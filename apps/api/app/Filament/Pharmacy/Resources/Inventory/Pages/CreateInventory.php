<?php

declare(strict_types=1);

namespace App\Filament\Pharmacy\Resources\Inventory\Pages;

use App\Filament\PanelAlert;
use App\Filament\Pharmacy\Resources\Inventory\InventoryResource;
use App\Inventory\InventoryBook;
use App\Models\Pharmacy;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Throwable;

final class CreateInventory extends CreateRecord
{
    protected static string $resource = InventoryResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $user = auth()->user();
        $pharmacy = Filament::getTenant();
        abort_unless($user instanceof User && $pharmacy instanceof Pharmacy, 403);
        $data['quantity_on_hand'] = $data['quantity_on_hand'] === null || $data['quantity_on_hand'] === ''
            ? null
            : (int) $data['quantity_on_hand'];
        $data['unit_price_paisa'] = (int) $data['unit_price_paisa'];
        $data['is_available_for_pickup'] = (bool) ($data['is_available_for_pickup'] ?? false);

        try {
            return app(InventoryBook::class)->save($user, $pharmacy, $data);
        } catch (Throwable $exception) {
            PanelAlert::halt($exception);
        }
    }
}
