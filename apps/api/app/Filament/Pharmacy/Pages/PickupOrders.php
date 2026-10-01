<?php

declare(strict_types=1);

namespace App\Filament\Pharmacy\Pages;

use App\Audit\AuditRecorder;
use App\Enums\CredentialStatus;
use App\Enums\MembershipStatus;
use App\Enums\OrderRequestMode;
use App\Enums\OrderStatus;
use App\Enums\StaffRole;
use App\Exceptions\ApiProblem;
use App\Models\Order;
use App\Models\OrderQuote;
use App\Models\Pharmacy;
use App\Models\PharmacyMembership;
use App\Models\Prescription;
use App\Models\User;
use App\Orders\FulfillmentAccess;
use App\Orders\FulfillmentService;
use App\Orders\OrderPrescriptionGuard;
use App\Orders\QuoteService;
use App\Prescriptions\PrescriptionDownloadHeaders;
use App\Prescriptions\PrescriptionObjectStore;
use App\Support\TestingBarrier;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class PickupOrders extends Page
{
    private const QUEUE_PAGE_SIZE = 50;

    protected string $view = 'filament.pharmacy.pickup-orders';

    protected static ?string $navigationLabel = 'Pickup orders';

    protected static ?string $slug = 'pickup-orders';

    public string $reason = '';

    public string $startsAt = '';

    public string $endsAt = '';

    public string $cashPaisa = '';

    public string $medicineId = '';

    public string $quantity = '1';

    public string $unitPrice = '';

    public ?string $quoteOrderId = null;

    public int $queuePage = 1;

    /**
     * @var list<array{medicine_id: string, quantity: int, unit_price_paisa: int}>
     */
    public array $draftLines = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();
        $pharmacy = Filament::getTenant();

        return $user instanceof User
            && $pharmacy instanceof Pharmacy
            && self::membership($user, $pharmacy) instanceof PharmacyMembership;
    }

    public function updatingQuoteOrderId(mixed $value): void
    {
        $next = is_string($value) ? strtolower($value) : null;
        $current = $this->quoteOrderId === null ? null : strtolower($this->quoteOrderId);

        if ($next !== $current) {
            $this->clearDraft();
        }
    }

    public function selectOrder(string $publicId): void
    {
        $order = $this->findPharmacyOrder($publicId);

        if (! $order instanceof Order) {
            Notification::make()->title('That order is not in this pharmacy queue.')->danger()->send();

            return;
        }

        if ($this->quoteOrderId === null || strtolower($this->quoteOrderId) !== strtolower($order->public_id)) {
            $this->clearDraft();
        }

        $this->quoteOrderId = $order->public_id;
    }

    public function addLine(): void
    {
        $order = $this->quoteOrderId === null ? null : $this->findPharmacyOrder($this->quoteOrderId);

        if (! $order instanceof Order) {
            Notification::make()->title('Choose an order before adding a reviewed line.')->danger()->send();

            return;
        }

        $limit = $this->lineLimit();

        if (count($this->draftLines) >= $limit) {
            Notification::make()->title('A quote can contain at most '.$limit.' medicines.')->danger()->send();

            return;
        }

        if ($this->medicineId === '' || ! ctype_digit($this->quantity) || ! ctype_digit($this->unitPrice)) {
            Notification::make()->title('Enter a medicine, quantity, and unit price.')->danger()->send();

            return;
        }

        $this->draftLines[] = [
            'medicine_id' => strtolower($this->medicineId),
            'quantity' => (int) $this->quantity,
            'unit_price_paisa' => (int) $this->unitPrice,
        ];
        $this->medicineId = '';
        $this->quantity = '1';
        $this->unitPrice = '';
    }

    public function discardDraft(): void
    {
        $this->clearDraft();
    }

    public function proposeQuote(string $publicId): void
    {
        $publicId = strtolower($publicId);

        if ($this->quoteOrderId === null || strtolower($this->quoteOrderId) !== $publicId) {
            Notification::make()->title('Choose that order before sending its quote.')->danger()->send();

            return;
        }

        $lines = $this->draftLines;
        $this->run(function (User $user, Pharmacy $pharmacy) use ($publicId, $lines): void {
            $order = $this->findPharmacyOrder($publicId);

            if (! $order instanceof Order || strtolower($order->public_id) !== strtolower((string) $this->quoteOrderId)) {
                throw new ApiProblem('not_found', 'The requested resource was not found.', 404);
            }

            app(QuoteService::class)->propose($user, $pharmacy, $order->public_id, $lines);
            $this->clearDraft();
        }, 'Quote sent to the customer.');
    }

    public function confirmOrder(string $publicId): void
    {
        $this->run(function (User $user, Pharmacy $pharmacy) use ($publicId): void {
            app(FulfillmentService::class)->confirm($user, $pharmacy, $publicId);
        }, 'Order confirmed.');
    }

    public function rejectOrder(string $publicId): void
    {
        $this->run(function (User $user, Pharmacy $pharmacy) use ($publicId): void {
            app(FulfillmentService::class)->reject($user, $pharmacy, $publicId, $this->reason);
            $this->reason = '';
        }, 'Order rejected.');
    }

    public function markReady(string $publicId): void
    {
        $this->run(function (User $user, Pharmacy $pharmacy) use ($publicId): void {
            app(FulfillmentService::class)->markReady($user, $pharmacy, $publicId, $this->startsAt, $this->endsAt);
        }, 'Pickup window saved.');
    }

    public function collectOrder(string $publicId): void
    {
        $this->run(function (User $user, Pharmacy $pharmacy) use ($publicId): void {
            if (! ctype_digit($this->cashPaisa)) {
                throw ValidationException::withMessages(['cash' => ['Enter the cash amount in paisa, including zero when nothing is due.']]);
            }

            app(FulfillmentService::class)->collect($user, $pharmacy, $publicId, (int) $this->cashPaisa);
            $this->cashPaisa = '';
        }, 'Collection recorded.');
    }

    public function closeNoShow(string $publicId): void
    {
        $this->run(function (User $user, Pharmacy $pharmacy) use ($publicId): void {
            app(FulfillmentService::class)->notCollected($user, $pharmacy, $publicId, $this->reason);
            $this->reason = '';
        }, 'No-show recorded.');
    }

    /**
     * Phase 6 order-file exception. Phase 4 still refuses a verified share on the review screen.
     *
     * Allowed only for a pending_pharmacy_review full-image order whose prescription_usable
     * flag is true and whose share is currently verified for this pharmacy, mode, and content
     * version. Confirmed, ready, and every terminal status are denied. A pending share stays
     * on the Phase 4 review screen.
     *
     * The pharmacy, acting user, and membership are locked, then the prescription, then the
     * order. The decision uses those locked rows. Locks are released before storage is called,
     * so a withdrawal or membership change that commits first is denied, and one waiting on
     * the prescription lock cannot change the share until this decision commits. A withdrawal
     * that commits after the decision, while storage is still being read, is not undone; the
     * next read is denied. Storage failure returns no bytes and records no prescription_file_read.
     */
    public function viewPrescription(string $publicId): StreamedResponse
    {
        $user = auth()->user();

        try {
            $pharmacy = $this->pharmacy();
        } catch (ApiProblem) {
            throw new ApiProblem('not_found', 'The requested resource was not found.', 404);
        }

        if (! $user instanceof User) {
            throw new ApiProblem('not_found', 'The requested resource was not found.', 404);
        }

        try {
            $authorized = DB::transaction(function () use ($user, $pharmacy, $publicId): array {
                try {
                    app(FulfillmentAccess::class)->lock($user, $pharmacy);
                } catch (ApiProblem) {
                    throw new ApiProblem('not_found', 'The requested resource was not found.', 404);
                }

                $peek = Order::query()
                    ->where('pharmacy_id', $pharmacy->id)
                    ->where('public_id', strtolower($publicId))
                    ->first();

                if (! $peek instanceof Order || $peek->prescription_id === null) {
                    throw new ApiProblem('not_found', 'The requested resource was not found.', 404);
                }

                $prescription = Prescription::query()->whereKey($peek->prescription_id)->lockForUpdate()->first();
                TestingBarrier::wait('after_order_file_prescription_lock');
                $order = Order::query()
                    ->whereKey($peek->id)
                    ->where('pharmacy_id', $pharmacy->id)
                    ->lockForUpdate()
                    ->first();

                if (! $order instanceof Order || ! $prescription instanceof Prescription || ! $this->orderFileAllowed($order, $prescription)) {
                    throw new ApiProblem('not_found', 'The requested resource was not found.', 404);
                }

                return [
                    'prescription_id' => (string) $prescription->id,
                    'pharmacy_id' => (string) $prescription->pharmacy_id,
                    'object_key' => (string) $prescription->object_key,
                    'mime_type' => (string) $prescription->mime_type,
                ];
            });
        } catch (ApiProblem $exception) {
            if ($exception->errorCode === 'not_found') {
                throw $exception;
            }

            throw new ApiProblem('not_found', 'The requested resource was not found.', 404);
        }

        try {
            $bytes = app(PrescriptionObjectStore::class)->get($authorized['object_key']);
        } catch (Throwable) {
            throw new ApiProblem('storage_unavailable', 'The prescription file is unavailable.', 503);
        }

        app(AuditRecorder::class)->record(
            $user,
            'prescription_file_read',
            'prescription',
            $authorized['prescription_id'],
            $authorized['pharmacy_id'],
        );
        $headers = PrescriptionDownloadHeaders::forMime($authorized['mime_type']);

        return response()->streamDownload(function () use ($bytes): void {
            echo $bytes;
        }, null, $headers);
    }

    public function previousQueuePage(): void
    {
        $this->queuePage = max(1, $this->boundedQueuePage() - 1);
    }

    public function nextQueuePage(): void
    {
        $this->queuePage = min($this->boundedQueuePage() + 1, $this->queueLastPage());
    }

    public function lineLimit(): int
    {
        return (int) config('medifind.discovery.max_medicines');
    }

    public function quoteOrder(): ?Order
    {
        if ($this->quoteOrderId === null) {
            return null;
        }

        $order = Order::query()
            ->with(['items', 'quotes'])
            ->where('pharmacy_id', $this->pharmacy()->id)
            ->where('public_id', strtolower($this->quoteOrderId))
            ->first();

        return $order instanceof Order ? $order : null;
    }

    public function currentQuote(): ?OrderQuote
    {
        $quote = $this->quoteOrder()?->quotes->first();

        return $quote instanceof OrderQuote ? $quote : null;
    }

    /**
     * @return array{share_state: string, share_mode: string, content_version: int}|null
     */
    public function quotePrescription(): ?array
    {
        $order = $this->quoteOrder();

        if (! $order instanceof Order || $order->prescription_id === null) {
            return null;
        }

        $prescription = Prescription::query()
            ->whereKey($order->prescription_id)
            ->first(['id', 'share_state', 'share_mode', 'content_version']);

        if (! $prescription instanceof Prescription || ! is_string($prescription->share_mode)) {
            return null;
        }

        return [
            'share_state' => (string) $prescription->share_state,
            'share_mode' => $prescription->share_mode,
            'content_version' => (int) $prescription->content_version,
        ];
    }

    public function queueLastPage(): int
    {
        $count = $this->activeOrders()->count();

        return max(1, (int) ceil($count / self::QUEUE_PAGE_SIZE));
    }

    /**
     * @return Collection<int, Order>
     */
    public function orders(): Collection
    {
        return $this->activeOrders()
            ->orderBy('created_at')
            ->orderBy('id')
            ->forPage($this->boundedQueuePage(), self::QUEUE_PAGE_SIZE)
            ->get();
    }

    private function run(callable $action, string $success): void
    {
        $user = auth()->user();
        $pharmacy = $this->pharmacy();

        if (! $user instanceof User) {
            Notification::make()->title('This action is unauthorized.')->danger()->send();

            return;
        }

        try {
            $action($user, $pharmacy);
            Notification::make()->title($success)->success()->send();
        } catch (Throwable $exception) {
            $message = $exception instanceof ApiProblem || $exception instanceof ValidationException
                ? $exception->getMessage()
                : 'The order could not be updated.';
            Notification::make()->title($message)->danger()->send();
        }
    }

    private function pharmacy(): Pharmacy
    {
        $pharmacy = Filament::getTenant();

        if (! $pharmacy instanceof Pharmacy) {
            throw new ApiProblem('forbidden', 'This action is unauthorized.', 403);
        }

        return $pharmacy;
    }

    private function findPharmacyOrder(string $publicId): ?Order
    {
        $order = Order::query()
            ->where('pharmacy_id', $this->pharmacy()->id)
            ->where('public_id', strtolower($publicId))
            ->first();

        return $order instanceof Order ? $order : null;
    }

    /**
     * @return Builder<Order>
     */
    private function activeOrders(): Builder
    {
        return Order::query()
            ->where('pharmacy_id', $this->pharmacy()->id)
            ->whereIn('status', [
                OrderStatus::PendingPharmacyReview,
                OrderStatus::Confirmed,
                OrderStatus::ReadyForPickup,
            ]);
    }

    private function boundedQueuePage(): int
    {
        $page = min(max(1, $this->queuePage), $this->queueLastPage());
        $this->queuePage = $page;

        return $page;
    }

    private function clearDraft(): void
    {
        $this->draftLines = [];
        $this->medicineId = '';
        $this->quantity = '1';
        $this->unitPrice = '';
    }

    private function orderFileAllowed(Order $order, Prescription $prescription): bool
    {
        if ($order->status !== OrderStatus::PendingPharmacyReview || $order->prescription_usable !== true) {
            return false;
        }

        if ($order->request_mode !== OrderRequestMode::PrescriptionFullImage || $order->prescription_share_mode !== 'full_image') {
            return false;
        }

        if ($prescription->share_mode !== 'full_image') {
            return false;
        }

        if (strtolower((string) $prescription->id) !== strtolower((string) $order->prescription_id)) {
            return false;
        }

        return app(OrderPrescriptionGuard::class)->fulfillmentShareMatches($order, $prescription);
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
