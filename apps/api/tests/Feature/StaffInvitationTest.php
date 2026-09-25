<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\CredentialStatus;
use App\Enums\StaffRole;
use App\Enums\UserRole;
use App\Mail\AccountInvitationMail;
use App\Models\AccountInvitation;
use App\Models\PharmacyMembership;
use App\Models\User;
use App\Pharmacy\PharmacyRuleException;
use App\Pharmacy\PharmacyWorkflow;
use App\Staff\InvitationDeliveryException;
use App\Staff\InvitationOutbox;
use App\Staff\StaffInvitation;
use Database\Seeders\CitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class StaffInvitationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CitySeeder::class);
    }

    public function test_invitation_is_single_use_hashed_and_does_not_set_a_password_for_the_manager(): void
    {
        $admin = $this->user(UserRole::Admin, 'invite-admin@example.test');
        $pharmacy = app(PharmacyWorkflow::class)->create($admin, [
            'name' => 'Invite Pharmacy',
            'address_text' => 'Susan Road',
        ]);
        $token = app(StaffInvitation::class)->inviteManager($admin, $pharmacy, 'new-manager@example.test');
        $invitation = AccountInvitation::query()->firstOrFail();

        $this->assertNotSame($token, $invitation->token_hash);
        $this->assertSame(64, strlen($invitation->token_hash));
        $this->assertNull(User::query()->where('email', 'new-manager@example.test')->first());
        $this->assertFalse(DB::table('audit_events')->where('metadata', 'like', '%'.$token.'%')->exists());
        $this->assertStringNotContainsString($token, (string) DB::table('invitation_deliveries')->value('token_ciphertext'));

        try {
            app(StaffInvitation::class)->acceptNewAccount($token, 'correct-horse-battery');
            $this->fail('An undelivered invitation was accepted.');
        } catch (PharmacyRuleException) {
        }

        $this->release($token);
        $accepted = app(StaffInvitation::class)->acceptNewAccount($token, 'correct-horse-battery');
        $this->assertTrue(Hash::check('correct-horse-battery', (string) $accepted->password));
        $this->assertSame(UserRole::PharmacyStaff, $accepted->role);

        try {
            app(StaffInvitation::class)->acceptNewAccount($token, 'another-password-value');
            $this->fail('A used invitation was accepted again.');
        } catch (PharmacyRuleException) {
        }
    }

    public function test_customer_email_cannot_become_staff_and_a_manager_cannot_invite_a_manager(): void
    {
        $admin = $this->user(UserRole::Admin, 'invite-admin-2@example.test');
        $customer = $this->user(UserRole::Customer, 'customer-invite@example.test');
        $customer->forceFill(['phone_e164' => '+923001112233'])->save();
        $pharmacy = app(PharmacyWorkflow::class)->create($admin, [
            'name' => 'Invite Pharmacy Two',
            'address_text' => 'Susan Road',
        ]);
        $token = app(StaffInvitation::class)->inviteManager($admin, $pharmacy, $customer->email);
        $this->release($token);

        try {
            app(StaffInvitation::class)->acceptNewAccount($token, 'correct-horse-battery');
            $this->fail('A customer account accepted a staff invitation.');
        } catch (PharmacyRuleException) {
        }

        $managerToken = app(StaffInvitation::class)->inviteManager($admin, $pharmacy, 'real-manager@example.test');
        $this->release($managerToken);
        $manager = app(StaffInvitation::class)->acceptNewAccount($managerToken, 'correct-horse-battery');

        $this->expectException(PharmacyRuleException::class);
        app(StaffInvitation::class)->inviteManager($manager, $pharmacy, 'second-manager@example.test');
    }

    public function test_expired_invitations_can_be_reissued_after_the_replacement_is_delivered(): void
    {
        $admin = $this->user(UserRole::Admin, 'invite-admin-4@example.test');
        $pharmacy = app(PharmacyWorkflow::class)->create($admin, [
            'name' => 'Invite Pharmacy Four',
            'address_text' => 'Susan Road',
        ]);
        $invitations = app(StaffInvitation::class);
        $first = $invitations->invitePharmacist($admin, $pharmacy, 'pharmacist-new@example.test');

        try {
            $invitations->invitePharmacist($admin, $pharmacy, 'pharmacist-new@example.test');
            $this->fail('A second open invitation was created.');
        } catch (PharmacyRuleException) {
        }

        $this->assertSame(1, AccountInvitation::query()->count());
        $stored = AccountInvitation::query()->firstOrFail();
        $invitations->revoke($admin, $stored);
        $this->assertNotNull($stored->refresh()->revoked_at);

        try {
            $invitations->acceptNewAccount($first, 'correct-horse-battery');
            $this->fail('A revoked invitation was accepted.');
        } catch (PharmacyRuleException) {
        }

        $second = $invitations->invitePharmacist($admin, $pharmacy, 'pharmacist-new@example.test');
        $this->release($second);
        $open = AccountInvitation::query()->whereNull('revoked_at')->whereNull('accepted_at')->firstOrFail();
        $previousHash = $open->token_hash;
        $resent = $invitations->reissue($admin, $open);
        $this->assertSame($previousHash, $open->refresh()->token_hash);
        $this->release($resent);

        try {
            $invitations->acceptNewAccount($second, 'correct-horse-battery');
            $this->fail('A replaced invitation token was accepted.');
        } catch (PharmacyRuleException) {
        }

        $this->travel(25)->hours();
        $open->refresh();
        $this->assertTrue($open->expires_at->isPast());
        $third = $invitations->invitePharmacist($admin, $pharmacy, 'pharmacist-new@example.test');
        $this->assertNotNull($open->refresh()->superseded_at);
        $this->release($third);

        $accepted = $invitations->acceptNewAccount($third, 'correct-horse-battery');
        $membership = PharmacyMembership::query()->where('user_id', $accepted->id)->firstOrFail();
        $this->assertSame(StaffRole::Pharmacist, $membership->staff_role);
        $this->assertSame(CredentialStatus::Unverified, $membership->credential_status);
        $this->travelBack();
    }

    public function test_invitation_mail_is_not_sent_when_the_transaction_rolls_back(): void
    {
        Mail::fake();
        $admin = $this->user(UserRole::Admin, 'invite-rollback@example.test');
        $pharmacy = app(PharmacyWorkflow::class)->create($admin, [
            'name' => 'Rollback Pharmacy',
            'address_text' => 'Susan Road',
        ]);

        try {
            DB::transaction(function () use ($admin, $pharmacy): void {
                app(StaffInvitation::class)->inviteManager($admin, $pharmacy, 'rollback-invite@example.test');
                throw new RuntimeException('cancel invitation');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('cancel invitation', $exception->getMessage());
        }

        Mail::assertNothingSent();
        $this->assertNull(AccountInvitation::query()->where('email', 'rollback-invite@example.test')->first());
        $this->assertSame(0, DB::table('invitation_deliveries')->count());
    }

    public function test_mail_is_sent_only_after_the_outermost_transaction_commits(): void
    {
        Mail::fake();
        $admin = $this->user(UserRole::Admin, 'invite-commit@example.test');
        $pharmacy = app(PharmacyWorkflow::class)->create($admin, [
            'name' => 'Commit Pharmacy',
            'address_text' => 'Susan Road',
        ]);
        $adminId = $admin->id;
        $pharmacyId = $pharmacy->id;

        DB::commit();

        try {
            try {
                DB::transaction(function () use ($admin, $pharmacy): void {
                    app(StaffInvitation::class)->inviteManager($admin, $pharmacy, 'rolled-back-invite@example.test');
                    throw new RuntimeException('cancel invitation');
                });
            } catch (RuntimeException $exception) {
                $this->assertSame('cancel invitation', $exception->getMessage());
            }

            Mail::assertNothingSent();
            $this->assertNull(AccountInvitation::query()->where('email', 'rolled-back-invite@example.test')->first());

            $token = app(StaffInvitation::class)->inviteManager($admin, $pharmacy, 'committed-invite@example.test');
            Mail::assertSent(AccountInvitationMail::class, 1);
            $stored = AccountInvitation::query()->where('email', 'committed-invite@example.test')->firstOrFail();
            $this->assertSame('delivered', $stored->delivery_status->value);
            $this->assertNull(DB::table('invitation_deliveries')->where('account_invitation_id', $stored->id)->first());
            $this->assertStringNotContainsString($token, (string) DB::table('audit_events')->where('subject_id', $stored->id)->value('metadata'));
            $this->assertSame(0, app(InvitationOutbox::class)->deliverOutstanding());
            Mail::assertSent(AccountInvitationMail::class, 1);
            $this->assertNotNull(app(StaffInvitation::class)->acceptNewAccount($token, 'correct-horse-battery')->id);
        } finally {
            $invitationIds = DB::table('account_invitations')->where('pharmacy_id', $pharmacyId)->pluck('id');
            DB::table('invitation_deliveries')->whereIn('account_invitation_id', $invitationIds)->delete();
            DB::table('pharmacy_memberships')->where('pharmacy_id', $pharmacyId)->delete();
            DB::table('account_invitations')->whereIn('id', $invitationIds)->delete();
            $acceptedUserId = DB::table('users')->where('email', 'committed-invite@example.test')->value('id');
            DB::table('audit_events')->where('pharmacy_id', $pharmacyId)->orWhere('actor_id', $adminId)->delete();

            if ($acceptedUserId !== null) {
                DB::table('audit_events')->where('actor_id', $acceptedUserId)->delete();
            }
            DB::table('pharmacy_operating_hours')->where('pharmacy_id', $pharmacyId)->delete();
            DB::table('pharmacy_inventory')->where('pharmacy_id', $pharmacyId)->delete();
            DB::table('pharmacies')->where('id', $pharmacyId)->delete();
            DB::table('users')->where('email', 'committed-invite@example.test')->delete();
            DB::table('users')->where('id', $adminId)->delete();

            if (DB::transactionLevel() === 0) {
                DB::beginTransaction();
            }
        }
    }

    public function test_failed_delivery_stays_pending_and_can_be_retried_without_storing_the_token(): void
    {
        $admin = $this->user(UserRole::Admin, 'invite-delivery@example.test');
        $pharmacy = app(PharmacyWorkflow::class)->create($admin, [
            'name' => 'Delivery Pharmacy',
            'address_text' => 'Susan Road',
        ]);
        $token = app(StaffInvitation::class)->invitePharmacist($admin, $pharmacy, 'pharmacist-retry@example.test');
        $fail = true;
        Event::listen(MessageSending::class, function () use (&$fail): void {
            if ($fail) {
                throw new RuntimeException('smtp down');
            }
        });

        try {
            $this->release($token);
            $this->fail('A failed delivery marked the invitation usable.');
        } catch (InvitationDeliveryException $exception) {
            $this->assertSame('Invitation email delivery failed.', $exception->getMessage());
        }

        $stored = AccountInvitation::query()->where('email', 'pharmacist-retry@example.test')->firstOrFail();
        $this->assertSame('pending', $stored->delivery_status->value);
        $ciphertext = (string) DB::table('invitation_deliveries')->where('account_invitation_id', $stored->id)->value('token_ciphertext');
        $this->assertNotSame('', $ciphertext);
        $this->assertStringNotContainsString($token, $ciphertext);

        try {
            app(StaffInvitation::class)->acceptNewAccount($token, 'correct-horse-battery');
            $this->fail('An undelivered invitation was accepted.');
        } catch (PharmacyRuleException) {
        }

        $failure = DB::table('audit_events')->where('action', 'invitation_delivery_failed')->first();
        $this->assertNotNull($failure);
        $this->assertStringNotContainsString($token, (string) $failure->metadata);
        $this->assertStringNotContainsString('smtp', (string) $failure->metadata);
        $this->assertStringNotContainsString('pharmacist-retry@example.test', (string) $failure->metadata);

        $fail = false;
        $this->release($token);
        $this->assertNotNull(app(StaffInvitation::class)->acceptNewAccount($token, 'correct-horse-battery')->id);
        $this->assertNull(DB::table('invitation_deliveries')->where('account_invitation_id', $stored->id)->first());
    }

    public function test_duplicate_delivery_sends_one_email_and_a_claimed_row_is_not_sent_again(): void
    {
        Mail::fake();
        $admin = $this->user(UserRole::Admin, 'invite-duplicate@example.test');
        $pharmacy = app(PharmacyWorkflow::class)->create($admin, [
            'name' => 'Duplicate Pharmacy',
            'address_text' => 'Susan Road',
        ]);
        $token = app(StaffInvitation::class)->inviteManager($admin, $pharmacy, 'duplicate-invite@example.test');
        $deliveryId = (string) DB::table('invitation_deliveries')->value('id');
        $claimId = (string) Str::uuid();
        DB::table('invitation_deliveries')->where('id', $deliveryId)->update([
            'claimed_at' => now(),
            'claim_id' => $claimId,
        ]);
        app(InvitationOutbox::class)->deliver($deliveryId);
        Mail::assertNothingSent();
        $this->assertSame($claimId, DB::table('invitation_deliveries')->where('id', $deliveryId)->value('claim_id'));

        DB::table('invitation_deliveries')->where('id', $deliveryId)->update(['claimed_at' => now()->subMinute()]);
        app(InvitationOutbox::class)->deliver($deliveryId);
        app(InvitationOutbox::class)->deliver($deliveryId);
        Mail::assertSent(AccountInvitationMail::class, 1);
        $this->assertSame('delivered', AccountInvitation::query()->where('email', 'duplicate-invite@example.test')->firstOrFail()->delivery_status->value);
        $this->assertStringNotContainsString($token, (string) DB::table('audit_events')->where('action', 'invitation_created')->value('metadata'));
    }

    public function test_reissue_delivery_failure_keeps_the_current_link(): void
    {
        $admin = $this->user(UserRole::Admin, 'invite-reissue@example.test');
        $pharmacy = app(PharmacyWorkflow::class)->create($admin, [
            'name' => 'Reissue Pharmacy',
            'address_text' => 'Susan Road',
        ]);
        $invitations = app(StaffInvitation::class);
        $previous = $invitations->inviteManager($admin, $pharmacy, 'manager-resend@example.test');
        $this->release($previous);
        $row = AccountInvitation::query()->where('email', 'manager-resend@example.test')->firstOrFail();
        $hash = $row->token_hash;
        $fail = true;
        Event::listen(MessageSending::class, function () use (&$fail): void {
            if ($fail) {
                throw new RuntimeException('smtp down');
            }
        });
        $replacement = $invitations->reissue($admin, $row);

        try {
            $this->release($replacement);
            $this->fail('A failed replacement email retired the current link.');
        } catch (InvitationDeliveryException $exception) {
            $this->assertSame('Invitation email delivery failed.', $exception->getMessage());
        }

        $this->assertSame($hash, $row->refresh()->token_hash);
        $this->assertSame('delivered', $row->delivery_status->value);
        $fail = false;
        $this->assertNotNull($invitations->acceptNewAccount($previous, 'correct-horse-battery')->id);
    }

    public function test_existing_staff_must_confirm_without_a_new_password(): void
    {
        $admin = $this->user(UserRole::Admin, 'invite-admin-5@example.test');
        $staff = $this->user(UserRole::PharmacyStaff, 'existing-staff@example.test');
        $staff->forceFill(['password' => Hash::make('existing-password-value')])->save();
        $original = (string) $staff->password;
        $pharmacy = app(PharmacyWorkflow::class)->create($admin, [
            'name' => 'Invite Pharmacy Five',
            'address_text' => 'Susan Road',
        ]);
        $token = app(StaffInvitation::class)->invitePharmacist($admin, $pharmacy, $staff->email);
        $this->release($token);

        $this->get('/invitations/accept?token='.$token)
            ->assertOk()
            ->assertSee('Confirm this invitation')
            ->assertDontSee('Save password');

        $this->post('/invitations/accept', [
            'token' => $token,
            'password' => 'another-password-value',
            'password_confirmation' => 'another-password-value',
        ])->assertOk()->assertSee('without entering a new one');

        $this->assertSame($original, (string) $staff->refresh()->password);
        $this->assertNull(PharmacyMembership::query()->where('user_id', $staff->id)->first());

        $this->actingAs($staff)->post('/invitations/accept', [
            'token' => $token,
        ])->assertOk()->assertSee('Invitation accepted')->assertDontSee($token);

        $this->assertSame($original, (string) $staff->refresh()->password);
        $membership = PharmacyMembership::query()->where('user_id', $staff->id)->firstOrFail();
        $this->assertSame(StaffRole::Pharmacist, $membership->staff_role);
        $this->assertSame(CredentialStatus::Unverified, $membership->credential_status);

        $this->post('/invitations/accept', [
            'token' => $token,
        ])->assertOk()->assertSee('no longer valid');
    }

    public function test_a_manager_cannot_invite_a_pharmacist(): void
    {
        $admin = $this->user(UserRole::Admin, 'invite-admin-6@example.test');
        $pharmacy = app(PharmacyWorkflow::class)->create($admin, [
            'name' => 'Invite Pharmacy Six',
            'address_text' => 'Susan Road',
        ]);
        $managerToken = app(StaffInvitation::class)->inviteManager($admin, $pharmacy, 'manager-six@example.test');
        $this->release($managerToken);
        $manager = app(StaffInvitation::class)->acceptNewAccount($managerToken, 'correct-horse-battery');

        $this->expectException(PharmacyRuleException::class);
        app(StaffInvitation::class)->invitePharmacist($manager, $pharmacy, 'pharmacist-six@example.test');
    }

    public function test_accept_form_stores_the_invitee_password_and_does_not_echo_the_token_afterward(): void
    {
        $admin = $this->user(UserRole::Admin, 'invite-admin-3@example.test');
        $token = app(StaffInvitation::class)->inviteAdmin('panel-admin@example.test', $admin);
        $this->release($token);

        $this->get('/invitations/accept?token='.$token)->assertOk();
        $this->post('/invitations/accept', [
            'token' => $token,
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ])->assertOk()->assertSee('Password saved')->assertDontSee($token);

        $user = User::query()->where('email', 'panel-admin@example.test')->firstOrFail();
        $this->assertSame(UserRole::Admin, $user->role);
        $this->assertTrue(Hash::check('correct-horse-battery', (string) $user->password));
    }

    private function release(string $token): void
    {
        $hash = hash_hmac('sha256', $token, (string) config('app.key'));
        $invitationId = DB::table('account_invitations')->where('token_hash', $hash)->value('id')
            ?? DB::table('invitation_deliveries')->where('token_hash', $hash)->value('account_invitation_id');

        if ($invitationId === null) {
            return;
        }

        $deliveryId = DB::table('invitation_deliveries')->where('account_invitation_id', $invitationId)->value('id');

        if ($deliveryId === null) {
            return;
        }

        app(InvitationOutbox::class)->deliver((string) $deliveryId);
    }

    private function user(UserRole $role, string $email): User
    {
        return User::query()->forceCreate([
            'public_id' => (string) Str::uuid(),
            'email' => $email,
            'role' => $role,
            'status' => AccountStatus::Active,
        ]);
    }
}
