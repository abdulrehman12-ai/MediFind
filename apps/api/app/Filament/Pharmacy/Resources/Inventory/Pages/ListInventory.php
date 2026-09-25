<?php

declare(strict_types=1);

namespace App\Filament\Pharmacy\Resources\Inventory\Pages;

use App\Filament\Pharmacy\Resources\Inventory\InventoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListInventory extends ListRecords
{
    protected static string $resource = InventoryResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
