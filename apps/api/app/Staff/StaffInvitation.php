<?php

declare(strict_types=1);

namespace App\Staff;

use App\Audit\AuditRecorder;
use App\Enums\AccountStatus;
use App\Enums\CredentialStatus;
use App\Enums\InvitationDeliveryStatus;
use App\Enums\InvitationKind;
use App\Enums\MembershipStatus;
use App\Enums\PharmacyStatus;
use App\Enums\StaffRole;
use App\Enums\UserRole;
use App\Models\AccountInvitation;
use App\Models\InvitationDelivery;
use App\Models\Pharmacy;
use App\Models\PharmacyMembership;
use App\Models\User;
use App\Pharmacy\PharmacyAccess;
use App\Pharmacy\PharmacyRuleException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class StaffInvitation
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function inviteAdmin(string $email, ?User $actor = null): string
    {
        if ($actor instanceof User) {
            PharmacyAccess::admin($actor);
        }

        return $this->create(InvitationKind::Admin, strtolower(trim($email)), null, $actor);
    }

    public function inviteManager(User $admin, Pharmacy $pharmacy, string $email): string
    {
        PharmacyAccess::admin($admin);

        return $this->create(InvitationKind::Manager, strtolower(trim($email)), $pharmacy, $admin);
    }

    public function invitePharmacist(User $admin, Pharmacy $pharmacy, string $email): string
    {
        PharmacyAccess::admin($admin);

        return $this->create(InvitationKind::Pharmacist, strtolower(trim($email)), $pharmacy, $admin);
    }

    public function inviteInventoryStaff(User $actor, Pharmacy $pharmacy, string $email): string
    {
        if ($actor->role === UserRole::Admin) {
            PharmacyAccess::admin($actor);
        } else {
            PharmacyAccess::manager($actor, $pharmacy);
            if (! in_array($pharmacy->status, [PharmacyStatus::Pending, PharmacyStatus::Approved], true)) {
                throw new PharmacyRuleException('Staff invitations are available while the pharmacy is pending or approved.');
            }
        }

        return $this->create(InvitationKind::InventoryStaff, strtolower(trim($email)), $pharmacy, $actor);
    }

    public function reissue(User $actor, AccountInvitation $invitation): string
    {
        $this->authorize($actor, $invitation);

        $result = DB::transaction(function () use ($actor, $invitation): array {
            PharmacyAccess::authorizeInvitation($actor, $invitation->kind, $invitation->pharmacy);
            $this->advisoryLock($invitation->email, $invitation->kind, $invitation->pharmacy_id);
            $locked = AccountInvitation::query()->whereKey($invitation->id)->lockForUpdate()->first();

            if (! $locked instanceof AccountInvitation || ! $this->isOpen($locked) || $locked->expires_at->isPast()) {
                throw new PharmacyRuleException('This invitation can no longer be resent. Send a new one after it expires or is revoked.');
            }

            $token = $this->freshToken();
            $nextHash = $this->hash($token);
            $existing = InvitationDelivery::query()->where('account_invitation_id', $locked->id)->lockForUpdate()->first();

            if ($existing instanceof InvitationDelivery && app(InvitationOutbox::class)->claimIsActive($existing)) {
                throw new PharmacyRuleException('An invitation email is already being sent. Try again in a moment.');
            }

            $currentLinkWorks = $locked->delivery_status === InvitationDeliveryStatus::Delivered
                && $locked->expires_at->isFuture();

            if (! $currentLinkWorks) {
                $locked->forceFill([
                    'token_hash' => $nextHash,
                    'expires_at' => now()->addHours(24),
                ])->save();
            }

            $delivery = app(InvitationOutbox::class)->store(
                $locked,
                $token,
                $nextHash,
                $currentLinkWorks ? 'reissue' : 'initial',
            );
            $this->audit->record($actor, 'invitation_reissue_requested', 'account_invitation', (string) $locked->id, $locked->pharmacy_id, [
                'kind' => $locked->kind->value,
                'replaces_current_link' => $currentLinkWorks,
            ]);

            return [
                'token' => $token,
                'delivery_id' => (string) $delivery->id,
                'failure' => $currentLinkWorks
                    ? 'The replacement email could not be sent. The previous invitation link still works.'
                    : 'The invitation email could not be sent. It cannot be accepted until delivery succeeds. Try again.',
            ];
        });

        $this->deliverAfterCommit($result['delivery_id'], $result['failure']);

        return $result['token'];
    }

    public function revoke(User $actor, AccountInvitation $invitation): void
    {
        $this->authorize($actor, $invitation);

        DB::transaction(function () use ($actor, $invitation): void {
            $locked = AccountInvitation::query()->whereKey($invitation->id)->lockForUpdate()->first();

            if (! $locked instanceof AccountInvitation || ! $this->isOpen($locked)) {
                throw new PharmacyRuleException('This invitation can no longer be revoked.');
            }

            $locked->forceFill(['revoked_at' => now()])->save();
            $this->deleteOutbox($locked);
            $this->audit->record($actor, 'invitation_revoked', 'account_invitation', (string) $locked->id, $locked->pharmacy_id, [
                'kind' => $locked->kind->value,
            ]);
        });
    }

    public function preview(string $token): string
    {
        $invitation = AccountInvitation::query()->where('token_hash', $this->hash($token))->first();

        if (! $invitation instanceof AccountInvitation || ! $this->isUsable($invitation)) {
            return 'invalid';
        }

        $existing = User::query()->whereRaw('lower(email) = ?', [strtolower($invitation->email)])->first();

        if ($existing instanceof User && $existing->role !== UserRole::Customer) {
            return 'existing';
        }

        return 'new';
    }

    public function acceptNewAccount(string $token, string $password): User
    {
        if (strlen($password) < 12) {
            throw new PharmacyRuleException('Use a password of at least 12 characters.');
        }

        return DB::transaction(function () use ($token, $password): User {
            $invitation = $this->lockUsable($token);
            $email = strtolower($invitation->email);
            $existing = User::query()->whereRaw('lower(email) = ?', [$email])->lockForUpdate()->first();

            if ($existing instanceof User && $existing->role === UserRole::Customer) {
                throw new PharmacyRuleException('Use a different email. Customer accounts stay separate from staff accounts.');
            }

            if ($existing instanceof User) {
                throw new PharmacyRuleException('This email already has an account. Sign in and accept the invitation without setting a new password.');
            }

            $role = $invitation->kind === InvitationKind::Admin ? UserRole::Admin : UserRole::PharmacyStaff;
            $user = $this->provisionNew($email, $password, $role);
            $this->attachMembership($invitation, $user);
            $invitation->forceFill(['accepted_at' => now()])->save();
            $this->deleteOutbox($invitation);
            $this->audit->record($user, 'invitation_accepted', 'account_invitation', (string) $invitation->id, $invitation->pharmacy_id, [
                'kind' => $invitation->kind->value,
                'account' => 'created',
            ]);

            return $user;
        });
    }

    public function acceptAuthenticated(User $actor, string $token): User
    {
        return DB::transaction(function () use ($actor, $token): User {
            $invitation = $this->lockUsable($token);
            $actor = User::query()->whereKey($actor->id)->lockForUpdate()->first();

            if (! $actor instanceof User || strtolower((string) $actor->email) !== strtolower($invitation->email)) {
                throw new PharmacyRuleException('Sign in with the invited account to accept this invitation.');
            }

            if ($actor->role === UserRole::Customer || ! $actor->isOperational()) {
                throw new PharmacyRuleException('This account cannot accept that invitation.');
            }

            $expected = $invitation->kind === InvitationKind::Admin ? UserRole::Admin : UserRole::PharmacyStaff;

            if ($actor->role !== $expected) {
                throw new PharmacyRuleException('This account cannot accept that invitation.');
            }

            $this->attachMembership($invitation, $actor);
            $invitation->forceFill(['accepted_at' => now()])->save();
            $this->deleteOutbox($invitation);
            $this->audit->record($actor, 'invitation_accepted', 'account_invitation', (string) $invitation->id, $invitation->pharmacy_id, [
                'kind' => $invitation->kind->value,
                'account' => 'existing',
            ]);

            return $actor;
        });
    }

    private function create(InvitationKind $kind, string $email, ?Pharmacy $pharmacy, ?User $actor): string
    {
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new PharmacyRuleException('Enter a valid email address.');
        }

        $this->closeExpired($email, $kind, $pharmacy?->id, $actor);
        $token = $this->freshToken();

        $deliveryId = DB::transaction(function () use ($kind, $email, $pharmacy, $actor, $token): string {
            if ($actor instanceof User) {
                PharmacyAccess::authorizeInvitation($actor, $kind, $pharmacy);
            }

            $this->advisoryLock($email, $kind, $pharmacy?->id);
            $this->supersedeExpired($email, $kind, $pharmacy?->id, $actor);

            $open = $this->openQuery($email, $kind, $pharmacy?->id)->lockForUpdate()->get();

            if ($open->contains(fn (AccountInvitation $invitation): bool => $invitation->expires_at->isFuture())) {
                throw new PharmacyRuleException('An invitation is already open. Revoke it or wait until it expires before sending another.');
            }

            $invitation = new AccountInvitation;
            $invitation->forceFill([
                'email' => $email,
                'kind' => $kind,
                'pharmacy_id' => $pharmacy?->id,
                'token_hash' => $this->hash($token),
                'delivery_status' => InvitationDeliveryStatus::Pending,
                'expires_at' => now()->addHours(24),
                'invited_by' => $actor?->id,
            ]);
            $invitation->save();
            $delivery = app(InvitationOutbox::class)->store($invitation, $token, $this->hash($token), 'initial');
            $this->audit->record($actor, 'invitation_created', 'account_invitation', (string) $invitation->id, $pharmacy?->id, [
                'kind' => $kind->value,
            ]);

            return (string) $delivery->id;
        });

        $this->deliverAfterCommit(
            $deliveryId,
            'The invitation email could not be sent. It cannot be accepted until delivery succeeds. Try again.',
        );

        return $token;
    }

    private function deliverAfterCommit(string $deliveryId, string $failureMessage): void
    {
        if (DB::transactionLevel() > 0) {
            DB::afterCommit(function () use ($deliveryId, $failureMessage): void {
                if (DB::transactionLevel() > 0) {
                    return;
                }

                $this->sendCommittedDelivery($deliveryId, $failureMessage);
            });

            return;
        }

        $this->sendCommittedDelivery($deliveryId, $failureMessage);
    }

    private function sendCommittedDelivery(string $deliveryId, string $failureMessage): void
    {
        try {
            app(InvitationOutbox::class)->deliver($deliveryId);
        } catch (InvitationDeliveryException) {
            throw new PharmacyRuleException($failureMessage);
        }
    }

    private function closeExpired(string $email, InvitationKind $kind, ?string $pharmacyId, ?User $actor): void
    {
        DB::transaction(function () use ($email, $kind, $pharmacyId, $actor): void {
            $this->advisoryLock($email, $kind, $pharmacyId);
            $this->supersedeExpired($email, $kind, $pharmacyId, $actor);
        });
    }

    private function supersedeExpired(string $email, InvitationKind $kind, ?string $pharmacyId, ?User $actor): void
    {
        $rows = $this->openQuery($email, $kind, $pharmacyId)->lockForUpdate()->get();

        foreach ($rows as $invitation) {
            if ($invitation->expires_at->isFuture()) {
                continue;
            }

            $invitation->forceFill(['superseded_at' => now()])->save();
            $this->deleteOutbox($invitation);
            $this->audit->record($actor, 'invitation_expired_closed', 'account_invitation', (string) $invitation->id, $pharmacyId, [
                'kind' => $kind->value,
            ]);
        }
    }

    private function deleteOutbox(AccountInvitation $invitation): void
    {
        $rows = InvitationDelivery::query()->where('account_invitation_id', $invitation->id)->lockForUpdate()->get();

        foreach ($rows as $row) {
            $row->delete();
        }
    }

    private function authorize(User $actor, AccountInvitation $invitation): void
    {
        if ($invitation->kind === InvitationKind::InventoryStaff && $actor->role !== UserRole::Admin) {
            $pharmacy = $invitation->pharmacy;

            if (! $pharmacy instanceof Pharmacy) {
                throw new PharmacyRuleException('This invitation can no longer be changed.');
            }

            PharmacyAccess::manager($actor, $pharmacy);

            return;
        }

        PharmacyAccess::admin($actor);
    }

    private function attachMembership(AccountInvitation $invitation, User $user): void
    {
        if ($invitation->kind === InvitationKind::Admin) {
            return;
        }

        $role = match ($invitation->kind) {
            InvitationKind::Manager => StaffRole::Manager,
            InvitationKind::Pharmacist => StaffRole::Pharmacist,
            InvitationKind::InventoryStaff => StaffRole::InventoryStaff,
        };

        $membership = PharmacyMembership::query()
            ->where('pharmacy_id', $invitation->pharmacy_id)
            ->where('user_id', $user->id)
            ->lockForUpdate()
            ->first();

        if ($membership instanceof PharmacyMembership) {
            if ($membership->staff_role !== $role) {
                throw new PharmacyRuleException('This account already has a different role at that pharmacy.');
            }

            return;
        }

        $membership = new PharmacyMembership;
        $membership->forceFill([
            'pharmacy_id' => $invitation->pharmacy_id,
            'user_id' => $user->id,
            'staff_role' => $role,
            'status' => MembershipStatus::Active,
            'credential_status' => CredentialStatus::Unverified,
        ])->save();
    }

    private function lockUsable(string $token): AccountInvitation
    {
        $invitation = AccountInvitation::query()
            ->where('token_hash', $this->hash($token))
            ->lockForUpdate()
            ->first();

        if (! $invitation instanceof AccountInvitation || ! $this->isUsable($invitation)) {
            throw new PharmacyRuleException('This invitation is no longer valid.');
        }

        return $invitation;
    }

    private function isOpen(AccountInvitation $invitation): bool
    {
        return $invitation->accepted_at === null
            && $invitation->revoked_at === null
            && $invitation->superseded_at === null;
    }

    private function isUsable(AccountInvitation $invitation): bool
    {
        return $this->isOpen($invitation)
            && $invitation->delivery_status === InvitationDeliveryStatus::Delivered
            && $invitation->expires_at->isFuture();
    }

    /**
     * @return Builder<AccountInvitation>
     */
    private function openQuery(string $email, InvitationKind $kind, ?string $pharmacyId): Builder
    {
        return AccountInvitation::query()
            ->whereRaw('lower(email) = ?', [$email])
            ->where('kind', $kind->value)
            ->when(
                $pharmacyId === null,
                fn (Builder $query) => $query->whereNull('pharmacy_id'),
                fn (Builder $query) => $query->where('pharmacy_id', $pharmacyId),
            )
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->whereNull('superseded_at');
    }

    private function advisoryLock(string $email, InvitationKind $kind, ?string $pharmacyId): void
    {
        $key = strtolower($email).'|'.$kind->value.'|'.($pharmacyId ?? '');
        DB::selectOne("select pg_advisory_xact_lock(('x' || substr(md5(?), 1, 16))::bit(64)::bigint)", [$key]);
    }

    private function provisionNew(string $email, string $password, UserRole $role): User
    {
        $user = new User;
        $user->forceFill([
            'public_id' => (string) Str::uuid(),
            'email' => $email,
            'password' => Hash::make($password),
            'role' => $role,
            'status' => AccountStatus::Active,
            'phone_e164' => null,
            'session_generation' => 0,
        ]);
        $user->save();

        return $user;
    }

    private function freshToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function hash(string $token): string
    {
        return hash_hmac('sha256', $token, (string) config('app.key'));
    }
}
