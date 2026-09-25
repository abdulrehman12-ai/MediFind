<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\InvitationDeliveryStatus;
use App\Enums\UserRole;
use App\Mail\AccountInvitationMail;
use App\Models\AccountInvitation;
use App\Models\Pharmacy;
use App\Models\User;
use App\Pharmacy\PharmacyRuleException;
use App\Pharmacy\PharmacyWorkflow;
use App\Staff\InvitationOutbox;
use App\Staff\StaffInvitation;
use Database\Seeders\CitySeeder;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class InvitationDeliveryConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private ?Connection $session = null;

    private bool $failMail = false;

    private int $mailAttempts = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CitySeeder::class);
        Event::listen(MessageSending::class, function (): void {
            if ($this->failMail) {
                throw new RuntimeException('smtp down');
            }

            $this->mailAttempts++;
        });
    }

    public function test_claim_and_reissue_wait_on_both_lock_orders_without_changing_the_invitation(): void
    {
        $admin = $this->user('lock-admin@example.test');
        $pharmacy = $this->pharmacy($admin, 'Lock Order Pharmacy');

        $this->outside(function () use ($admin, $pharmacy): void {
            $this->failMail = true;
            $invitation = $this->pendingInvitation($admin, $pharmacy, 'lock-order@example.test');
            $this->failMail = false;
            Mail::fake();
            $delivery = DB::table('invitation_deliveries')->where('account_invitation_id', $invitation->id)->first();
            $hash = $invitation->token_hash;
            $audits = DB::table('audit_events')->where('subject_id', $invitation->id)->count();

            $this->whileLocked(
                'select id from account_invitations where id = ? for update',
                [$invitation->id],
                function () use ($admin, $invitation, $delivery): void {
                    $this->assertLockTimeout(fn () => app(InvitationOutbox::class)->deliver((string) $delivery->id));
                    $this->assertLockTimeout(fn () => app(StaffInvitation::class)->reissue($admin, $invitation));
                },
            );

            $this->whileLocked(
                'select id from invitation_deliveries where id = ? for update',
                [$delivery->id],
                function () use ($admin, $invitation, $delivery): void {
                    $this->assertLockTimeout(fn () => app(InvitationOutbox::class)->deliver((string) $delivery->id));
                    $this->assertLockTimeout(fn () => app(StaffInvitation::class)->reissue($admin, $invitation));
                },
            );

            $invitation->refresh();
            $after = DB::table('invitation_deliveries')->where('id', $delivery->id)->first();
            $this->assertSame($hash, $invitation->token_hash);
            $this->assertSame(InvitationDeliveryStatus::Pending, $invitation->delivery_status);
            $this->assertNull($invitation->accepted_at);
            $this->assertNull($invitation->revoked_at);
            $this->assertSame($delivery->token_ciphertext, $after->token_ciphertext);
            $this->assertSame($delivery->token_hash, $after->token_hash);
            $this->assertNull($after->claim_id);
            $this->assertSame($audits, DB::table('audit_events')->where('subject_id', $invitation->id)->count());
            $this->assertSame(0, DB::table('audit_events')->where('action', 'invitation_reissue_requested')->where('subject_id', $invitation->id)->count());
            Mail::assertNothingSent();
        }, $pharmacy->id, (int) $admin->id, ['lock-admin@example.test', 'lock-order@example.test']);
    }

    public function test_reissue_while_a_sender_holds_a_claim_does_not_replace_the_row(): void
    {
        $admin = $this->user('inflight-admin@example.test');
        $pharmacy = $this->pharmacy($admin, 'In Flight Pharmacy');

        $this->outside(function () use ($admin, $pharmacy): void {
            $this->failMail = true;
            $invitation = $this->pendingInvitation($admin, $pharmacy, 'inflight@example.test');
            $this->failMail = false;
            Mail::fake();
            $deliveryId = (string) DB::table('invitation_deliveries')->where('account_invitation_id', $invitation->id)->value('id');
            $before = DB::table('invitation_deliveries')->where('id', $deliveryId)->first();
            $claimed = app(InvitationOutbox::class)->claim($deliveryId);
            $this->assertIsArray($claimed);
            $this->assertFalse($claimed['skipped']);

            $other = $this->secondConnection();
            $visible = $other->selectOne('select claim_id, token_ciphertext, token_hash from invitation_deliveries where id = ?', [$deliveryId]);
            $this->assertSame($claimed['claim_id'], $visible->claim_id);
            $this->assertSame($before->token_ciphertext, $visible->token_ciphertext);

            try {
                app(StaffInvitation::class)->reissue($admin, $invitation->refresh());
                $this->fail('Reissue replaced an in-flight delivery.');
            } catch (PharmacyRuleException $exception) {
                $this->assertSame('An invitation email is already being sent. Try again in a moment.', $exception->getMessage());
            }

            $after = DB::table('invitation_deliveries')->where('id', $deliveryId)->first();
            $invitation->refresh();
            $this->assertSame($before->token_ciphertext, $after->token_ciphertext);
            $this->assertSame($before->token_hash, $after->token_hash);
            $this->assertSame($claimed['claim_id'], $after->claim_id);
            $this->assertSame($before->token_hash, $invitation->token_hash);
            $this->assertSame(InvitationDeliveryStatus::Pending, $invitation->delivery_status);
            $this->assertSame(0, DB::table('audit_events')->where('action', 'invitation_reissue_requested')->where('subject_id', $invitation->id)->count());
            Mail::assertNothingSent();

            app(InvitationOutbox::class)->sendClaimed($claimed);
            $invitation->refresh();
            $this->assertSame(InvitationDeliveryStatus::Delivered, $invitation->delivery_status);
            $this->assertNull(DB::table('invitation_deliveries')->where('id', $deliveryId)->first());
            Mail::assertSent(AccountInvitationMail::class, 1);
            $this->assertStringNotContainsString($claimed['token'], (string) DB::table('audit_events')->where('subject_id', $invitation->id)->value('metadata'));
            $this->assertNotNull(app(StaffInvitation::class)->acceptNewAccount($claimed['token'], 'correct-horse-battery')->id);
        }, $pharmacy->id, (int) $admin->id, ['inflight-admin@example.test', 'inflight@example.test']);
    }

    public function test_a_stale_sender_cannot_activate_a_newer_claim(): void
    {
        $admin = $this->user('stale-admin@example.test');
        $pharmacy = $this->pharmacy($admin, 'Stale Claim Pharmacy');

        $this->outside(function () use ($admin, $pharmacy): void {
            $this->failMail = true;
            $invitation = $this->pendingInvitation($admin, $pharmacy, 'stale-claim@example.test');
            $this->failMail = false;
            Mail::fake();
            $deliveryId = (string) DB::table('invitation_deliveries')->where('account_invitation_id', $invitation->id)->value('id');
            $originalHash = $invitation->token_hash;
            $first = app(InvitationOutbox::class)->claim($deliveryId);
            $this->assertIsArray($first);
            $this->assertFalse($first['skipped']);
            DB::table('invitation_deliveries')->where('id', $deliveryId)->update(['claimed_at' => now()->subMinute()]);

            $second = app(InvitationOutbox::class)->claim($deliveryId);
            $this->assertIsArray($second);
            $this->assertFalse($second['skipped']);
            $this->assertNotSame($first['claim_id'], $second['claim_id']);

            app(InvitationOutbox::class)->sendClaimed($first);
            $invitation->refresh();
            $row = DB::table('invitation_deliveries')->where('id', $deliveryId)->first();
            $this->assertSame(InvitationDeliveryStatus::Pending, $invitation->delivery_status);
            $this->assertSame($originalHash, $invitation->token_hash);
            $this->assertSame($second['claim_id'], $row->claim_id);
            $this->assertNotNull($row->token_ciphertext);
            $this->assertStringNotContainsString($first['token'], (string) $row->token_ciphertext);
            Mail::assertSent(AccountInvitationMail::class, 1);

            app(InvitationOutbox::class)->sendClaimed($second);
            $this->assertSame(InvitationDeliveryStatus::Delivered, $invitation->refresh()->delivery_status);
            $this->assertSame($originalHash, $invitation->token_hash);
            $this->assertNull(DB::table('invitation_deliveries')->where('id', $deliveryId)->first());
            Mail::assertSent(AccountInvitationMail::class, 2);
            $this->assertNotNull(app(StaffInvitation::class)->acceptNewAccount($second['token'], 'correct-horse-battery')->id);
        }, $pharmacy->id, (int) $admin->id, ['stale-admin@example.test', 'stale-claim@example.test']);
    }

    public function test_a_stale_sender_cannot_activate_a_replacement(): void
    {
        $admin = $this->user('replace-admin@example.test');
        $pharmacy = $this->pharmacy($admin, 'Stale Replacement Pharmacy');

        $this->outside(function () use ($admin, $pharmacy): void {
            $this->failMail = true;
            $invitation = $this->pendingInvitation($admin, $pharmacy, 'stale-replace@example.test');
            $deliveryId = (string) DB::table('invitation_deliveries')->where('account_invitation_id', $invitation->id)->value('id');
            $first = app(InvitationOutbox::class)->claim($deliveryId);
            $this->assertIsArray($first);
            DB::table('invitation_deliveries')->where('id', $deliveryId)->update(['claimed_at' => now()->subMinute()]);

            try {
                app(StaffInvitation::class)->reissue($admin, $invitation->refresh());
                $this->fail('A pending reissue reported a usable previous link.');
            } catch (PharmacyRuleException $exception) {
                $this->assertSame('The invitation email could not be sent. It cannot be accepted until delivery succeeds. Try again.', $exception->getMessage());
            }

            $this->failMail = false;
            Mail::fake();
            app(InvitationOutbox::class)->sendClaimed($first);
            $invitation->refresh();
            $row = DB::table('invitation_deliveries')->where('account_invitation_id', $invitation->id)->first();
            $replacement = Crypt::decryptString((string) $row->token_ciphertext);
            $this->assertSame(InvitationDeliveryStatus::Pending, $invitation->delivery_status);
            $this->assertNotSame($first['claim_id'], $row->claim_id);
            $this->assertSame(hash_hmac('sha256', $replacement, (string) config('app.key')), $invitation->token_hash);
            $this->assertStringNotContainsString($replacement, (string) $row->token_ciphertext);
            $this->assertStringNotContainsString($first['token'], (string) $row->token_ciphertext);
            Mail::assertSent(AccountInvitationMail::class, 1);

            try {
                app(StaffInvitation::class)->acceptNewAccount($first['token'], 'correct-horse-battery');
                $this->fail('The token from the stale sender was accepted.');
            } catch (PharmacyRuleException) {
            }

            try {
                app(StaffInvitation::class)->acceptNewAccount($replacement, 'correct-horse-battery');
                $this->fail('The unsent replacement was accepted.');
            } catch (PharmacyRuleException) {
            }

            $this->assertNull($invitation->refresh()->accepted_at);

            foreach (DB::table('audit_events')->where('subject_id', $invitation->id)->pluck('metadata') as $metadata) {
                $this->assertStringNotContainsString($replacement, (string) $metadata);
                $this->assertStringNotContainsString($first['token'], (string) $metadata);
            }
        }, $pharmacy->id, (int) $admin->id, ['replace-admin@example.test', 'stale-replace@example.test']);
    }

    public function test_failed_reissue_of_a_pending_invitation_does_not_leave_a_usable_link(): void
    {
        $admin = $this->user('pending-admin@example.test');
        $pharmacy = $this->pharmacy($admin, 'Pending Reissue Pharmacy');

        $this->outside(function () use ($admin, $pharmacy): void {
            $this->failMail = true;
            $invitation = $this->pendingInvitation($admin, $pharmacy, 'pending-reissue@example.test');
            $previousHash = $invitation->token_hash;

            try {
                app(StaffInvitation::class)->reissue($admin, $invitation);
                $this->fail('A pending reissue reported a usable previous link.');
            } catch (PharmacyRuleException $exception) {
                $this->assertSame('The invitation email could not be sent. It cannot be accepted until delivery succeeds. Try again.', $exception->getMessage());
                $this->assertStringNotContainsString('still works', $exception->getMessage());
            }

            $invitation->refresh();
            $row = DB::table('invitation_deliveries')->where('account_invitation_id', $invitation->id)->first();
            $replacement = Crypt::decryptString((string) $row->token_ciphertext);
            $metadata = (string) DB::table('audit_events')->where('action', 'invitation_reissue_requested')->where('subject_id', $invitation->id)->value('metadata');
            $failure = (string) DB::table('audit_events')->where('action', 'invitation_delivery_failed')->where('subject_id', $invitation->id)->orderByDesc('created_at')->value('metadata');
            $this->assertSame(InvitationDeliveryStatus::Pending, $invitation->delivery_status);
            $this->assertNotSame($previousHash, $invitation->token_hash);
            $this->assertSame(hash_hmac('sha256', $replacement, (string) config('app.key')), $invitation->token_hash);
            $this->assertNull($row->claim_id);
            $this->assertStringNotContainsString($replacement, (string) $row->token_ciphertext);
            $this->assertStringNotContainsString($replacement, $metadata);
            $this->assertStringNotContainsString($replacement, $failure);
            $this->assertStringNotContainsString('pending-reissue@example.test', $failure);
            $this->assertFalse(json_decode($metadata, true)['replaces_current_link']);

            try {
                app(StaffInvitation::class)->acceptNewAccount($replacement, 'correct-horse-battery');
                $this->fail('A pending invitation was accepted.');
            } catch (PharmacyRuleException) {
            }

            $this->assertSame(InvitationDeliveryStatus::Pending, $invitation->refresh()->delivery_status);
            $this->assertNull($invitation->accepted_at);
        }, $pharmacy->id, (int) $admin->id, ['pending-admin@example.test', 'pending-reissue@example.test']);
    }

    public function test_failed_replacement_of_a_delivered_invitation_keeps_the_current_link_until_expiry(): void
    {
        $admin = $this->user('keep-admin@example.test');
        $pharmacy = $this->pharmacy($admin, 'Keep Link Pharmacy');

        $this->outside(function () use ($admin, $pharmacy): void {
            $this->failMail = true;
            $invitation = $this->pendingInvitation($admin, $pharmacy, 'keep-link@example.test');
            $this->failMail = false;
            $token = $this->deliverPending($invitation);
            $invitation->refresh();
            $hash = $invitation->token_hash;
            $expiresAt = $invitation->expires_at->toIso8601String();
            $this->failMail = true;

            try {
                app(StaffInvitation::class)->reissue($admin, $invitation);
                $this->fail('A failed replacement retired the delivered link.');
            } catch (PharmacyRuleException $exception) {
                $this->assertSame('The replacement email could not be sent. The previous invitation link still works.', $exception->getMessage());
            }

            $invitation->refresh();
            $metadata = json_decode((string) DB::table('audit_events')->where('action', 'invitation_reissue_requested')->where('subject_id', $invitation->id)->value('metadata'), true);
            $failure = (string) DB::table('audit_events')->where('action', 'invitation_delivery_failed')->where('subject_id', $invitation->id)->get()->first(function (object $row): bool {
                return json_decode((string) $row->metadata, true)['outcome'] === 'reissue';
            })->metadata;
            $this->assertSame($hash, $invitation->token_hash);
            $this->assertSame($expiresAt, $invitation->expires_at->toIso8601String());
            $this->assertSame(InvitationDeliveryStatus::Delivered, $invitation->delivery_status);
            $this->assertTrue($metadata['replaces_current_link']);
            $this->assertSame('reissue', json_decode($failure, true)['outcome']);
            $this->assertStringNotContainsString($token, $failure);
            $this->assertNotNull(DB::table('invitation_deliveries')->where('account_invitation_id', $invitation->id)->whereNull('claim_id')->first());
            $this->assertSame('new', app(StaffInvitation::class)->preview($token));

            $this->travel(25)->hours();
            $invitation->refresh();
            $this->assertSame($hash, $invitation->token_hash);
            $this->assertSame(InvitationDeliveryStatus::Delivered, $invitation->delivery_status);

            try {
                app(StaffInvitation::class)->acceptNewAccount($token, 'correct-horse-battery');
                $this->fail('An expired delivered link was accepted.');
            } catch (PharmacyRuleException) {
            }

            $this->assertNull($invitation->refresh()->accepted_at);
            $this->travelBack();
        }, $pharmacy->id, (int) $admin->id, ['keep-admin@example.test', 'keep-link@example.test']);
    }

    public function test_an_expired_claim_is_retried_once_after_a_worker_crash(): void
    {
        $admin = $this->user('crash-admin@example.test');
        $pharmacy = $this->pharmacy($admin, 'Crash Pharmacy');

        $this->outside(function () use ($admin, $pharmacy): void {
            $this->failMail = true;
            $invitation = $this->pendingInvitation($admin, $pharmacy, 'crash@example.test');
            $this->failMail = false;
            Mail::fake();
            $deliveryId = (string) DB::table('invitation_deliveries')->where('account_invitation_id', $invitation->id)->value('id');
            $crashedClaim = (string) Str::uuid();
            DB::table('invitation_deliveries')->where('id', $deliveryId)->update([
                'claim_id' => $crashedClaim,
                'claimed_at' => now()->subMinute(),
                'attempts' => 1,
            ]);

            $this->assertSame(0, app(InvitationOutbox::class)->deliverOutstanding());
            $invitation->refresh();
            $this->assertSame(InvitationDeliveryStatus::Delivered, $invitation->delivery_status);
            $this->assertNull(DB::table('invitation_deliveries')->where('account_invitation_id', $invitation->id)->first());
            Mail::assertSent(AccountInvitationMail::class, 1);

            $this->assertSame(0, app(InvitationOutbox::class)->deliverOutstanding());
            Mail::assertSent(AccountInvitationMail::class, 1);
            $created = (string) DB::table('audit_events')->where('action', 'invitation_created')->where('subject_id', $invitation->id)->value('metadata');
            $this->assertStringNotContainsString($crashedClaim, $created);
        }, $pharmacy->id, (int) $admin->id, ['crash-admin@example.test', 'crash@example.test']);
    }

    public function test_revoke_or_accept_during_delivery_does_not_make_a_token_usable(): void
    {
        $admin = $this->user('stop-admin@example.test');
        $pharmacy = $this->pharmacy($admin, 'Stop Delivery Pharmacy');

        $this->outside(function () use ($admin, $pharmacy): void {
            $this->failMail = true;
            $pending = $this->pendingInvitation($admin, $pharmacy, 'revoke-inflight@example.test');
            $this->failMail = false;
            $pendingId = (string) DB::table('invitation_deliveries')->where('account_invitation_id', $pending->id)->value('id');
            $pendingHash = $pending->token_hash;
            $claimed = app(InvitationOutbox::class)->claim($pendingId);
            $this->assertIsArray($claimed);
            $failuresBeforeRevoke = DB::table('audit_events')->where('action', 'invitation_delivery_failed')->where('subject_id', $pending->id)->count();
            $attemptsBeforeRevoke = $this->mailAttempts;
            app(StaffInvitation::class)->revoke($admin, $pending->refresh());
            app(InvitationOutbox::class)->sendClaimed($claimed);

            $pending->refresh();
            $this->assertNotNull($pending->revoked_at);
            $this->assertSame(InvitationDeliveryStatus::Pending, $pending->delivery_status);
            $this->assertSame($pendingHash, $pending->token_hash);
            $this->assertNull($pending->accepted_at);
            $this->assertNull(DB::table('invitation_deliveries')->where('account_invitation_id', $pending->id)->first());
            $this->assertSame($attemptsBeforeRevoke, $this->mailAttempts);
            $this->assertSame($failuresBeforeRevoke, DB::table('audit_events')->where('action', 'invitation_delivery_failed')->where('subject_id', $pending->id)->count());

            try {
                app(StaffInvitation::class)->acceptNewAccount($claimed['token'], 'correct-horse-battery');
                $this->fail('A revoked in-flight invitation was accepted.');
            } catch (PharmacyRuleException) {
            }

            $revokedAudit = (string) DB::table('audit_events')->where('action', 'invitation_revoked')->where('subject_id', $pending->id)->value('metadata');
            $this->assertStringNotContainsString($claimed['token'], $revokedAudit);

            $this->failMail = true;
            $delivered = $this->pendingInvitation($admin, $pharmacy, 'accept-inflight@example.test');
            $this->failMail = false;
            $currentToken = $this->deliverPending($delivered);
            $delivered->refresh();
            $currentHash = $delivered->token_hash;
            $replacement = 'replacement-token-value-32b';
            app(InvitationOutbox::class)->store(
                $delivered,
                $replacement,
                hash_hmac('sha256', $replacement, (string) config('app.key')),
                'reissue',
            );
            $replacementId = (string) DB::table('invitation_deliveries')->where('account_invitation_id', $delivered->id)->value('id');
            $replacementClaim = app(InvitationOutbox::class)->claim($replacementId);
            $this->assertIsArray($replacementClaim);
            $this->assertSame($currentHash, $delivered->refresh()->token_hash);
            Mail::fake();

            app(StaffInvitation::class)->acceptNewAccount($currentToken, 'correct-horse-battery');
            app(InvitationOutbox::class)->sendClaimed($replacementClaim);

            $delivered->refresh();
            $this->assertNotNull($delivered->accepted_at);
            $this->assertSame($currentHash, $delivered->token_hash);
            $this->assertSame(InvitationDeliveryStatus::Delivered, $delivered->delivery_status);
            $this->assertNull(DB::table('invitation_deliveries')->where('account_invitation_id', $delivered->id)->first());
            Mail::assertNothingSent();

            try {
                app(StaffInvitation::class)->acceptNewAccount($replacement, 'correct-horse-battery');
                $this->fail('An unsent replacement became usable after acceptance.');
            } catch (PharmacyRuleException) {
            }

            try {
                app(StaffInvitation::class)->acceptNewAccount($currentToken, 'correct-horse-battery');
                $this->fail('An accepted invitation was accepted again.');
            } catch (PharmacyRuleException) {
            }

            $acceptedAudit = (string) DB::table('audit_events')->where('action', 'invitation_accepted')->where('subject_id', $delivered->id)->value('metadata');
            $this->assertStringNotContainsString($currentToken, $acceptedAudit);
            $this->assertStringNotContainsString($replacement, $acceptedAudit);
        }, $pharmacy->id, (int) $admin->id, [
            'stop-admin@example.test',
            'revoke-inflight@example.test',
            'accept-inflight@example.test',
        ]);
    }

    private function pendingInvitation(User $admin, Pharmacy $pharmacy, string $email): AccountInvitation
    {
        try {
            app(StaffInvitation::class)->inviteManager($admin, $pharmacy, $email);
            $this->fail('The invitation was delivered during setup.');
        } catch (PharmacyRuleException $exception) {
            $this->assertSame('The invitation email could not be sent. It cannot be accepted until delivery succeeds. Try again.', $exception->getMessage());
        }

        $invitation = AccountInvitation::query()->where('email', $email)->firstOrFail();
        $this->assertSame(InvitationDeliveryStatus::Pending, $invitation->delivery_status);
        $this->assertNotNull(DB::table('invitation_deliveries')->where('account_invitation_id', $invitation->id)->whereNull('claim_id')->first());

        return $invitation;
    }

    private function deliverPending(AccountInvitation $invitation): string
    {
        $deliveryId = (string) DB::table('invitation_deliveries')->where('account_invitation_id', $invitation->id)->value('id');
        $claimed = app(InvitationOutbox::class)->claim($deliveryId);
        $this->assertIsArray($claimed);
        $this->assertFalse($claimed['skipped']);
        app(InvitationOutbox::class)->sendClaimed($claimed);

        return $claimed['token'];
    }

    private function assertLockTimeout(callable $callback): void
    {
        try {
            $callback();
            $this->fail('The invitation operation ignored a held lock.');
        } catch (QueryException $exception) {
            $this->assertSame('55P03', $exception->getCode());
        }
    }

    private function whileLocked(string $sql, array $bindings, callable $callback): void
    {
        $holder = $this->secondConnection();
        $holder->beginTransaction();

        try {
            $holder->select($sql, $bindings);
            DB::statement("set lock_timeout = '300ms'");
            $callback();
        } finally {
            if ($holder->transactionLevel() > 0) {
                $holder->rollBack();
            }
        }
    }

    /**
     * @param  list<string>  $emails
     */
    private function outside(callable $callback, string $pharmacyId, int $adminId, array $emails): void
    {
        DB::commit();

        try {
            $callback();
        } finally {
            if ($this->session instanceof Connection && $this->session->transactionLevel() > 0) {
                $this->session->rollBack();
            }

            $invitationIds = DB::table('account_invitations')->where('pharmacy_id', $pharmacyId)->pluck('id');
            $userIds = DB::table('users')->whereIn('email', $emails)->pluck('id');
            DB::table('invitation_deliveries')->whereIn('account_invitation_id', $invitationIds)->delete();
            DB::table('pharmacy_memberships')->where('pharmacy_id', $pharmacyId)->delete();
            DB::table('account_invitations')->whereIn('id', $invitationIds)->delete();
            DB::table('audit_events')->where('pharmacy_id', $pharmacyId)->orWhereIn('actor_id', $userIds)->orWhere('actor_id', $adminId)->delete();
            DB::table('pharmacies')->where('id', $pharmacyId)->delete();
            DB::table('users')->whereIn('id', $userIds)->delete();
            DB::disconnect('phase2_session');

            try {
                DB::statement('set lock_timeout = 0');
            } catch (Throwable) {
                DB::rollBack();
                DB::statement('set lock_timeout = 0');
            }

            if (DB::transactionLevel() === 0) {
                DB::beginTransaction();
            }
        }
    }

    private function secondConnection(): Connection
    {
        $name = (string) config('database.default');
        config(['database.connections.phase2_session' => config('database.connections.'.$name)]);
        DB::purge('phase2_session');
        $this->session = DB::connection('phase2_session');

        return $this->session;
    }

    private function pharmacy(User $admin, string $name): Pharmacy
    {
        return app(PharmacyWorkflow::class)->create($admin, [
            'name' => $name,
            'address_text' => 'Susan Road',
            'latitude' => '31.418000',
            'longitude' => '73.079000',
        ]);
    }

    private function user(string $email): User
    {
        return User::query()->forceCreate([
            'public_id' => (string) Str::uuid(),
            'email' => $email,
            'role' => UserRole::Admin,
            'status' => AccountStatus::Active,
        ]);
    }
}
