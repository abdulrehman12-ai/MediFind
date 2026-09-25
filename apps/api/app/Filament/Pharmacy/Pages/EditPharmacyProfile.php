<?php

declare(strict_types=1);

namespace App\Filament\Pharmacy\Pages;

use App\Filament\PanelAlert;
use App\Models\Pharmacy;
use App\Models\User;
use App\Pharmacy\PharmacyWorkflow;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Tenancy\EditTenantProfile;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Throwable;

final class EditPharmacyProfile extends EditTenantProfile
{
    public static function getLabel(): string
    {
        return 'Pharmacy profile';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(180),
            Textarea::make('address_text')->required()->maxLength(300),
            TextInput::make('phone_e164')->maxLength(20),
            TextInput::make('license_number')->maxLength(100),
            TextInput::make('latitude'),
            TextInput::make('longitude'),
            Textarea::make('pickup_instructions'),
            Textarea::make('appeal_note')->label('Appeal note'),
        ]);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $record instanceof Pharmacy, 403);
        try {
            $workflow = app(PharmacyWorkflow::class);
            $updated = $workflow->updateProfile($user, $record, $data);

            if (filled($data['appeal_note'] ?? null)) {
                $workflow->appeal($user, $updated, (string) $data['appeal_note']);
            }

            return $updated->refresh();
        } catch (Throwable $exception) {
            PanelAlert::halt($exception);
        }
    }
}
