<?php

declare(strict_types=1);

namespace App\Staff;

use App\Audit\AuditRecorder;
use App\Enums\InvitationDeliveryStatus;
use App\Mail\AccountInvitationMail;
use App\Models\AccountInvitation;
use App\Models\InvitationDelivery;
use App\Models\User;
use App\Pharmacy\PharmacyRuleException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Lock account_invitations, then invitation_deliveries. Release both before calling the mailer.
 * A sender may record success or release a claim only when the outbox row still has the claim id it stored.
 */
final class InvitationOutbox
{
    public const CLAIM_LEASE_SECONDS = 30;

    public function __construct(private readonly AuditRecorder $audit) {}

    public function store(AccountInvitation $invitation, string $token, string $tokenHash, string $purpose): InvitationDelivery
    {
        $delivery = InvitationDelivery::query()->where('account_invitation_id', $invitation->id)->lockForUpdate()->first();

        if ($delivery instanceof InvitationDelivery && $this->claimIsActive($delivery)) {
            throw new PharmacyRuleException('An invitation email is already being sent. Try again in a moment.');
        }

        $attempts = $delivery instanceof InvitationDelivery ? $delivery->attempts : 0;

        if (! $delivery instanceof InvitationDelivery) {
            $delivery = new InvitationDelivery;
        }

        $delivery->forceFill([
            'account_invitation_id' => $invitation->id,
            'purpose' => $purpose,
            'token_ciphertext' => Crypt::encryptString($token),
            'token_hash' => $tokenHash,
            'attempts' => $attempts,
            'claim_id' => null,
            'claimed_at' => null,
        ])->save();

        return $delivery;
    }

    public function claimIsActive(InvitationDelivery $delivery): bool
    {
        return $delivery->claim_id !== null
            && $delivery->claimed_at !== null
            && $delivery->claimed_at->greaterThan(now()->subSeconds(self::CLAIM_LEASE_SECONDS));
    }

    public function deliverOutstanding(): int
    {
        $failures = 0;

        foreach (InvitationDelivery::query()->orderBy('created_at')->pluck('id') as $id) {
            try {
                $this->deliver((string) $id);
            } catch (InvitationDeliveryException) {
                $failures++;
            }
        }

        return $failures;
    }

    public function deliver(string $deliveryId): void
    {
        $claimed = $this->claim($deliveryId);

        if ($claimed === null || $claimed['skipped'] === true) {
            return;
        }

        $this->sendClaimed($claimed);
    }

    /**
     * @return array{skipped: true}|array{
     *     skipped: false,
     *     claim_id: string,
     *     delivery_id: string,
     *     invitation_id: string,
     *     email: string,
     *     token: string,
     *     purpose: string,
     *     pharmacy_id: string|null,
     *     kind: string,
     *     actor_id: int|null
     * }|null
     */
    public function claim(string $deliveryId): ?array
    {
        return DB::transaction(function () use ($deliveryId): ?array {
            $peek = InvitationDelivery::query()->whereKey($deliveryId)->first();

            if (! $peek instanceof InvitationDelivery) {
                return null;
            }

            $invitation = AccountInvitation::query()->whereKey($peek->account_invitation_id)->lockForUpdate()->first();

            if (! $invitation instanceof AccountInvitation) {
                return null;
            }

            $delivery = InvitationDelivery::query()->whereKey($deliveryId)->lockForUpdate()->first();

            if (! $delivery instanceof InvitationDelivery || $delivery->account_invitation_id !== $invitation->id) {
                return null;
            }

            if ($this->claimIsActive($delivery)) {
                return ['skipped' => true];
            }

            if (! $this->canSend($invitation)) {
                $delivery->delete();

                return null;
            }

            $claimId = (string) Str::uuid();
            $token = Crypt::decryptString($delivery->token_ciphertext);
            $delivery->forceFill([
                'claim_id' => $claimId,
                'claimed_at' => now(),
                'attempts' => $delivery->attempts + 1,
            ])->save();

            return [
                'skipped' => false,
                'claim_id' => $claimId,
                'delivery_id' => (string) $delivery->id,
                'invitation_id' => (string) $invitation->id,
                'email' => $invitation->email,
                'token' => $token,
                'purpose' => $delivery->purpose,
                'pharmacy_id' => $invitation->pharmacy_id,
                'kind' => $invitation->kind->value,
                'actor_id' => $invitation->invited_by === null ? null : (int) $invitation->invited_by,
            ];
        });
    }

    /**
     * @param  array{
     *     skipped: false,
     *     claim_id: string,
     *     delivery_id: string,
     *     invitation_id: string,
     *     email: string,
     *     token: string,
     *     purpose: string,
     *     pharmacy_id: string|null,
     *     kind: string,
     *     actor_id: int|null
     * }  $claimed
     */
    public function sendClaimed(array $claimed): void
    {
        $invitation = AccountInvitation::query()->find($claimed['invitation_id']);

        if (! $invitation instanceof AccountInvitation || ! $this->canSend($invitation)) {
            $this->activateClaim($claimed);

            return;
        }

        try {
            Mail::to($claimed['email'])->send(new AccountInvitationMail($claimed['token']));
        } catch (Throwable $exception) {
            Log::warning('Invitation email delivery failed.', [
                'exception' => $exception::class,
            ]);
            $this->failClaim($claimed);

            throw new InvitationDeliveryException('Invitation email delivery failed.');
        }

        $this->activateClaim($claimed);
    }

    /**
     * @param  array{claim_id: string, delivery_id: string, invitation_id: string, purpose: string, pharmacy_id: string|null, kind: string, actor_id: int|null}  $claimed
     */
    public function activateClaim(array $claimed): void
    {
        DB::transaction(function () use ($claimed): void {
            $invitation = AccountInvitation::query()->whereKey($claimed['invitation_id'])->lockForUpdate()->first();
            $delivery = $this->lockedClaim($claimed['delivery_id'], $claimed['claim_id']);

            if (! $delivery instanceof InvitationDelivery || ! $invitation instanceof AccountInvitation) {
                return;
            }

            if (! $this->canSend($invitation)) {
                $delivery->delete();

                return;
            }

            $changes = ['delivery_status' => InvitationDeliveryStatus::Delivered];

            if ($delivery->purpose === 'reissue') {
                $changes['token_hash'] = $delivery->token_hash;
                $changes['expires_at'] = now()->addHours(24);
            }

            $invitation->forceFill($changes)->save();
            $delivery->delete();
        });
    }

    /**
     * @param  array{claim_id: string, delivery_id: string, invitation_id: string, purpose: string, pharmacy_id: string|null, kind: string, actor_id: int|null}  $claimed
     */
    private function failClaim(array $claimed): void
    {
        DB::transaction(function () use ($claimed): void {
            $invitation = AccountInvitation::query()->whereKey($claimed['invitation_id'])->lockForUpdate()->first();
            $delivery = $this->lockedClaim($claimed['delivery_id'], $claimed['claim_id']);

            if ($delivery instanceof InvitationDelivery) {
                $delivery->forceFill([
                    'claim_id' => null,
                    'claimed_at' => null,
                ])->save();
            }

            $actor = $claimed['actor_id'] === null ? null : User::query()->find($claimed['actor_id']);
            $pharmacyId = $invitation instanceof AccountInvitation ? $invitation->pharmacy_id : $claimed['pharmacy_id'];
            $this->audit->record($actor instanceof User ? $actor : null, 'invitation_delivery_failed', 'account_invitation', $claimed['invitation_id'], $pharmacyId, [
                'kind' => $claimed['kind'],
                'outcome' => $claimed['purpose'],
            ]);
        });
    }

    private function lockedClaim(string $deliveryId, string $claimId): ?InvitationDelivery
    {
        $delivery = InvitationDelivery::query()
            ->whereKey($deliveryId)
            ->where('claim_id', $claimId)
            ->lockForUpdate()
            ->first();

        return $delivery instanceof InvitationDelivery ? $delivery : null;
    }

    private function canSend(AccountInvitation $invitation): bool
    {
        return $invitation->accepted_at === null
            && $invitation->revoked_at === null
            && $invitation->superseded_at === null
            && $invitation->expires_at->isFuture();
    }
}
