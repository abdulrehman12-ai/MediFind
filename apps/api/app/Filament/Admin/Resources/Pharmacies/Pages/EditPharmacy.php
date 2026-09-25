<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Pharmacies\Pages;

use App\Filament\Admin\Resources\Pharmacies\PharmacyResource;
use App\Filament\PanelAlert;
use App\Models\Pharmacy;
use App\Models\PharmacyMembership;
use App\Models\User;
use App\Pharmacy\PharmacyRuleException;
use App\Pharmacy\PharmacyWorkflow;
use App\Staff\StaffInvitation;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Throwable;

final class EditPharmacy extends EditRecord
{
    protected static string $resource = PharmacyResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $record instanceof Pharmacy, 403);

        try {
            return app(PharmacyWorkflow::class)->updateProfile($user, $record, $data);
        } catch (Throwable $exception) {
            PanelAlert::halt($exception);
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('approve')
                ->requiresConfirmation()
                ->action(function (): void {
                    $this->decide(fn (PharmacyWorkflow $workflow, User $admin, Pharmacy $pharmacy) => $workflow->approve($admin, $pharmacy));
                }),
            Action::make('reject')
                ->schema([Textarea::make('reason')->required()])
                ->action(function (array $data): void {
                    $this->decide(fn (PharmacyWorkflow $workflow, User $admin, Pharmacy $pharmacy) => $workflow->reject($admin, $pharmacy, $data['reason']));
                }),
            Action::make('suspend')
                ->schema([Textarea::make('reason')->required()])
                ->action(function (array $data): void {
                    $this->decide(fn (PharmacyWorkflow $workflow, User $admin, Pharmacy $pharmacy) => $workflow->suspend($admin, $pharmacy, $data['reason']));
                }),
            Action::make('reinstate')
                ->requiresConfirmation()
                ->action(function (): void {
                    $this->decide(fn (PharmacyWorkflow $workflow, User $admin, Pharmacy $pharmacy) => $workflow->reinstate($admin, $pharmacy));
                }),
            Action::make('verifyLicense')
                ->label('Verify license')
                ->schema([Textarea::make('evidence')->required()])
                ->action(function (array $data): void {
                    $this->decide(fn (PharmacyWorkflow $workflow, User $admin, Pharmacy $pharmacy) => $workflow->verifyLicense($admin, $pharmacy, $data['evidence']));
                }),
            Action::make('approveLocation')
                ->label('Approve location')
                ->schema([Textarea::make('evidence')->required()])
                ->action(function (array $data): void {
                    $this->decide(fn (PharmacyWorkflow $workflow, User $admin, Pharmacy $pharmacy) => $workflow->approveLocation($admin, $pharmacy, $data['evidence']));
                }),
            Action::make('verifyCredential')
                ->label('Verify pharmacist credential')
                ->schema([
                    Select::make('membership_id')->options(fn (): array => $this->membershipOptions())->required(),
                    Textarea::make('evidence')->required(),
                ])
                ->action(function (array $data): void {
                    $this->guard(function (User $admin, Pharmacy $pharmacy) use ($data): void {
                        $workflow = app(PharmacyWorkflow::class);
                        $membership = $workflow->verifiablePharmacistMemberships($pharmacy)
                            ->firstWhere('id', $data['membership_id']);

                        if (! $membership instanceof PharmacyMembership) {
                            throw new PharmacyRuleException('Choose an active pharmacist with a license number.');
                        }

                        $workflow->verifyCredential($admin, $membership, $data['evidence']);
                    });
                }),
            Action::make('inviteManager')
                ->label('Invite manager')
                ->schema([TextInput::make('email')->email()->required()])
                ->action(function (array $data): void {
                    $this->guard(function (User $admin, Pharmacy $pharmacy) use ($data): void {
                        app(StaffInvitation::class)->inviteManager($admin, $pharmacy, $data['email']);
                        Notification::make()->title('Invitation sent.')->success()->send();
                    });
                }),
            Action::make('invitePharmacist')
                ->label('Invite pharmacist')
                ->schema([TextInput::make('email')->email()->required()])
                ->action(function (array $data): void {
                    $this->guard(function (User $admin, Pharmacy $pharmacy) use ($data): void {
                        app(StaffInvitation::class)->invitePharmacist($admin, $pharmacy, $data['email']);
                        Notification::make()->title('Invitation sent.')->success()->send();
                    });
                }),
            Action::make('assignPharmacist')
                ->label('Assign pharmacist')
                ->schema([
                    TextInput::make('email')->email()->required(),
                    TextInput::make('license_number')->maxLength(100),
                ])
                ->action(function (array $data): void {
                    $this->guard(function (User $admin, Pharmacy $pharmacy) use ($data): void {
                        $staff = User::query()->whereRaw('lower(email) = ?', [strtolower(trim($data['email']))])->first();

                        if (! $staff instanceof User) {
                            throw new PharmacyRuleException('No staff account uses that email. Send an invitation instead.');
                        }

                        app(PharmacyWorkflow::class)->assignPharmacist($admin, $pharmacy, $staff, $data['license_number'] ?? null);
                    });
                }),
            Action::make('recordPharmacistLicense')
                ->label('Record pharmacist license')
                ->schema([
                    Select::make('membership_id')->options(fn (): array => $this->pharmacistOptions())->required(),
                    TextInput::make('license_number')->required()->maxLength(100),
                ])
                ->action(function (array $data): void {
                    $this->guard(function (User $admin, Pharmacy $pharmacy) use ($data): void {
                        $membership = PharmacyMembership::query()
                            ->where('pharmacy_id', $pharmacy->id)
                            ->whereKey($data['membership_id'])
                            ->first();

                        if (! $membership instanceof PharmacyMembership) {
                            throw new PharmacyRuleException('Choose a pharmacist membership.');
                        }

                        app(PharmacyWorkflow::class)->recordPharmacistLicense($admin, $membership, $data['license_number']);
                    });
                }),
        ];
    }

    private function decide(callable $callback): void
    {
        $this->guard(function (User $admin, Pharmacy $pharmacy) use ($callback): void {
            $callback(app(PharmacyWorkflow::class), $admin, $pharmacy);
        });
    }

    private function guard(callable $callback): void
    {
        $user = auth()->user();
        $record = $this->getRecord();
        abort_unless($user instanceof User && $record instanceof Pharmacy, 403);

        try {
            $callback($user, $record);
            $this->record = $record->refresh();
        } catch (Throwable $exception) {
            PanelAlert::halt($exception);
        }
    }

    /**
     * @return array<string, string>
     */
    private function membershipOptions(): array
    {
        $record = $this->getRecord();
        if (! $record instanceof Pharmacy) {
            return [];
        }

        return app(PharmacyWorkflow::class)
            ->verifiablePharmacistMemberships($record)
            ->load('user')
            ->mapWithKeys(fn (PharmacyMembership $membership): array => [
                $membership->id => (string) $membership->user?->email,
            ])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function pharmacistOptions(): array
    {
        $record = $this->getRecord();
        if (! $record instanceof Pharmacy) {
            return [];
        }

        return PharmacyMembership::query()
            ->where('pharmacy_id', $record->id)
            ->where('staff_role', 'pharmacist')
            ->where('status', 'active')
            ->with('user')
            ->get()
            ->mapWithKeys(fn (PharmacyMembership $membership): array => [
                $membership->id => (string) $membership->user?->email,
            ])
            ->all();
    }
}
