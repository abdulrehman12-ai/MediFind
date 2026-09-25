<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Medicines\Pages;

use App\Catalog\MedicineCatalog;
use App\Filament\Admin\Resources\Medicines\MedicineResource;
use App\Filament\PanelAlert;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Throwable;

final class CreateMedicine extends CreateRecord
{
    protected static string $resource = MedicineResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        try {
            return app(MedicineCatalog::class)->create($user, $data);
        } catch (Throwable $exception) {
            PanelAlert::halt($exception);
        }
    }
}
