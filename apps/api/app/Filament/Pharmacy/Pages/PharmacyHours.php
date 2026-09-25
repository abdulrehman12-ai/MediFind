<?php

declare(strict_types=1);

namespace App\Filament\Pharmacy\Pages;

use App\Filament\PanelAlert;
use App\Models\Pharmacy;
use App\Models\PharmacyOperatingHour;
use App\Models\User;
use App\Pharmacy\OperatingHoursWriter;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Throwable;

/**
 * @property-read Schema $form
 */
final class PharmacyHours extends Page
{
    protected string $view = 'filament.pharmacy.hours';

    protected static ?string $navigationLabel = 'Hours';

    /**
     * @var array<string, mixed>
     */
    public array $data = [];

    public function mount(): void
    {
        $pharmacy = $this->pharmacy();
        $this->data = [
            'intervals' => $pharmacy->operatingHours->map(fn (PharmacyOperatingHour $hour): array => [
                'day_of_week' => $hour->day_of_week,
                'interval_kind' => $hour->interval_kind->value,
                'opens_at' => $hour->opens_at,
                'closes_at' => $hour->closes_at,
            ])->all(),
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Repeater::make('intervals')->schema([
                Select::make('day_of_week')->options([
                    1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday',
                ])->required(),
                Select::make('interval_kind')->options([
                    'same_day' => 'Same day',
                    'overnight' => 'Overnight',
                    'all_day' => 'Open 24 hours',
                ])->required(),
                TextInput::make('opens_at'),
                TextInput::make('closes_at'),
            ]),
        ])->statePath('data');
    }

    public function save(): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        try {
            $intervals = $this->form->getState()['intervals'] ?? [];
            app(OperatingHoursWriter::class)->replace($user, $this->pharmacy(), $intervals);
        } catch (Throwable $exception) {
            PanelAlert::notify($exception);
        }
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();
        $tenant = Filament::getTenant();

        return $user instanceof User && $tenant instanceof Pharmacy && $user->can('update', $tenant);
    }

    private function pharmacy(): Pharmacy
    {
        $pharmacy = Filament::getTenant();
        abort_unless($pharmacy instanceof Pharmacy, 404);

        return $pharmacy;
    }
}
