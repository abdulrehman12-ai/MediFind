<?php

declare(strict_types=1);

namespace App\Filament\Pharmacy\Pages;

use App\Audit\AuditRecorder;
use App\Enums\CredentialStatus;
use App\Enums\MembershipStatus;
use App\Enums\StaffRole;
use App\Exceptions\ApiProblem;
use App\Models\Pharmacy;
use App\Models\PharmacyMembership;
use App\Models\Prescription;
use App\Models\User;
use App\Prescriptions\PrescriptionDownloadHeaders;
use App\Prescriptions\PrescriptionObjectStore;
use App\Prescriptions\PrescriptionWorkflow;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class ReviewPrescriptions extends Page
{
    protected string $view = 'filament.pharmacy.review-prescriptions';

    protected static ?string $navigationLabel = 'Prescription reviews';

    protected static ?string $slug = 'prescription-reviews';

    public ?string $selected = null;

    public string $reason = '';

    public static function canAccess(): bool
    {
        $user = auth()->user();
        $pharmacy = Filament::getTenant();

        return $user instanceof User
            && $pharmacy instanceof Pharmacy
            && self::membership($user, $pharmacy) instanceof PharmacyMembership;
    }

    public function download(string $publicId): StreamedResponse
    {
        $prescription = $this->authorized($publicId);
        $bytes = app(PrescriptionObjectStore::class)->get($prescription->object_key);
        app(AuditRecorder::class)->record(auth()->user() instanceof User ? auth()->user() : null, 'prescription_file_read', 'prescription', (string) $prescription->id, (string) $prescription->pharmacy_id);

        $headers = PrescriptionDownloadHeaders::forMime($prescription->mime_type);

        return response()->streamDownload(function () use ($bytes): void {
            echo $bytes;
        }, null, $headers);
    }

    public function verifyPrescription(string $publicId): void
    {
        $this->selected = $publicId;
        $this->decide('verified', null);
    }

    public function rejectPrescription(string $publicId): void
    {
        $this->selected = $publicId;
        $this->decide('rejected', $this->reason);
    }

    /**
     * @return Collection<int, Prescription>
     */
    public function pending(): Collection
    {
        $pharmacy = $this->pharmacy();

        return Prescription::query()
            ->where('pharmacy_id', $pharmacy->id)
            ->where('share_state', 'pending')
            ->whereNull('deleted_at')
            ->whereColumn('shared_content_version', 'content_version')
            ->latest()
            ->get();
    }

    private function decide(string $decision, ?string $reason): void
    {
        $pharmacy = $this->pharmacy();
        $user = auth()->user();

        if (! $user instanceof User || $this->selected === null) {
            Notification::make()->title('Choose a shared prescription.')->danger()->send();

            return;
        }

        try {
            $prescription = $this->authorized($this->selected);
            app(PrescriptionWorkflow::class)->review($user, $pharmacy, $prescription, $prescription->content_version, $decision, $reason);
            $this->selected = null;
            $this->reason = '';
            Notification::make()->title('Review recorded for this prescription version.')->success()->send();
        } catch (Throwable $exception) {
            Notification::make()->title($exception instanceof ApiProblem ? $exception->getMessage() : 'The review could not be saved.')->danger()->send();
        }
    }

    private function authorized(string $publicId): Prescription
    {
        $user = auth()->user();
        $pharmacy = $this->pharmacy();
        $prescription = Prescription::query()->where('public_id', $publicId)->first();

        if (! $user instanceof User || ! $prescription instanceof Prescription) {
            throw new ApiProblem('not_found', 'The requested resource was not found.', 404);
        }

        app(PrescriptionWorkflow::class)->pharmacistCanRead($user, $pharmacy, $prescription);

        return $prescription;
    }

    private function pharmacy(): Pharmacy
    {
        $pharmacy = Filament::getTenant();

        if (! $pharmacy instanceof Pharmacy) {
            throw new ApiProblem('forbidden', 'This action is unauthorized.', 403);
        }

        return $pharmacy;
    }

    private static function membership(User $user, Pharmacy $pharmacy): ?PharmacyMembership
    {
        if (! $user->isOperational() || ! $pharmacy->publiclyEligible()) {
            return null;
        }

        $membership = PharmacyMembership::query()
            ->where('pharmacy_id', $pharmacy->id)
            ->where('user_id', $user->id)
            ->where('status', MembershipStatus::Active->value)
            ->where('staff_role', StaffRole::Pharmacist->value)
            ->where('credential_status', CredentialStatus::Verified->value)
            ->first();

        return $membership instanceof PharmacyMembership ? $membership : null;
    }
}
