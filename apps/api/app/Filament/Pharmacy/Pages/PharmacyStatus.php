<?php

declare(strict_types=1);

namespace App\Filament\Pharmacy\Pages;

use App\Models\Pharmacy;
use Filament\Facades\Filament;
use Filament\Pages\Page;

final class PharmacyStatus extends Page
{
    protected string $view = 'filament.pharmacy.status';

    protected static ?string $navigationLabel = 'Status';

    protected static ?string $slug = 'status';

    public function pharmacy(): ?Pharmacy
    {
        $pharmacy = Filament::getTenant();

        return $pharmacy instanceof Pharmacy ? $pharmacy : null;
    }
}
