<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Audit\AuditRecorder;
use App\Enums\MedicineStatus;
use App\Exceptions\ApiProblem;
use App\Http\Controllers\Controller;
use App\Http\Requests\CorrectPrescriptionItemRequest;
use App\Http\Requests\SharePrescriptionRequest;
use App\Models\Medicine;
use App\Models\PrescriptionItem;
use App\Models\User;
use App\Prescriptions\PrescriptionDownloadHeaders;
use App\Prescriptions\PrescriptionObjectStore;
use App\Prescriptions\PrescriptionWorkflow;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PrescriptionController extends Controller
{
    public function __construct(private readonly PrescriptionWorkflow $workflow) {}

    public function index(Request $request): JsonResponse
    {
        $customer = $this->customer($request);
        $this->readLimit($customer);
        $rows = $customer->prescriptions()
            ->whereNull('deleted_at')
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn ($row) => $this->workflow->present($row))
            ->all();

        return ApiResponse::data($request, $rows);
    }

    public function store(Request $request): JsonResponse
    {
        $customer = $this->customer($request);
        $file = $request->file('file');

        if (! $file instanceof UploadedFile) {
            throw new ApiProblem('invalid_prescription_file', 'Choose a JPEG, PNG, or PDF prescription.', 422);
        }

        $prescription = $request->filled('prescription_id')
            ? $this->workflow->replaceFile($customer, $this->workflow->findOwned($customer, (string) $request->input('prescription_id')), $file)
            : $this->workflow->upload($customer, $file);

        return ApiResponse::data($request, $this->workflow->present($prescription), 201);
    }

    public function show(Request $request, string $prescription): JsonResponse
    {
        $customer = $this->customer($request);
        $this->readLimit($customer);

        return ApiResponse::data($request, $this->workflow->present($this->workflow->findOwned($customer, $prescription)));
    }

    public function file(Request $request, string $prescription): StreamedResponse
    {
        $customer = $this->customer($request);
        $this->readLimit($customer);
        $row = $this->workflow->findOwned($customer, $prescription);

        return $this->stream($request, $row->object_key, $row->mime_type);
    }

    public function correct(CorrectPrescriptionItemRequest $request, string $prescription, string $item): JsonResponse
    {
        $customer = $this->customer($request);
        $row = $this->workflow->findOwned($customer, $prescription);
        $line = PrescriptionItem::query()->where('public_id', $item)->where('prescription_id', $row->id)->first();

        if (! $line instanceof PrescriptionItem) {
            throw new ApiProblem('not_found', 'The requested resource was not found.', 404);
        }

        $updated = $this->workflow->correct(
            $customer,
            $row,
            $line,
            $request->exists('text') ? $request->input('text') : null,
            $request->input('selected_medicine_id'),
            $request->boolean('selected'),
        );

        return ApiResponse::data($request, $this->workflow->present($updated));
    }

    public function share(SharePrescriptionRequest $request, string $prescription): JsonResponse
    {
        $customer = $this->customer($request);
        $row = $this->workflow->findOwned($customer, $prescription);
        $updated = $this->workflow->share($customer, $row, (string) $request->input('mode'), (string) $request->input('pharmacy_id'));

        return ApiResponse::data($request, $this->workflow->present($updated));
    }

    public function withdraw(Request $request, string $prescription): JsonResponse
    {
        $customer = $this->customer($request);
        $row = $this->workflow->findOwned($customer, $prescription);

        return ApiResponse::data($request, $this->workflow->present($this->workflow->withdraw($customer, $row)));
    }

    public function destroy(Request $request, string $prescription): JsonResponse
    {
        $customer = $this->customer($request);
        $erasure = $this->workflow->delete($customer, $this->workflow->findOwned($customer, $prescription));

        return ApiResponse::data($request, [
            'deleted' => true,
            'file_erasure' => $erasure,
        ]);
    }

    public function generalUse(Request $request, string $medicine): JsonResponse
    {
        $customer = $this->customer($request);
        $this->readLimit($customer);
        $found = Medicine::query()->whereKey($medicine)->where('status', MedicineStatus::Active->value)->where('controlled_drug', false)->first();

        if (! $found instanceof Medicine) {
            throw new ApiProblem('not_found', 'The requested resource was not found.', 404);
        }

        return ApiResponse::data($request, $this->workflow->generalUse((string) $found->id));
    }

    private function customer(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new ApiProblem('unauthenticated', 'Unauthenticated.', 401);
        }

        return $user;
    }

    private function readLimit(User $customer): void
    {
        $key = 'prescription:read:'.$customer->id;
        $limit = (int) config('medifind.prescriptions.read_limit');

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            throw new ApiProblem('prescription_rate_limited', 'Too many prescription requests.', 429, [], RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key, 60);
    }

    private function stream(Request $request, string $key, string $mime): StreamedResponse
    {
        $bytes = app(PrescriptionObjectStore::class)->get($key);
        app(AuditRecorder::class)->record($request->user() instanceof User ? $request->user() : null, 'prescription_file_read', 'prescription', (string) $request->route('prescription'), null);

        return response()->stream(function () use ($bytes): void {
            echo $bytes;
        }, 200, PrescriptionDownloadHeaders::forMime($mime));
    }
}
