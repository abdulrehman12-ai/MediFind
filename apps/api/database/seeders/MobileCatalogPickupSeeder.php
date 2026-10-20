<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Catalog\MedicineCatalog;
use App\Enums\AccountStatus;
use App\Enums\MedicineStatus;
use App\Enums\UserRole;
use App\Inventory\InventoryBook;
use App\Models\Medicine;
use App\Models\Pharmacy;
use App\Models\PharmacyInventory;
use App\Models\User;
use App\Pharmacy\PharmacyWorkflow;
use App\Support\IsolatedHttpRuntime;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class MobileCatalogPickupSeeder extends Seeder
{
    public const OTC_GENERIC = 'Catalogpickupotc';

    public const RX_GENERIC = 'Catalogpickuprx';

    public const PHARMACY_NAME = 'Mobile HTTP Test Pharmacy';

    public function run(): void
    {
        IsolatedHttpRuntime::assert();

        $this->call(CitySeeder::class);

        $admin = User::query()->where('email', 'mobile-http-admin@example.test')->first();

        if (! $admin instanceof User) {
            $admin = User::query()->forceCreate([
                'public_id' => (string) Str::uuid(),
                'email' => 'mobile-http-admin@example.test',
                'phone_e164' => null,
                'password' => 'not-a-login-secret',
                'role' => UserRole::Admin,
                'status' => AccountStatus::Active,
            ]);
        }

        $catalog = app(MedicineCatalog::class);
        $otc = $this->medicine($catalog, $admin, self::OTC_GENERIC, 'Tablet', '500 mg', false);
        $rx = $this->medicine($catalog, $admin, self::RX_GENERIC, 'Tablet', '10 mg', true);

        $workflow = app(PharmacyWorkflow::class);
        $pharmacy = Pharmacy::query()->where('name', self::PHARMACY_NAME)->first();

        if (! $pharmacy instanceof Pharmacy) {
            $pharmacy = $workflow->create($admin, [
                'name' => self::PHARMACY_NAME,
                'address_text' => 'Susan Road, Faisalabad',
                'latitude' => '31.450400',
                'longitude' => '73.135000',
                'license_number' => 'LIC-MOBILEHTTP1',
                'phone_e164' => '+923009990088',
            ]);
            $workflow->verifyLicense($admin, $pharmacy, 'Isolated HTTP fixture license');
            $workflow->approveLocation($admin, $pharmacy, 'Isolated HTTP fixture pin');
            $workflow->approve($admin, $pharmacy->refresh());
            $pharmacy = $pharmacy->refresh();
        }

        $this->stock($admin, $pharmacy, $otc);
        $this->stock($admin, $pharmacy, $rx);

        $otcCount = DB::table('medicines')->where('generic_name', self::OTC_GENERIC)->where('prescription_required', false)->count();
        $rxCount = DB::table('medicines')->where('generic_name', self::RX_GENERIC)->where('prescription_required', true)->count();

        if ($otcCount < 1 || $rxCount < 1) {
            throw new RuntimeException('Mobile catalog pickup seed did not store required medicines.');
        }
    }

    private function medicine(MedicineCatalog $catalog, User $admin, string $generic, string $form, string $strength, bool $prescriptionRequired): Medicine
    {
        $existing = Medicine::query()
            ->where('generic_name', $generic)
            ->where('dosage_form', $form)
            ->where('strength', $strength)
            ->first();

        if ($existing instanceof Medicine) {
            if ($existing->status !== MedicineStatus::Active) {
                $catalog->activate($admin, $existing);
            }

            return $existing->refresh();
        }

        $created = $catalog->create($admin, [
            'generic_name' => $generic,
            'dosage_form' => $form,
            'strength' => $strength,
            'manufacturer' => 'Mobile HTTP Labs',
            'brand_name' => null,
            'prescription_required' => $prescriptionRequired,
        ]);
        $catalog->activate($admin, $created);

        return $created->refresh();
    }

    private function stock(User $admin, Pharmacy $pharmacy, Medicine $medicine): void
    {
        $existing = PharmacyInventory::query()
            ->where('pharmacy_id', $pharmacy->id)
            ->where('medicine_id', $medicine->id)
            ->first();

        $payload = [
            'medicine_id' => (string) $medicine->id,
            'stock_status' => 'in_stock',
            'quantity_on_hand' => 12,
            'unit_price_paisa' => 1500,
            'is_available_for_pickup' => true,
        ];

        if ($existing instanceof PharmacyInventory) {
            $payload['version'] = $existing->version;
        }

        app(InventoryBook::class)->save($admin, $pharmacy, $payload);
    }
}
