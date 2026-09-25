<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Pharmacies\Pages;

use App\Filament\Admin\Resources\Pharmacies\PharmacyResource;
use App\Filament\PanelAlert;
use App\Models\User;
use App\Pharmacy\PharmacyWorkflow;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Throwable;

final class CreatePharmacy extends CreateRecord
{
    protected static string $resource = PharmacyResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        try {
            return app(PharmacyWorkflow::class)->create($user, $data);
        } catch (Throwable $exception) {
            PanelAlert::halt($exception);
        }
    }
}
