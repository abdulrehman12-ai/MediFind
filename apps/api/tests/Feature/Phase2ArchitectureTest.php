<?php

namespace Tests\Feature;

use App\Audit\AuditRecorder;
use App\Catalog\MedicineCatalog;
use App\Enums\AccountStatus;
use App\Enums\CredentialStatus;
use App\Enums\LicenseStatus;
use App\Enums\LocationReviewStatus;
use App\Enums\MembershipStatus;
use App\Enums\PharmacyStatus;
use App\Enums\StaffRole;
use App\Enums\UserRole;
use App\Inventory\InventoryBook;
use App\Models\AccountInvitation;
use App\Models\Medicine;
use App\Models\Pharmacy;
use App\Models\PharmacyMembership;
use App\Models\User;
use App\Pharmacy\OperatingHoursWriter;
use App\Pharmacy\PharmacyRuleException;
use App\Pharmacy\PharmacyWorkflow;
use App\Staff\StaffInvitation;
use Database\Seeders\CitySeeder;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class Phase2ArchitectureTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private ?Connection $session = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CitySeeder::class);
        $this->admin = $this->user(UserRole::Admin, 'architecture-admin@example.test');
    }

    public function test_database_rejects_partial_verification_and_invalid_inventory_states(): void
    {
        $pharmacy = $this->pharmacy();
        $pharmacy->forceFill(['license_number' => 'LIC-1'])->save();
        $medicine = $this->activeMedicine();
        $inventory = app(InventoryBook::class)->save($this->admin, $pharmacy, [
            'medicine_id' => $medicine->id,
            'stock_status' => 'in_stock',
            'quantity_on_hand' => 4,
            'unit_price_paisa' => 1000,
            'is_available_for_pickup' => true,
        ]);
        $pharmacist = $this->user(UserRole::PharmacyStaff, 'architecture-pharmacist@example.test');
        $membership = $this->membership($pharmacy, $pharmacist, StaffRole::Pharmacist, 'RPH-1');
        $clerk = $this->membership($pharmacy, $this->user(UserRole::PharmacyStaff, 'architecture-clerk@example.test'), StaffRole::InventoryStaff, 'RPH-CLERK');
        $blankLicense = $this->membership($pharmacy, $this->user(UserRole::PharmacyStaff, 'architecture-blank@example.test'), StaffRole::Pharmacist, '   ');
        $missingLicense = $this->membership($pharmacy, $this->user(UserRole::PharmacyStaff, 'architecture-missing@example.test'), StaffRole::Pharmacist, null);

        $this->assertRejected(function () use ($pharmacy): void {
            DB::table('pharmacies')->where('id', $pharmacy->id)->update([
                'license_status' => 'verified',
                'license_number' => 'LIC-1',
                'license_reviewed_by' => $this->admin->id,
                'license_reviewed_at' => now(),
                'license_verification_method' => 'documented_admin_review',
                'license_evidence' => null,
            ]);
        });
        $this->assertRejected(function () use ($pharmacy): void {
            DB::table('pharmacies')->where('id', $pharmacy->id)->update([
                'license_status' => 'verified',
                'license_evidence' => '   ',
                'license_reviewed_by' => $this->admin->id,
                'license_reviewed_at' => now(),
                'license_verification_method' => 'documented_admin_review',
            ]);
        });
        $this->assertRejected(function () use ($pharmacy): void {
            DB::table('pharmacies')->where('id', $pharmacy->id)->update([
                'location_review_status' => 'approved',
                'location_evidence' => null,
            ]);
        });
        $this->assertRejected(function () use ($pharmacy): void {
            DB::table('pharmacies')->where('id', $pharmacy->id)->update([
                'status' => 'rejected',
                'status_reason' => null,
            ]);
        });
        $this->assertRejected(function () use ($pharmacy): void {
            DB::table('pharmacies')->where('id', $pharmacy->id)->update([
                'status' => 'suspended',
                'status_reason' => '   ',
            ]);
        });
        $this->assertRejected(function () use ($clerk): void {
            DB::table('pharmacy_memberships')->where('id', $clerk->id)->update([
                'credential_status' => 'verified',
                'credential_reviewed_by' => $this->admin->id,
                'credential_reviewed_at' => now(),
                'credential_verification_method' => 'documented_admin_review',
                'credential_evidence' => 'Reviewed certificate',
            ]);
        });
        $this->assertRejected(function () use ($membership): void {
            DB::table('pharmacy_memberships')->where('id', $membership->id)->update([
                'credential_status' => 'verified',
                'credential_reviewed_by' => $this->admin->id,
                'credential_reviewed_at' => now(),
                'credential_verification_method' => 'documented_admin_review',
                'credential_evidence' => null,
            ]);
        });
        $this->assertRejected(function () use ($missingLicense): void {
            DB::table('pharmacy_memberships')->where('id', $missingLicense->id)->update([
                'credential_status' => 'verified',
                'credential_reviewed_by' => $this->admin->id,
                'credential_reviewed_at' => now(),
                'credential_verification_method' => 'documented_admin_review',
                'credential_evidence' => 'Reviewed certificate',
            ]);
        });
        $this->assertRejected(function () use ($blankLicense): void {
            DB::table('pharmacy_memberships')->where('id', $blankLicense->id)->update([
                'credential_status' => 'verified',
                'credential_reviewed_by' => $this->admin->id,
                'credential_reviewed_at' => now(),
                'credential_verification_method' => 'documented_admin_review',
                'credential_evidence' => 'Reviewed certificate',
            ]);
        });

        foreach ([
            ['stock_status' => 'low_stock', 'quantity_on_hand' => null, 'last_verified_at' => now(), 'is_available_for_pickup' => false],
            ['stock_status' => 'out_of_stock', 'quantity_on_hand' => null, 'last_verified_at' => now(), 'is_available_for_pickup' => false],
            ['stock_status' => 'low_stock', 'quantity_on_hand' => 0, 'last_verified_at' => now(), 'is_available_for_pickup' => false],
            ['stock_status' => 'in_stock', 'quantity_on_hand' => 0, 'last_verified_at' => now(), 'is_available_for_pickup' => false],
            ['stock_status' => 'unknown', 'quantity_on_hand' => 2, 'last_verified_at' => null, 'is_available_for_pickup' => false],
        ] as $invalid) {
            $this->assertRejected(function () use ($inventory, $invalid): void {
                DB::table('pharmacy_inventory')->where('id', $inventory->id)->update($invalid);
            });
        }

        $this->assertSame(4, $inventory->refresh()->quantity_on_hand);
        $this->assertSame(LicenseStatus::Unverified, $pharmacy->refresh()->license_status);
        $this->assertSame(CredentialStatus::Unverified, $membership->refresh()->credential_status);
    }

    public function test_only_an_active_admin_can_verify_an_eligible_pharmacist(): void
    {
        $pharmacy = $this->pharmacy();
        $workflow = app(PharmacyWorkflow::class);
        $pharmacist = $this->user(UserRole::PharmacyStaff, 'verify-pharmacist@example.test');
        $manager = $this->user(UserRole::PharmacyStaff, 'verify-manager@example.test');
        $eligible = $this->membership($pharmacy, $pharmacist, StaffRole::Pharmacist, 'RPH-200');
        $managerMembership = $this->membership($pharmacy, $manager, StaffRole::Manager, null);
        $inactive = $this->membership($pharmacy, $this->user(UserRole::PharmacyStaff, 'verify-inactive@example.test'), StaffRole::Pharmacist, 'RPH-201');
        $inactive->forceFill(['status' => MembershipStatus::Suspended])->save();
        $blank = $this->membership($pharmacy, $this->user(UserRole::PharmacyStaff, 'verify-blank@example.test'), StaffRole::Pharmacist, '   ');

        $ids = $workflow->verifiablePharmacistMemberships($pharmacy)->pluck('id')->all();
        $this->assertSame([$eligible->id], $ids);

        foreach ([$managerMembership, $inactive, $blank] as $membership) {
            try {
                $workflow->verifyCredential($this->admin, $membership, 'Certificate inspected.');
                $this->fail('An ineligible membership was verified.');
            } catch (PharmacyRuleException) {
            }
            $this->assertSame(CredentialStatus::Unverified, $membership->refresh()->credential_status);
        }

        try {
            $workflow->verifyCredential($pharmacist, $eligible, 'Self review.');
            $this->fail('Pharmacy staff verified a credential.');
        } catch (PharmacyRuleException) {
        }

        $inactiveAdmin = $this->user(UserRole::Admin, 'verify-inactive-admin@example.test');
        $inactiveAdmin->forceFill(['status' => AccountStatus::Suspended])->save();

        try {
            $workflow->verifyCredential($inactiveAdmin, $eligible, 'Certificate inspected.');
            $this->fail('An inactive administrator verified a credential.');
        } catch (PharmacyRuleException) {
        }

        $workflow->verifyCredential($this->admin, $eligible, 'Certificate inspected.');
        $this->assertSame(CredentialStatus::Verified, $eligible->refresh()->credential_status);

        $workflow->recordPharmacistLicense($this->admin, $eligible, 'RPH-200-B');
        $this->assertSame(CredentialStatus::Unverified, $eligible->refresh()->credential_status);
        $this->assertNull($eligible->credential_evidence);

        $workflow->verifyCredential($this->admin, $eligible->refresh(), 'Certificate inspected again.');
        $eligible->forceFill(['staff_role' => StaffRole::InventoryStaff])->save();
        $this->assertSame(CredentialStatus::Unverified, $eligible->refresh()->credential_status);
        $this->assertSame(StaffRole::InventoryStaff, $eligible->staff_role);

        DB::table('pharmacy_memberships')->where('id', $managerMembership->id)->update([
            'staff_role' => 'pharmacist',
            'license_number' => 'RPH-KEEP',
            'credential_status' => 'verified',
            'credential_reviewed_by' => $this->admin->id,
            'credential_reviewed_at' => now(),
            'credential_verification_method' => 'documented_admin_review',
            'credential_evidence' => 'Should be cleared because the role changed.',
        ]);
        $managerMembership->refresh();
        $this->assertSame(StaffRole::Pharmacist, $managerMembership->staff_role);
        $this->assertSame(CredentialStatus::Unverified, $managerMembership->credential_status);
        $this->assertNull($managerMembership->credential_evidence);
    }

    public function test_controlled_transition_clears_pickup_for_service_and_direct_updates(): void
    {
        $pharmacy = $this->pharmacy();
        $catalog = app(MedicineCatalog::class);
        $medicine = $catalog->create($this->admin, [
            'generic_name' => 'Diazepam',
            'dosage_form' => 'Tablet',
            'strength' => '5 mg',
            'manufacturer' => 'Gamma Labs',
            'controlled_drug' => false,
        ]);
        $catalog->activate($this->admin, $medicine);
        $inventory = app(InventoryBook::class)->save($this->admin, $pharmacy, [
            'medicine_id' => $medicine->id,
            'stock_status' => 'in_stock',
            'quantity_on_hand' => 2,
            'unit_price_paisa' => 500,
            'is_available_for_pickup' => true,
        ]);
        $this->assertTrue($inventory->is_available_for_pickup);

        $this->assertRejected(function () use ($medicine): void {
            DB::statement('ALTER TABLE medicines DISABLE TRIGGER medifind_clear_ineligible_pickup');
            DB::statement('SET CONSTRAINTS medifind_reject_controlled_pickup_medicine IMMEDIATE');
            DB::table('medicines')->where('id', $medicine->id)->update(['controlled_drug' => true]);
        });
        $this->assertFalse((bool) $medicine->refresh()->controlled_drug);
        $this->assertTrue($inventory->refresh()->is_available_for_pickup);

        $catalog->update($this->admin, $medicine->refresh(), [
            'generic_name' => 'Diazepam',
            'dosage_form' => 'Tablet',
            'strength' => '5 mg',
            'manufacturer' => 'Gamma Labs',
            'controlled_drug' => true,
        ]);
        $inventory->refresh();
        $this->assertTrue((bool) $medicine->refresh()->controlled_drug);
        $this->assertFalse($inventory->is_available_for_pickup);

        $second = $catalog->create($this->admin, [
            'generic_name' => 'Morphine',
            'dosage_form' => 'Injection',
            'strength' => '10 mg',
            'manufacturer' => 'Delta Labs',
        ]);
        $catalog->activate($this->admin, $second);
        $secondInventory = app(InventoryBook::class)->save($this->admin, $pharmacy, [
            'medicine_id' => $second->id,
            'stock_status' => 'low_stock',
            'quantity_on_hand' => 1,
            'unit_price_paisa' => 800,
            'is_available_for_pickup' => true,
        ]);
        DB::table('medicines')->where('id', $second->id)->update(['controlled_drug' => true]);
        $this->assertFalse((bool) $secondInventory->refresh()->is_available_for_pickup);
        $this->assertSame(0, DB::table('pharmacy_inventory')
            ->join('medicines', 'medicines.id', '=', 'pharmacy_inventory.medicine_id')
            ->where('medicines.controlled_drug', true)
            ->where('pharmacy_inventory.is_available_for_pickup', true)
            ->count());
    }

    public function test_direct_license_and_location_changes_clear_reviews(): void
    {
        $pharmacy = $this->pharmacy();
        $pharmacy->forceFill([
            'license_number' => 'LIC-OLD',
            'latitude' => '31.418000',
            'longitude' => '73.079000',
        ])->save();
        $workflow = app(PharmacyWorkflow::class);
        $workflow->approve($this->admin, $pharmacy);
        $workflow->verifyLicense($this->admin, $pharmacy->refresh(), 'Paper file checked.');
        $workflow->approveLocation($this->admin, $pharmacy->refresh(), 'Shopfront checked.');

        DB::table('pharmacies')->where('id', $pharmacy->id)->update([
            'license_number' => 'LIC-NEW',
            'license_status' => 'verified',
            'license_reviewed_by' => $this->admin->id,
            'license_reviewed_at' => now(),
            'license_verification_method' => 'documented_admin_review',
            'license_evidence' => 'This review belongs to the previous number.',
        ]);
        $pharmacy->refresh();
        $this->assertSame('LIC-NEW', $pharmacy->license_number);
        $this->assertSame(LicenseStatus::Unverified, $pharmacy->license_status);
        $this->assertNull($pharmacy->license_evidence);

        DB::table('pharmacies')->where('id', $pharmacy->id)->update([
            'latitude' => '31.420000',
            'location_review_status' => 'approved',
            'location_reviewed_by' => $this->admin->id,
            'location_reviewed_at' => now(),
            'location_verification_method' => 'documented_admin_review',
            'location_evidence' => 'This review belongs to the previous coordinates.',
        ]);
        $pharmacy->refresh();
        $this->assertSame(LocationReviewStatus::Pending, $pharmacy->location_review_status);
        $this->assertNull($pharmacy->location_evidence);
        $this->assertFalse($pharmacy->publiclyEligible());
    }

    public function test_review_waits_for_a_concurrent_profile_change(): void
    {
        $pharmacy = $this->pharmacy();
        $pharmacy->forceFill(['license_number' => 'LIC-RACE'])->save();
        $holder = null;

        $this->outsideTransaction(function () use ($pharmacy, &$holder): void {
            $holder = $this->secondConnection();
            $holder->beginTransaction();
            $holder->select('select id from pharmacies where id = ? for update', [$pharmacy->id]);
            $holder->update('update pharmacies set license_number = ? where id = ?', ['LIC-AFTER', $pharmacy->id]);

            DB::statement("set lock_timeout = '300ms'");

            try {
                DB::transaction(function () use ($pharmacy): void {
                    DB::select('select id from pharmacies where id = ? for update', [$pharmacy->id]);
                });
                $this->fail('License verification was not blocked by the profile update.');
            } catch (QueryException $exception) {
                $this->assertSame('55P03', $exception->getCode());
            }

            $holder->commit();
            $holder = null;

            try {
                app(PharmacyWorkflow::class)->verifyLicense($this->admin, $pharmacy, 'Paper file checked.');
                $this->fail('A review was applied after the license number changed.');
            } catch (PharmacyRuleException) {
            }

            $current = DB::table('pharmacies')->where('id', $pharmacy->id)->first();
            $this->assertSame('LIC-AFTER', $current->license_number);
            $this->assertSame('unverified', $current->license_status);
        }, [$pharmacy->id], [(int) $this->admin->id]);
    }

    public function test_overlapping_hour_writes_cannot_commit_together(): void
    {
        $pharmacy = $this->pharmacy();
        $holder = null;

        $this->outsideTransaction(function () use ($pharmacy, &$holder): void {
            $holder = $this->secondConnection();
            $holder->beginTransaction();
            $holder->insert(
                'insert into pharmacy_operating_hours (pharmacy_id, day_of_week, interval_kind, opens_at, closes_at, created_at, updated_at) values (?, 1, ?, ?, ?, now(), now())',
                [$pharmacy->id, 'same_day', '09:00', '17:00'],
            );

            DB::statement("set lock_timeout = '300ms'");

            try {
                DB::transaction(function () use ($pharmacy): void {
                    DB::insert(
                        'insert into pharmacy_operating_hours (pharmacy_id, day_of_week, interval_kind, opens_at, closes_at, created_at, updated_at) values (?, 1, ?, ?, ?, now(), now())',
                        [$pharmacy->id, 'same_day', '10:00', '18:00'],
                    );
                });
                $this->fail('An overlapping hours write was not blocked.');
            } catch (QueryException $exception) {
                $this->assertSame('55P03', $exception->getCode());
            }

            $holder->commit();
            $holder = null;
            $this->assertSame(1, DB::table('pharmacy_operating_hours')->where('pharmacy_id', $pharmacy->id)->count());

            try {
                DB::transaction(function () use ($pharmacy): void {
                    DB::insert(
                        'insert into pharmacy_operating_hours (pharmacy_id, day_of_week, interval_kind, opens_at, closes_at, created_at, updated_at) values (?, 1, ?, ?, ?, now(), now())',
                        [$pharmacy->id, 'same_day', '10:00', '18:00'],
                    );
                });
                $this->fail('An overlapping hours write was committed.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('Opening intervals overlap', $exception->getMessage());
            }

            $this->assertSame(1, DB::table('pharmacy_operating_hours')->where('pharmacy_id', $pharmacy->id)->count());
        }, [$pharmacy->id], [(int) $this->admin->id]);
    }

    public function test_concurrent_invitations_for_the_same_person_are_serialized(): void
    {
        $pharmacy = $this->pharmacy();
        $holder = null;
        $email = 'locked-invite@example.test';

        $this->outsideTransaction(function () use ($pharmacy, $email, &$holder): void {
            $holder = $this->secondConnection();
            $holder->beginTransaction();
            $holder->selectOne(
                "select pg_advisory_xact_lock(('x' || substr(md5(?), 1, 16))::bit(64)::bigint)",
                [$email.'|pharmacist|'.$pharmacy->id],
            );

            DB::statement("set lock_timeout = '300ms'");

            try {
                app(StaffInvitation::class)->invitePharmacist($this->admin, $pharmacy, $email);
                $this->fail('A concurrent invitation was not blocked.');
            } catch (QueryException $exception) {
                $this->assertSame('55P03', $exception->getCode());
            }

            $holder->rollBack();
            $holder = null;
            $this->assertSame(0, DB::table('account_invitations')->where('email', $email)->count());
        }, [$pharmacy->id], [(int) $this->admin->id]);
    }

    public function test_audit_failure_rolls_back_the_sensitive_change(): void
    {
        $pharmacy = $this->pharmacy();
        $workflow = app(PharmacyWorkflow::class);
        $workflow->approve($this->admin, $pharmacy);
        $manager = $this->user(UserRole::PharmacyStaff, 'audit-manager@example.test');
        $this->membership($pharmacy, $manager, StaffRole::Manager, null);
        app(OperatingHoursWriter::class)->replace($manager, $pharmacy, [
            ['day_of_week' => 1, 'interval_kind' => 'same_day', 'opens_at' => '09:00', 'closes_at' => '17:00'],
        ]);
        $medicine = $this->activeMedicine();
        $inventory = app(InventoryBook::class)->save($this->admin, $pharmacy, [
            'medicine_id' => $medicine->id,
            'stock_status' => 'in_stock',
            'quantity_on_hand' => 3,
            'unit_price_paisa' => 1200,
            'is_available_for_pickup' => true,
        ]);
        $invitationToken = app(StaffInvitation::class)->inviteManager($this->admin, $pharmacy, 'audit-invite@example.test');
        $invitationId = DB::table('account_invitations')->where('email', 'audit-invite@example.test')->value('id');

        $failing = new class extends AuditRecorder
        {
            public function record(
                ?User $actor,
                string $action,
                string $subjectType,
                string $subjectId,
                ?string $pharmacyId,
                array $metadata = [],
            ): void {
                throw new RuntimeException('audit store failed');
            }
        };

        $this->assertAuditRollsBack(function () use ($failing, $pharmacy): void {
            (new PharmacyWorkflow($failing))->suspend($this->admin, $pharmacy, 'Inspection failed.');
        });
        $this->assertSame(PharmacyStatus::Approved, $pharmacy->refresh()->status);
        $this->assertSame(0, $manager->refresh()->session_generation);

        $this->assertAuditRollsBack(function () use ($failing, $manager, $pharmacy): void {
            (new OperatingHoursWriter($failing))->replace($manager, $pharmacy, [
                ['day_of_week' => 2, 'interval_kind' => 'same_day', 'opens_at' => '10:00', 'closes_at' => '14:00'],
            ]);
        });
        $this->assertSame(1, $pharmacy->operatingHours()->count());
        $this->assertSame(1, $pharmacy->operatingHours()->firstOrFail()->day_of_week);

        $this->assertAuditRollsBack(function () use ($failing, $pharmacy, $medicine, $inventory): void {
            (new InventoryBook($failing))->save($this->admin, $pharmacy, [
                'medicine_id' => $medicine->id,
                'stock_status' => 'low_stock',
                'quantity_on_hand' => 1,
                'unit_price_paisa' => 1200,
                'is_available_for_pickup' => false,
                'version' => $inventory->version,
            ]);
        });
        $this->assertSame(3, $inventory->refresh()->quantity_on_hand);

        $this->assertAuditRollsBack(function () use ($failing, $medicine): void {
            (new MedicineCatalog($failing))->update($this->admin, $medicine, [
                'generic_name' => 'Paracetamol',
                'dosage_form' => 'Tablet',
                'strength' => '500 mg',
                'manufacturer' => 'Alpha Labs',
                'controlled_drug' => true,
            ]);
        });
        $this->assertFalse((bool) $medicine->refresh()->controlled_drug);
        $this->assertTrue($inventory->refresh()->is_available_for_pickup);

        $this->assertAuditRollsBack(function () use ($failing, $invitationId): void {
            $invitation = AccountInvitation::query()->findOrFail($invitationId);
            (new StaffInvitation($failing))->revoke($this->admin, $invitation);
        });
        $this->assertNull(DB::table('account_invitations')->where('id', $invitationId)->value('revoked_at'));

        app(AuditRecorder::class)->record($this->admin, 'redaction_probe', 'pharmacy', (string) $pharmacy->id, (string) $pharmacy->id, [
            'token' => $invitationToken,
            'password' => 'correct-horse-battery',
            'email' => 'audit-invite@example.test',
        ]);
        $metadata = (string) DB::table('audit_events')->where('action', 'redaction_probe')->value('metadata');
        $this->assertStringNotContainsString($invitationToken, $metadata);
        $this->assertStringNotContainsString('correct-horse-battery', $metadata);
        $this->assertStringNotContainsString('audit-invite@example.test', $metadata);
    }

    public function test_staff_writes_waiting_behind_suspension_do_not_change_data(): void
    {
        $pharmacy = $this->pharmacy();
        $workflow = app(PharmacyWorkflow::class);
        $workflow->approve($this->admin, $pharmacy);
        $manager = $this->user(UserRole::PharmacyStaff, 'suspend-manager@example.test');
        $this->membership($pharmacy, $manager, StaffRole::Manager, null);
        $medicineId = null;

        $this->outsideTransaction(function () use ($pharmacy, $manager, &$medicineId): void {
            $medicine = $this->activeMedicine();
            $medicineId = (string) $medicine->id;
            $book = app(InventoryBook::class);
            $inventory = $book->save($manager, $pharmacy, $this->stock($medicine->id, 3, true));
            app(OperatingHoursWriter::class)->replace($manager, $pharmacy, [$this->shift(1, '09:00', '17:00')]);
            $audits = DB::table('audit_events')->count();

            $manager->forceFill(['status' => AccountStatus::Suspended])->save();

            try {
                $book->save($manager, $pharmacy, $this->stock($medicine->id, 1, false, $inventory->version));
                $this->fail('A suspended account changed inventory.');
            } catch (PharmacyRuleException) {
            }

            $manager->forceFill(['status' => AccountStatus::Active])->save();
            $this->assertSame(3, $inventory->refresh()->quantity_on_hand);
            $this->assertSame($audits, DB::table('audit_events')->count());

            $holder = null;

            try {
                $holder = $this->secondConnection();
                $holder->beginTransaction();
                $holder->select('select id from pharmacies where id = ? for update', [$pharmacy->id]);
                $holder->update(
                    'update pharmacies set status = ?, status_reason = ? where id = ?',
                    ['suspended', 'Inspection', $pharmacy->id],
                );
                DB::statement("set lock_timeout = '300ms'");

                try {
                    $book->save($manager, $pharmacy, $this->stock($medicine->id, 1, false, $inventory->version));
                    $this->fail('Inventory proceeded while suspension held the pharmacy lock.');
                } catch (QueryException $exception) {
                    $this->assertSame('55P03', $exception->getCode());
                }

                try {
                    app(StaffInvitation::class)->inviteInventoryStaff($manager, $pharmacy, 'during-suspension@example.test');
                    $this->fail('An invitation proceeded while suspension held the pharmacy lock.');
                } catch (QueryException $exception) {
                    $this->assertSame('55P03', $exception->getCode());
                }

                $this->assertSame(3, DB::table('pharmacy_inventory')->where('id', $inventory->id)->value('quantity_on_hand'));
                $this->assertSame(0, DB::table('account_invitations')->where('email', 'during-suspension@example.test')->count());
                $this->assertSame($audits, DB::table('audit_events')->count());

                $holder->commit();
                $holder = null;

                try {
                    $book->save($manager, $pharmacy, $this->stock($medicine->id, 1, false, $inventory->version));
                    $this->fail('Suspended-pharmacy inventory was saved.');
                } catch (PharmacyRuleException) {
                }

                try {
                    app(StaffInvitation::class)->inviteInventoryStaff($manager, $pharmacy, 'after-suspension@example.test');
                    $this->fail('A manager invitation was created after suspension.');
                } catch (PharmacyRuleException) {
                }

                $this->assertSame(3, $inventory->refresh()->quantity_on_hand);
                $this->assertSame(0, DB::table('account_invitations')->where('email', 'like', '%suspension@example.test')->count());
                $this->assertSame($audits, DB::table('audit_events')->count());

                $adminInventory = $book->save($this->admin, $pharmacy, $this->stock($medicine->id, 4, false, $inventory->version));
                $this->assertSame(4, $adminInventory->quantity_on_hand);
                app(StaffInvitation::class)->inviteInventoryStaff($this->admin, $pharmacy, 'admin-after-suspension@example.test');
                $this->assertSame(1, DB::table('account_invitations')->where('email', 'admin-after-suspension@example.test')->count());
            } finally {
                if ($holder instanceof Connection && $holder->transactionLevel() > 0) {
                    $holder->rollBack();
                }

                DB::table('pharmacy_inventory')->where('medicine_id', $medicineId)->delete();
                DB::table('medicines')->where('id', $medicineId)->delete();
            }
        }, [$pharmacy->id], [(int) $this->admin->id, (int) $manager->id]);
    }

    public function test_suspension_waiting_behind_a_staff_write_keeps_that_write(): void
    {
        $pharmacy = $this->pharmacy();
        $workflow = app(PharmacyWorkflow::class);
        $workflow->approve($this->admin, $pharmacy);
        $manager = $this->user(UserRole::PharmacyStaff, 'suspend-reverse@example.test');
        $this->membership($pharmacy, $manager, StaffRole::Manager, null);

        $this->outsideTransaction(function () use ($pharmacy, $manager, $workflow): void {
            app(OperatingHoursWriter::class)->replace($manager, $pharmacy, [$this->shift(1, '09:00', '17:00')]);
            $holder = null;

            try {
                $holder = $this->secondConnection();
                $holder->beginTransaction();
                $holder->select('select id from pharmacies where id = ? for update', [$pharmacy->id]);
                $holder->insert(
                    'insert into pharmacy_operating_hours (pharmacy_id, day_of_week, interval_kind, opens_at, closes_at, created_at, updated_at) values (?, 2, ?, ?, ?, now(), now())',
                    [$pharmacy->id, 'same_day', '10:00', '14:00'],
                );
                DB::statement("set lock_timeout = '300ms'");

                try {
                    $workflow->suspend($this->admin, $pharmacy, 'Inspection');
                    $this->fail('Suspension interrupted an in-flight hours write.');
                } catch (QueryException $exception) {
                    $this->assertSame('55P03', $exception->getCode());
                }

                $this->assertSame('approved', DB::table('pharmacies')->where('id', $pharmacy->id)->value('status'));
                $this->assertSame(1, DB::table('pharmacy_operating_hours')->where('pharmacy_id', $pharmacy->id)->count());
                $holder->commit();
                $holder = null;
                $this->assertSame(2, DB::table('pharmacy_operating_hours')->where('pharmacy_id', $pharmacy->id)->count());
                $workflow->suspend($this->admin, $pharmacy->refresh(), 'Inspection');
                $this->assertSame(PharmacyStatus::Suspended, $pharmacy->refresh()->status);
                $this->assertSame(2, DB::table('pharmacy_operating_hours')->where('pharmacy_id', $pharmacy->id)->count());
            } finally {
                if ($holder instanceof Connection && $holder->transactionLevel() > 0) {
                    $holder->rollBack();
                }
            }
        }, [$pharmacy->id], [(int) $this->admin->id, (int) $manager->id]);
    }

    public function test_deactivating_a_medicine_clears_pickup_without_restoring_it_later(): void
    {
        $pharmacy = $this->pharmacy();
        $other = $this->pharmacy();
        $catalog = app(MedicineCatalog::class);
        $book = app(InventoryBook::class);
        $medicine = $this->activeMedicine();
        $available = $book->save($this->admin, $pharmacy, $this->stock($medicine->id, 2, true));
        $hidden = $book->save($this->admin, $other, $this->stock($medicine->id, 2, false));
        $failing = new class extends AuditRecorder
        {
            public function record(
                ?User $actor,
                string $action,
                string $subjectType,
                string $subjectId,
                ?string $pharmacyId,
                array $metadata = [],
            ): void {
                throw new RuntimeException('audit store failed');
            }
        };

        $this->assertAuditRollsBack(function () use ($failing, $medicine): void {
            (new MedicineCatalog($failing))->deactivate($this->admin, $medicine);
        });
        $this->assertSame('active', $medicine->refresh()->status->value);
        $this->assertTrue($available->refresh()->is_available_for_pickup);
        $this->assertSame(1, $available->version);

        $catalog->deactivate($this->admin, $medicine);
        $this->assertSame('inactive', $medicine->refresh()->status->value);
        $this->assertFalse($available->refresh()->is_available_for_pickup);
        $this->assertSame(2, $available->version);
        $this->assertFalse($hidden->refresh()->is_available_for_pickup);
        $this->assertSame(1, $hidden->version);

        $catalog->activate($this->admin, $medicine->refresh());
        $this->assertSame('active', $medicine->refresh()->status->value);
        $this->assertFalse($available->refresh()->is_available_for_pickup);
        $this->assertSame(2, $available->version);

        $second = $catalog->create($this->admin, [
            'generic_name' => 'Ibuprofen',
            'dosage_form' => 'Tablet',
            'strength' => '200 mg',
            'manufacturer' => 'Beta Labs',
        ]);
        $catalog->activate($this->admin, $second);
        $secondInventory = $book->save($this->admin, $pharmacy, $this->stock($second->id, 5, true));
        DB::table('medicines')->where('id', $second->id)->update(['status' => 'inactive']);
        $this->assertFalse((bool) $secondInventory->refresh()->is_available_for_pickup);
        $this->assertSame(2, $secondInventory->version);
        $this->assertSame('inactive', $second->refresh()->status->value);

        $this->assertRejected(function () use ($second): void {
            DB::statement('ALTER TABLE medicines DISABLE TRIGGER medifind_clear_ineligible_pickup');
            DB::statement('SET CONSTRAINTS medifind_reject_controlled_pickup_medicine IMMEDIATE');
            DB::table('medicines')->where('id', $second->id)->update(['status' => 'active']);
            DB::table('pharmacy_inventory')->where('medicine_id', $second->id)->update([
                'is_available_for_pickup' => true,
            ]);
            DB::table('medicines')->where('id', $second->id)->update(['status' => 'inactive']);
        });
        $this->assertFalse((bool) $secondInventory->refresh()->is_available_for_pickup);

        $this->assertRejected(function () use ($secondInventory): void {
            DB::table('pharmacy_inventory')->where('id', $secondInventory->id)->update([
                'quantity_on_hand' => 9,
            ]);
        });
        $this->assertSame(5, $secondInventory->refresh()->quantity_on_hand);
    }

    /**
     * @return array{medicine_id: string, stock_status: string, quantity_on_hand: int, unit_price_paisa: int, is_available_for_pickup: bool, version?: int}
     */
    private function stock(string $medicineId, int $quantity, bool $pickup, ?int $version = null): array
    {
        $input = [
            'medicine_id' => $medicineId,
            'stock_status' => 'in_stock',
            'quantity_on_hand' => $quantity,
            'unit_price_paisa' => 1200,
            'is_available_for_pickup' => $pickup,
        ];

        if ($version !== null) {
            $input['version'] = $version;
        }

        return $input;
    }

    /**
     * @return array{day_of_week: int, interval_kind: string, opens_at: string, closes_at: string}
     */
    private function shift(int $day, string $opensAt, string $closesAt): array
    {
        return [
            'day_of_week' => $day,
            'interval_kind' => 'same_day',
            'opens_at' => $opensAt,
            'closes_at' => $closesAt,
        ];
    }

    private function assertAuditRollsBack(callable $callback): void
    {
        try {
            $callback();
            $this->fail('An audit failure still completed the change.');
        } catch (RuntimeException $exception) {
            $this->assertSame('audit store failed', $exception->getMessage());
        }
    }

    private function assertRejected(callable $callback): void
    {
        try {
            DB::transaction($callback);
            $this->fail('The database accepted an invalid row.');
        } catch (QueryException) {
        }
    }

    /**
     * @param  list<string>  $pharmacyIds
     * @param  list<int>  $userIds
     */
    private function outsideTransaction(callable $callback, array $pharmacyIds, array $userIds): void
    {
        DB::commit();

        try {
            $callback();
        } finally {
            if ($this->session instanceof Connection && $this->session->transactionLevel() > 0) {
                $this->session->rollBack();
            }

            foreach ($pharmacyIds as $pharmacyId) {
                DB::table('pharmacy_operating_hours')->where('pharmacy_id', $pharmacyId)->delete();
                DB::table('pharmacy_inventory')->where('pharmacy_id', $pharmacyId)->delete();
                DB::table('pharmacy_memberships')->where('pharmacy_id', $pharmacyId)->delete();
                $invitationIds = DB::table('account_invitations')
                    ->where('pharmacy_id', $pharmacyId)
                    ->orWhere('email', 'locked-invite@example.test')
                    ->pluck('id');
                DB::table('invitation_deliveries')->whereIn('account_invitation_id', $invitationIds)->delete();
                DB::table('account_invitations')->whereIn('id', $invitationIds)->delete();
                DB::table('audit_events')->where('pharmacy_id', $pharmacyId)->delete();
                DB::table('pharmacies')->where('id', $pharmacyId)->delete();
            }

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

    private function pharmacy(): Pharmacy
    {
        return app(PharmacyWorkflow::class)->create($this->admin, [
            'name' => 'Architecture Pharmacy '.Str::lower(Str::random(4)),
            'address_text' => 'Susan Road',
            'latitude' => '31.418000',
            'longitude' => '73.079000',
        ]);
    }

    private function activeMedicine(): Medicine
    {
        $catalog = app(MedicineCatalog::class);
        $medicine = $catalog->create($this->admin, [
            'generic_name' => 'Paracetamol',
            'dosage_form' => 'Tablet',
            'strength' => '500 mg',
            'manufacturer' => 'Alpha Labs',
        ]);
        $catalog->activate($this->admin, $medicine);

        return $medicine->refresh();
    }

    private function user(UserRole $role, string $email): User
    {
        return User::query()->forceCreate([
            'public_id' => (string) Str::uuid(),
            'email' => $email,
            'password' => 'not-a-login-secret',
            'role' => $role,
            'status' => AccountStatus::Active,
        ]);
    }

    private function membership(Pharmacy $pharmacy, User $user, StaffRole $role, ?string $license): PharmacyMembership
    {
        return PharmacyMembership::query()->forceCreate([
            'pharmacy_id' => $pharmacy->id,
            'user_id' => $user->id,
            'staff_role' => $role,
            'status' => MembershipStatus::Active,
            'license_number' => $license,
        ]);
    }
}
