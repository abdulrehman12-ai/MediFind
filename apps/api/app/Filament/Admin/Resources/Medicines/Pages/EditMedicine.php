<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Medicines\Pages;

use App\Catalog\MedicineCatalog;
use App\Filament\Admin\Resources\Medicines\MedicineResource;
use App\Filament\PanelAlert;
use App\Models\Medicine;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Throwable;

final class EditMedicine extends EditRecord
{
    protected static string $resource = MedicineResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $record instanceof Medicine, 403);

        try {
            return app(MedicineCatalog::class)->update($user, $record, $data);
        } catch (Throwable $exception) {
            PanelAlert::halt($exception);
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('activate')
                ->requiresConfirmation()
                ->action(function (): void {
                    $user = auth()->user();
                    $record = $this->getRecord();
                    abort_unless($user instanceof User && $record instanceof Medicine, 403);
                    try {
                        app(MedicineCatalog::class)->activate($user, $record);
                    } catch (Throwable $exception) {
                        PanelAlert::halt($exception);
                    }
                }),
            Action::make('deactivate')
                ->requiresConfirmation()
                ->action(function (): void {
                    $user = auth()->user();
                    $record = $this->getRecord();
                    abort_unless($user instanceof User && $record instanceof Medicine, 403);
                    try {
                        app(MedicineCatalog::class)->deactivate($user, $record);
                    } catch (Throwable $exception) {
                        PanelAlert::halt($exception);
                    }
                }),
            Action::make('addAlias')
                ->label('Add alias')
                ->schema([
                    TextInput::make('alias')->required(),
                    Select::make('alias_type')->options([
                        'brand' => 'Brand',
                        'generic' => 'Generic',
                        'spelling' => 'Spelling',
                        'local' => 'Local',
                    ])->required(),
                ])
                ->action(function (array $data): void {
                    $user = auth()->user();
                    $record = $this->getRecord();
                    abort_unless($user instanceof User && $record instanceof Medicine, 403);
                    try {
                        app(MedicineCatalog::class)->addAlias($user, $record, $data['alias'], $data['alias_type']);
                    } catch (Throwable $exception) {
                        PanelAlert::halt($exception);
                    }
                }),
            Action::make('draftUse')
                ->label('Save draft general use')
                ->schema([
                    Textarea::make('general_use')->required(),
                    Textarea::make('safety_disclaimer')->required(),
                    Textarea::make('source_citation')->required(),
                ])
                ->action(function (array $data): void {
                    $user = auth()->user();
                    $record = $this->getRecord();
                    abort_unless($user instanceof User && $record instanceof Medicine, 403);
                    try {
                        app(MedicineCatalog::class)->draftUse(
                            $user,
                            $record,
                            $data['general_use'],
                            $data['safety_disclaimer'],
                            $data['source_citation'],
                        );
                    } catch (Throwable $exception) {
                        PanelAlert::halt($exception);
                    }
                }),
        ];
    }
}
