<?php

declare(strict_types=1);

namespace App\Filament\Pharmacy\Pages;

use App\Enums\PharmacyStatus;
use App\Enums\StaffRole;
use App\Filament\PanelAlert;
use App\Models\Pharmacy;
use App\Models\User;
use App\Pharmacy\PharmacyAccess;
use App\Pharmacy\PharmacyRuleException;
use App\Staff\StaffInvitation;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Throwable;

/**
 * @property-read Schema $form
 */
final class InviteStaff extends Page
{
    protected string $view = 'filament.pharmacy.invite-staff';

    protected static ?string $navigationLabel = 'Invite staff';

    /**
     * @var array<string, mixed>
     */
    public array $data = [];

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('email')->email()->required(),
        ])->statePath('data');
    }

    public function invite(): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        try {
            $email = (string) ($this->form->getState()['email'] ?? '');
            app(StaffInvitation::class)->inviteInventoryStaff($user, $this->pharmacy(), $email);
            Notification::make()->title('Invitation sent.')->success()->send();
        } catch (Throwable $exception) {
            PanelAlert::notify($exception);
        }
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();
        $tenant = Filament::getTenant();

        if (! $user instanceof User || ! $tenant instanceof Pharmacy) {
            return false;
        }

        if (! in_array($tenant->status, [PharmacyStatus::Pending, PharmacyStatus::Approved], true)) {
            return false;
        }

        try {
            return PharmacyAccess::manager($user, $tenant)->staff_role === StaffRole::Manager;
        } catch (PharmacyRuleException) {
            return false;
        }
    }

    private function pharmacy(): Pharmacy
    {
        $pharmacy = Filament::getTenant();
        abort_unless($pharmacy instanceof Pharmacy, 404);

        return $pharmacy;
    }
}
