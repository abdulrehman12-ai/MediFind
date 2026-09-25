<?php

namespace Tests\Feature;

use App\Catalog\MedicineCatalog;
use App\Enums\AccountStatus;
use App\Enums\StaffRole;
use App\Enums\UserRole;
use App\Filament\Pharmacy\Pages\PharmacyHours;
use App\Filament\Pharmacy\Resources\Inventory\Pages\EditInventory;
use App\Inventory\InventoryBook;
use App\Models\PharmacyMembership;
use App\Models\User;
use App\Pharmacy\PharmacyWorkflow;
use Database\Seeders\CitySeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class Phase2PanelErrorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CitySeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('pharmacy'));
    }

    public function test_stale_inventory_and_overlapping_hours_are_panel_notifications(): void
    {
        $admin = $this->user(UserRole::Admin, 'panel-error-admin@example.test');
        $pharmacy = app(PharmacyWorkflow::class)->create($admin, [
            'name' => 'Panel Pharmacy',
            'address_text' => 'Susan Road',
        ]);
        $staff = $this->user(UserRole::PharmacyStaff, 'panel-error-staff@example.test');
        $staff->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $staff->saveAppAuthenticationRecoveryCodes(['recovery-code-panel']);
        PharmacyMembership::query()->forceCreate([
            'pharmacy_id' => $pharmacy->id,
            'user_id' => $staff->id,
            'staff_role' => StaffRole::Manager,
            'status' => 'active',
        ]);
        $medicine = app(MedicineCatalog::class)->create($admin, [
            'generic_name' => 'Cetirizine',
            'dosage_form' => 'Tablet',
            'strength' => '10 mg',
            'manufacturer' => 'Zeta Labs',
        ]);
        app(MedicineCatalog::class)->activate($admin, $medicine);
        $inventory = app(InventoryBook::class)->save($staff, $pharmacy, [
            'medicine_id' => $medicine->id,
            'stock_status' => 'in_stock',
            'quantity_on_hand' => 5,
            'unit_price_paisa' => 1500,
            'is_available_for_pickup' => true,
        ]);
        app(InventoryBook::class)->save($staff, $pharmacy, [
            'medicine_id' => $medicine->id,
            'stock_status' => 'in_stock',
            'quantity_on_hand' => 6,
            'unit_price_paisa' => 1500,
            'is_available_for_pickup' => true,
            'version' => 1,
        ]);

        $this->actingAs($staff)->withSession(['medifind.session_generation' => 0]);
        Filament::setTenant($pharmacy);

        Livewire::test(EditInventory::class, ['record' => $inventory->getKey()])
            ->fillForm([
                'medicine_id' => $medicine->id,
                'stock_status' => 'in_stock',
                'quantity_on_hand' => 9,
                'unit_price_paisa' => 1500,
                'is_available_for_pickup' => true,
                'version' => 1,
            ])
            ->call('save')
            ->assertNotified('The inventory record was updated by someone else. Reload it and try again.');

        $this->assertSame(2, $inventory->refresh()->version);
        $this->assertSame(6, $inventory->quantity_on_hand);

        Livewire::test(PharmacyHours::class)
            ->fillForm([
                'intervals' => [
                    ['day_of_week' => 1, 'interval_kind' => 'same_day', 'opens_at' => '09:00', 'closes_at' => '18:00'],
                    ['day_of_week' => 1, 'interval_kind' => 'same_day', 'opens_at' => '17:00', 'closes_at' => '20:00'],
                ],
            ])
            ->call('save')
            ->assertNotified('Opening intervals overlap.');

        $this->assertSame(0, $pharmacy->operatingHours()->count());
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
