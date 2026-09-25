<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Pharmacies\Pages;

use App\Filament\Admin\Resources\Pharmacies\PharmacyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListPharmacies extends ListRecords
{
    protected static string $resource = PharmacyResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
