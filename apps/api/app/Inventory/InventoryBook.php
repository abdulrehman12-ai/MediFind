<?php

declare(strict_types=1);

namespace App\Inventory;

use App\Audit\AuditRecorder;
use App\Enums\MedicineStatus;
use App\Enums\StockStatus;
use App\Enums\UserRole;
use App\Models\Medicine;
use App\Models\Pharmacy;
use App\Models\PharmacyInventory;
use App\Models\User;
use App\Pharmacy\PharmacyAccess;
use App\Pharmacy\PharmacyRuleException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class InventoryBook
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @param  array{medicine_id: string, stock_status: string, quantity_on_hand: ?int, unit_price_paisa: int, is_available_for_pickup: bool, version?: int}  $input
     */
    public function save(User $actor, Pharmacy $pharmacy, array $input): PharmacyInventory
    {
        if ($actor->role === UserRole::Admin) {
            PharmacyAccess::admin($actor);
        } else {
            PharmacyAccess::inventoryEditor($actor, $pharmacy);
        }

        $medicine = Medicine::query()->whereKey($input['medicine_id'])->first();
        if (! $medicine instanceof Medicine || $medicine->status !== MedicineStatus::Active || ! $medicine->identityIsComplete()) {
            throw new PharmacyRuleException('Inventory can use only an active medicine with a complete identity.');
        }

        if ($input['unit_price_paisa'] < 0) {
            throw new InvalidInventory('Price cannot be negative.');
        }

        $status = StockStatus::from($input['stock_status']);
        $pickup = $medicine->controlled_drug ? false : $input['is_available_for_pickup'];
        if ($medicine->controlled_drug && $input['is_available_for_pickup']) {
            throw new InvalidInventory('Controlled medicines are not available for pickup.');
        }

        $verified = $status !== StockStatus::Unknown;
        InventoryRules::assertState($status, $input['quantity_on_hand'], $verified, $pickup, $medicine->controlled_drug);

        $values = [
            'stock_status' => $status->value,
            'quantity_on_hand' => $input['quantity_on_hand'],
            'unit_price_paisa' => $input['unit_price_paisa'],
            'is_available_for_pickup' => $pickup,
            'last_verified_at' => $verified ? now()->utc() : null,
            'updated_by' => $actor->id,
        ];

        try {
            return DB::transaction(function () use ($actor, $pharmacy, $medicine, $input, $status, $values): PharmacyInventory {
                PharmacyAccess::authorizeInventoryChange($actor, $pharmacy);
                $medicine = Medicine::query()->whereKey($medicine->id)->first();

                if (! $medicine instanceof Medicine || $medicine->status !== MedicineStatus::Active || ! $medicine->identityIsComplete()) {
                    throw new PharmacyRuleException('Inventory can use only an active medicine with a complete identity.');
                }

                if ($medicine->controlled_drug && $values['is_available_for_pickup'] === true) {
                    throw new InvalidInventory('Controlled medicines are not available for pickup.');
                }

                $existing = PharmacyInventory::query()
                    ->where('pharmacy_id', $pharmacy->id)
                    ->where('medicine_id', $medicine->id)
                    ->lockForUpdate()
                    ->first();

                if (! $existing instanceof PharmacyInventory) {
                    $created = new PharmacyInventory;
                    $created->forceFill($values + [
                        'pharmacy_id' => $pharmacy->id,
                        'medicine_id' => $medicine->id,
                        'version' => 1,
                    ]);
                    $created->save();
                    $this->audit->record($actor, 'inventory_saved', 'pharmacy_inventory', (string) $created->id, (string) $pharmacy->id, [
                        'version' => 1,
                        'stock_status' => $status->value,
                        'unit_price_paisa' => $input['unit_price_paisa'],
                    ]);

                    return $created;
                }

                $expected = $input['version'] ?? null;
                if ($expected === null) {
                    throw new InventoryConflict;
                }

                $updated = DB::table('pharmacy_inventory')
                    ->where('id', $existing->id)
                    ->where('version', $expected)
                    ->update($values + [
                        'version' => $expected + 1,
                        'updated_at' => now(),
                    ]);

                if ($updated !== 1) {
                    throw new InventoryConflict;
                }

                $this->audit->record($actor, 'inventory_saved', 'pharmacy_inventory', (string) $existing->id, (string) $pharmacy->id, [
                    'version' => $expected + 1,
                    'stock_status' => $status->value,
                    'unit_price_paisa' => $input['unit_price_paisa'],
                ]);

                return $existing->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            throw new InventoryConflict;
        }
    }
}
