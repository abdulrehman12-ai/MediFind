<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Medicines\Pages;

use App\Filament\Admin\Resources\Medicines\MedicineResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListMedicines extends ListRecords
{
    protected static string $resource = MedicineResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
