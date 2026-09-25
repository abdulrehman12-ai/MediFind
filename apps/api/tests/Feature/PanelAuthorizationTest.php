<?php

namespace Tests\Feature;

use App\Catalog\MedicineCatalog;
use App\Enums\AccountStatus;
use App\Enums\StaffRole;
use App\Enums\UserRole;
use App\Inventory\InventoryBook;
use App\Models\PharmacyInventory;
use App\Models\PharmacyMembership;
use App\Models\User;
use App\Pharmacy\PharmacyWorkflow;
use Database\Seeders\CitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PanelAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CitySeeder::class);
    }

    public function test_panels_reject_the_wrong_role_and_a_stale_session_generation(): void
    {
        $admin = $this->user(UserRole::Admin, 'panel-admin@example.test');
        $staff = $this->user(UserRole::PharmacyStaff, 'panel-staff@example.test');
        $customer = $this->user(UserRole::Customer, 'panel-customer@example.test');
        $customer->forceFill(['phone_e164' => '+923001110000'])->save();
        $admin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $admin->saveAppAuthenticationRecoveryCodes(['recovery-code-one']);

        $this->get('/admin/login')->assertOk();
        $this->get('/pharmacy/login')->assertOk();

        $this->actingAs($customer)->get('/admin')->assertForbidden();
        $this->actingAs($customer)->get('/pharmacy')->assertForbidden();
        $this->actingAs($staff)->get('/admin')->assertForbidden();
        $this->actingAs($admin)->get('/pharmacy')->assertForbidden();

        $staff->forceFill(['status' => AccountStatus::Suspended])->save();
        $this->actingAs($staff)->get('/pharmacy')->assertForbidden();

        $this->actingAs($admin)
            ->withSession(['medifind.session_generation' => 0])
            ->followingRedirects()
            ->get('/admin')
            ->assertOk();

        $admin->forceFill(['session_generation' => 1])->save();
        $this->actingAs($admin)
            ->withSession(['medifind.session_generation' => 0])
            ->get('/admin')
            ->assertForbidden();
    }

    public function test_staff_cannot_open_another_pharmacys_inventory_by_guessing_ids(): void
    {
        $admin = $this->user(UserRole::Admin, 'panel-admin-2@example.test');
        $workflow = app(PharmacyWorkflow::class);
        $pharmacyA = $workflow->create($admin, ['name' => 'Pharmacy A', 'address_text' => 'Susan Road']);
        $pharmacyB = $workflow->create($admin, ['name' => 'Pharmacy B', 'address_text' => 'D Ground']);
        $staffA = $this->user(UserRole::PharmacyStaff, 'staff-a@example.test');
        $staffA->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $staffA->saveAppAuthenticationRecoveryCodes(['recovery-code-two']);
        PharmacyMembership::query()->forceCreate([
            'pharmacy_id' => $pharmacyA->id,
            'user_id' => $staffA->id,
            'staff_role' => StaffRole::Manager,
            'status' => 'active',
        ]);

        $medicine = app(MedicineCatalog::class)->create($admin, [
            'generic_name' => 'Loratadine',
            'dosage_form' => 'Tablet',
            'strength' => '10 mg',
            'manufacturer' => 'Epsilon Labs',
        ]);
        app(MedicineCatalog::class)->activate($admin, $medicine);
        $inventoryB = app(InventoryBook::class)->save($admin, $pharmacyB, [
            'medicine_id' => $medicine->id,
            'stock_status' => 'in_stock',
            'quantity_on_hand' => 2,
            'unit_price_paisa' => 8000,
            'is_available_for_pickup' => true,
        ]);

        $this->assertFalse($staffA->can('view', $inventoryB));
        $this->assertFalse($staffA->can('update', $inventoryB));
        $this->assertFalse($staffA->can('delete', $inventoryB));
        $this->assertFalse($staffA->can('deleteAny', PharmacyInventory::class));

        $this->actingAs($staffA)
            ->withSession(['medifind.session_generation' => 0])
            ->get('/pharmacy/'.$pharmacyB->id.'/inventory/'.$inventoryB->id.'/edit')
            ->assertNotFound();

        $this->actingAs($staffA)
            ->withSession(['medifind.session_generation' => 0])
            ->get('/pharmacy/'.$pharmacyA->id.'/status')
            ->assertOk();
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
