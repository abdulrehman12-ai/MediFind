<?php

namespace Tests\Feature;

use App\Catalog\MedicineCatalog;
use App\Enums\AccountStatus;
use App\Enums\CredentialStatus;
use App\Enums\MembershipStatus;
use App\Enums\OrderStatus;
use App\Enums\StaffRole;
use App\Enums\UserRole;
use App\Exceptions\ApiProblem;
use App\Filament\Pharmacy\Pages\PickupOrders;
use App\Filament\Pharmacy\Pages\ReviewPrescriptions;
use App\Inventory\InvalidInventory;
use App\Inventory\InventoryBook;
use App\Models\Medicine;
use App\Models\Order;
use App\Models\Pharmacy;
use App\Models\PharmacyMembership;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\User;
use App\Orders\FulfillmentService;
use App\Orders\OrderService;
use App\Orders\QuoteService;
use App\Pharmacy\PharmacyWorkflow;
use App\Prescriptions\PrescriptionObjectStore;
use App\Prescriptions\PrescriptionWorkflow;
use Database\Seeders\CitySeeder;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class PharmacyFulfillmentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $customer;

    private User $pharmacist;

    private string $token;

    private Pharmacy $pharmacy;

    private Medicine $medicine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CitySeeder::class);
        $this->admin = $this->user(UserRole::Admin, 'admin-phase6@example.test', null);
        $this->customer = $this->user(UserRole::Customer, 'phase6@example.test', '+923001110701');
        foreach (['upload', 'edit', 'share', 'read'] as $action) {
            RateLimiter::clear('prescription:'.$action.':'.$this->customer->id);
        }
        $this->token = $this->customer->createToken('customer', ['customer:access'], now()->addDay())->plainTextToken;
        $this->pharmacy = $this->eligiblePharmacy('Phase 6 Pharmacy');
        $this->pharmacist = $this->pharmacist($this->pharmacy, 'phase6-pharmacist@example.test');
        $this->medicine = $this->medicine('Paracetamol', '500 mg', 'Tablet', 'Example Labs', 'Example');
        $this->stock(4, 2500);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_confirmation_reserves_without_changing_on_hand_and_release_restores_available(): void
    {
        $order = $this->catalogOrder(3);
        $this->fulfillment()->confirm($this->pharmacist, $this->pharmacy, $order->public_id);
        $order->refresh();

        $this->assertSame(OrderStatus::Confirmed, $order->status);
        $this->assertSame(7500, (int) $order->confirmed_total_paisa);
        $this->assertSame('unpaid', $order->payment_status);
        $this->assertNull($order->cash_collected_paisa);
        $this->assertSame(4, $this->onHand());
        $this->assertSame(1, $this->available());
        $this->assertSame(['active'], $this->states($order));

        $this->fulfillment()->confirm($this->pharmacist, $this->pharmacy, $order->public_id);
        $this->assertSame(1, DB::table('order_reservations')->where('order_id', $order->id)->count());
        $this->assertSame(1, DB::table('order_status_events')->where('order_id', $order->id)->where('event', 'confirmed')->count());

        $this->withToken($this->token)->postJson('/api/v1/orders/'.$order->public_id.'/cancel', ['reason' => 'Changed my mind'])->assertOk();
        $this->withToken($this->token)->postJson('/api/v1/orders/'.$order->public_id.'/cancel', ['reason' => 'Again'])->assertOk();
        $order->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame(4, $this->onHand());
        $this->assertSame(4, $this->available());
        $this->assertSame(['released'], $this->states($order));
        $this->assertSame(1, DB::table('order_status_events')->where('order_id', $order->id)->where('event', 'cancelled')->count());
        $this->assertSame('original note', $order->customer_note);
    }

    public function test_collection_consumes_the_reservation_and_decrements_on_hand_once(): void
    {
        $order = $this->readyCatalog(3);
        $collected = $this->fulfillment()->collect($this->pharmacist, $this->pharmacy, $order->public_id, 7500);
        $again = $this->fulfillment()->collect($this->pharmacist, $this->pharmacy, $order->public_id, 7500);

        $this->assertSame(OrderStatus::Collected, $collected->status);
        $this->assertSame('paid', $collected->payment_status);
        $this->assertSame(7500, (int) $collected->cash_collected_paisa);
        $this->assertSame($collected->id, $again->id);
        $this->assertSame(1, $this->onHand());
        $this->assertSame(1, $this->available());
        $this->assertSame(['consumed'], $this->states($order));
        $this->assertSame('in_stock', DB::table('pharmacy_inventory')->where('medicine_id', $this->medicine->id)->value('stock_status'));
        $shown = $this->withToken($this->token)->getJson('/api/v1/orders/'.$order->public_id)->assertOk();
        $shown->assertJsonPath('data.payment_status', 'paid');
        $shown->assertJsonPath('data.amounts_are_estimates', false);
        $this->assertSame('This order was collected. Cash was paid at pickup.', $shown->json('data.notices.0'));
        $this->assertSame(1, DB::table('order_status_events')->where('order_id', $order->id)->where('event', 'collected')->count());
    }

    public function test_zero_total_collection_is_no_payment_due(): void
    {
        $image = app(PrescriptionWorkflow::class)->share($this->customer, $this->storedPrescription(), 'full_image', (string) $this->pharmacy->id);
        app(PrescriptionWorkflow::class)->review($this->pharmacist, $this->pharmacy, $image->refresh(), (int) $image->content_version, 'verified', null);
        $this->putCart([
            'pharmacy_id' => $this->pharmacy->id,
            'mode' => 'prescription_full_image',
            'prescription_id' => $image->public_id,
            'items' => [],
        ])->assertOk();
        $created = $this->submit()->assertCreated();
        $created->assertJsonPath('data.estimate_total_paisa', null);
        $created->assertJsonPath('data.quote', null);
        $order = Order::query()->where('public_id', $created->json('data.id'))->firstOrFail();
        $this->reprice(0);

        $quote = $this->quotes()->propose($this->pharmacist, $this->pharmacy, $order->public_id, [[
            'medicine_id' => (string) $this->medicine->id,
            'quantity' => 1,
            'unit_price_paisa' => 0,
        ]]);
        $this->withToken($this->token)->postJson('/api/v1/orders/'.$order->public_id.'/quote-acceptance', [
            'quote_id' => $quote->id,
            'version' => $quote->version,
        ])->assertOk()->assertJsonPath('data.quote.accepted', true);

        $confirmed = $this->fulfillment()->confirm($this->pharmacist, $this->pharmacy, $order->public_id);
        $this->assertSame(0, (int) $confirmed->confirmed_total_paisa);
        $ready = $this->markReady($confirmed);
        $collected = $this->fulfillment()->collect($this->pharmacist, $this->pharmacy, $ready->public_id, 0);
        $this->assertSame('no_payment_due', $collected->payment_status);
        $this->assertSame(0, (int) $collected->cash_collected_paisa);
        $shown = $this->withToken($this->token)->getJson('/api/v1/orders/'.$order->public_id)->assertOk();
        $this->assertSame('This order was collected. No payment was due.', $shown->json('data.notices.0'));
        $this->assertNotSame('Example Labs secret', json_encode($shown->json()));
    }

    public function test_price_changes_need_acceptance_and_a_mismatch_invalidates_after_rollback(): void
    {
        $order = $this->catalogOrder(1);
        $this->reprice(1800);
        try {
            $this->fulfillment()->confirm($this->pharmacist, $this->pharmacy, $order->public_id);
            $this->fail('A price change was confirmed without a quote.');
        } catch (ApiProblem $exception) {
            $this->assertSame('quote_required', $exception->errorCode);
        }

        $lower = $this->quotes()->propose($this->pharmacist, $this->pharmacy, $order->public_id, [[
            'medicine_id' => (string) $this->medicine->id,
            'quantity' => 1,
            'unit_price_paisa' => 1800,
        ]]);
        $this->withToken($this->token)->postJson('/api/v1/orders/'.$order->public_id.'/quote-acceptance', [
            'quote_id' => $lower->id,
            'version' => $lower->version,
        ])->assertOk();
        $confirmed = $this->fulfillment()->confirm($this->pharmacist, $this->pharmacy, $order->public_id);
        $this->assertSame(1800, (int) $confirmed->confirmed_total_paisa);
        $this->assertSame(4, $this->onHand());

        $second = $this->catalogOrder(1);
        $this->reprice(3000);
        $higher = $this->quotes()->propose($this->pharmacist, $this->pharmacy, $second->public_id, [[
            'medicine_id' => (string) $this->medicine->id,
            'quantity' => 1,
            'unit_price_paisa' => 3000,
        ]]);
        $this->withToken($this->token)->postJson('/api/v1/orders/'.$second->public_id.'/quote-acceptance', [
            'quote_id' => $higher->id,
            'version' => $higher->version,
        ])->assertOk();
        $this->reprice(2500);
        try {
            $this->fulfillment()->confirm($this->pharmacist, $this->pharmacy, $second->public_id);
            $this->fail('A stale quote was confirmed.');
        } catch (ApiProblem $exception) {
            $this->assertSame('quote_invalid', $exception->errorCode);
        }
        $second->refresh();
        $this->assertSame(OrderStatus::PendingPharmacyReview, $second->status);
        $this->assertNull($second->confirmed_total_paisa);
        $this->assertSame('invalid', DB::table('order_quotes')->where('id', $higher->id)->value('state'));
        $this->assertSame(0, DB::table('order_reservations')->where('order_id', $second->id)->count());
        $this->assertSame(4, $this->onHand());
    }

    public function test_quoted_unit_price_must_match_current_inventory_before_any_quote_is_saved(): void
    {
        $catalog = $this->catalogOrder(1);
        $open = $this->quotes()->propose($this->pharmacist, $this->pharmacy, $catalog->public_id, [[
            'medicine_id' => (string) $this->medicine->id,
            'quantity' => 1,
            'unit_price_paisa' => 2500,
        ]]);
        $this->assertSame('open', $open->state);
        $this->assertSame(2500, (int) $open->total_paisa);

        try {
            $this->quotes()->propose($this->pharmacist, $this->pharmacy, $catalog->public_id, [[
                'medicine_id' => (string) $this->medicine->id,
                'quantity' => 1,
                'unit_price_paisa' => 1800,
            ]]);
            $this->fail('A mismatched catalog price was stored as a quote.');
        } catch (ValidationException $exception) {
            $this->assertSame(['Each quoted unit price must match the pharmacy\'s current inventory price.'], $exception->errors()['lines'] ?? null);
        }
        $this->assertSame(1, DB::table('order_quotes')->where('order_id', $catalog->id)->count());
        $this->assertSame('open', DB::table('order_quotes')->where('id', $open->id)->value('state'));
        $this->assertSame(2500, (int) DB::table('order_quotes')->where('id', $open->id)->value('total_paisa'));

        $image = app(PrescriptionWorkflow::class)->share($this->customer, $this->storedPrescription(), 'full_image', (string) $this->pharmacy->id);
        app(PrescriptionWorkflow::class)->review($this->pharmacist, $this->pharmacy, $image->refresh(), (int) $image->content_version, 'verified', null);
        $this->putCart([
            'pharmacy_id' => $this->pharmacy->id,
            'mode' => 'prescription_full_image',
            'prescription_id' => $image->public_id,
            'items' => [],
        ])->assertOk();
        $created = $this->submit()->assertCreated();
        $fullImage = Order::query()->where('public_id', $created->json('data.id'))->firstOrFail();

        try {
            $this->quotes()->propose($this->pharmacist, $this->pharmacy, $fullImage->public_id, [[
                'medicine_id' => (string) $this->medicine->id,
                'quantity' => 1,
                'unit_price_paisa' => 50,
            ]]);
            $this->fail('A mismatched full-image price was stored as a quote.');
        } catch (ValidationException $exception) {
            $this->assertSame(['Each quoted unit price must match the pharmacy\'s current inventory price.'], $exception->errors()['lines'] ?? null);
        }
        $this->assertSame(0, DB::table('order_quotes')->where('order_id', $fullImage->id)->count());

        $accepted = $this->quotes()->propose($this->pharmacist, $this->pharmacy, $fullImage->public_id, [[
            'medicine_id' => (string) $this->medicine->id,
            'quantity' => 1,
            'unit_price_paisa' => 2500,
        ]]);
        $this->assertSame('open', $accepted->state);
        $this->assertSame(2500, (int) $accepted->total_paisa);
        $this->withToken($this->token)->postJson('/api/v1/orders/'.$fullImage->public_id.'/quote-acceptance', [
            'quote_id' => $accepted->id,
            'version' => $accepted->version,
        ])->assertOk();
        $confirmed = $this->fulfillment()->confirm($this->pharmacist, $this->pharmacy, $fullImage->public_id);
        $this->assertSame(2500, (int) $confirmed->confirmed_total_paisa);
    }

    public function test_direct_sql_rejects_collected_rows_without_cash(): void
    {
        $paid = $this->readyCatalog(1);
        try {
            DB::transaction(function () use ($paid): void {
                DB::update("update orders set status = 'collected', payment_status = 'paid', cash_collected_paisa = null where id = ?", [$paid->id]);
            });
            $this->fail('Collected paid with NULL cash was stored.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('orders_collected_cash_present_check', $exception->getMessage());
        }
        $paid->refresh();
        $this->assertSame(OrderStatus::ReadyForPickup, $paid->status);
        $this->assertSame('unpaid', $paid->payment_status);
        $this->assertNull($paid->cash_collected_paisa);

        $collected = $this->fulfillment()->collect($this->pharmacist, $this->pharmacy, $paid->public_id, 2500);
        $this->assertSame('paid', $collected->payment_status);
        $this->assertSame(2500, (int) $collected->cash_collected_paisa);

        $image = app(PrescriptionWorkflow::class)->share($this->customer, $this->storedPrescription(), 'full_image', (string) $this->pharmacy->id);
        app(PrescriptionWorkflow::class)->review($this->pharmacist, $this->pharmacy, $image->refresh(), (int) $image->content_version, 'verified', null);
        $this->putCart([
            'pharmacy_id' => $this->pharmacy->id,
            'mode' => 'prescription_full_image',
            'prescription_id' => $image->public_id,
            'items' => [],
        ])->assertOk();
        $created = $this->submit()->assertCreated();
        $zero = Order::query()->where('public_id', $created->json('data.id'))->firstOrFail();
        $this->reprice(0);
        $quote = $this->quotes()->propose($this->pharmacist, $this->pharmacy, $zero->public_id, [[
            'medicine_id' => (string) $this->medicine->id,
            'quantity' => 1,
            'unit_price_paisa' => 0,
        ]]);
        $this->withToken($this->token)->postJson('/api/v1/orders/'.$zero->public_id.'/quote-acceptance', [
            'quote_id' => $quote->id,
            'version' => $quote->version,
        ])->assertOk();
        $this->fulfillment()->confirm($this->pharmacist, $this->pharmacy, $zero->public_id);
        $zero = $this->markReady($zero->refresh());
        try {
            DB::transaction(function () use ($zero): void {
                DB::update("update orders set status = 'collected', payment_status = 'no_payment_due', cash_collected_paisa = null where id = ?", [$zero->id]);
            });
            $this->fail('Collected no_payment_due with NULL cash was stored.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('orders_collected_cash_present_check', $exception->getMessage());
        }
        $zero->refresh();
        $this->assertSame(OrderStatus::ReadyForPickup, $zero->status);
        $this->assertSame('unpaid', $zero->payment_status);
        $this->assertNull($zero->cash_collected_paisa);
        $free = $this->fulfillment()->collect($this->pharmacist, $this->pharmacy, $zero->public_id, 0);
        $this->assertSame('no_payment_due', $free->payment_status);
        $this->assertSame(0, (int) $free->cash_collected_paisa);
    }

    public function test_catalog_quotes_cannot_change_identity_or_quantity(): void
    {
        $order = $this->catalogOrder(1);
        $other = $this->medicine('Ibuprofen', '200 mg', 'Tablet', 'Example Labs', 'Example');
        $this->stockMedicine($other, 4, 900);

        try {
            $this->quotes()->propose($this->pharmacist, $this->pharmacy, $order->public_id, [[
                'medicine_id' => (string) $other->id,
                'quantity' => 1,
                'unit_price_paisa' => 900,
            ]]);
            $this->fail('A different medicine was quoted.');
        } catch (ValidationException) {
            $this->assertSame(0, DB::table('order_quotes')->where('order_id', $order->id)->count());
        }

        try {
            $this->quotes()->propose($this->pharmacist, $this->pharmacy, $order->public_id, [[
                'medicine_id' => (string) $this->medicine->id,
                'quantity' => 2,
                'unit_price_paisa' => 2500,
            ]]);
            $this->fail('A different quantity was quoted.');
        } catch (ValidationException) {
            $this->assertSame(0, DB::table('order_quotes')->where('order_id', $order->id)->count());
        }
    }

    public function test_unknown_quantity_and_a_competing_order_cannot_both_reserve(): void
    {
        $version = (int) DB::table('pharmacy_inventory')->where('medicine_id', $this->medicine->id)->value('version');
        app(InventoryBook::class)->save($this->admin, $this->pharmacy, [
            'medicine_id' => (string) $this->medicine->id,
            'stock_status' => 'in_stock',
            'quantity_on_hand' => null,
            'unit_price_paisa' => 2500,
            'is_available_for_pickup' => true,
            'version' => $version,
        ]);
        $unknown = $this->catalogOrder(1);
        try {
            $this->fulfillment()->confirm($this->pharmacist, $this->pharmacy, $unknown->public_id);
            $this->fail('Null on-hand quantity was reserved.');
        } catch (ApiProblem $exception) {
            $this->assertContains($exception->errorCode, ['insufficient_stock', 'medicine_unavailable']);
        }
        $this->assertSame(0, DB::table('order_reservations')->count());

        $this->reprice(2500);
        $version = (int) DB::table('pharmacy_inventory')->where('medicine_id', $this->medicine->id)->value('version');
        app(InventoryBook::class)->save($this->admin, $this->pharmacy, [
            'medicine_id' => (string) $this->medicine->id,
            'stock_status' => 'in_stock',
            'quantity_on_hand' => 4,
            'unit_price_paisa' => 2500,
            'is_available_for_pickup' => true,
            'version' => $version,
        ]);
        $first = $this->catalogOrder(3);
        $second = $this->catalogOrder(3);
        $this->fulfillment()->confirm($this->pharmacist, $this->pharmacy, $first->public_id);
        try {
            $this->fulfillment()->confirm($this->pharmacist, $this->pharmacy, $second->public_id);
            $this->fail('Two orders reserved the same units.');
        } catch (ApiProblem $exception) {
            $this->assertSame('insufficient_stock', $exception->errorCode);
        }
        $this->assertSame(4, $this->onHand());
        $this->assertSame(1, $this->available());
        $this->assertSame(1, DB::table('order_reservations')->where('state', 'active')->count());
    }

    public function test_inventory_edit_cannot_drop_on_hand_below_active_reservations(): void
    {
        $order = $this->catalogOrder(3);
        $this->fulfillment()->confirm($this->pharmacist, $this->pharmacy, $order->public_id);
        $version = (int) DB::table('pharmacy_inventory')->where('medicine_id', $this->medicine->id)->value('version');

        $this->expectException(InvalidInventory::class);
        app(InventoryBook::class)->save($this->admin, $this->pharmacy, [
            'medicine_id' => (string) $this->medicine->id,
            'stock_status' => 'in_stock',
            'quantity_on_hand' => 2,
            'unit_price_paisa' => 2500,
            'is_available_for_pickup' => true,
            'version' => $version,
        ]);
    }

    public function test_prescription_failure_after_confirmation_and_readiness_releases_without_a_no_show(): void
    {
        $order = $this->confirmedSelected(1);
        $prescription = Prescription::query()->findOrFail($order->prescription_id);
        app(PrescriptionWorkflow::class)->withdraw($this->customer, $prescription);
        $order->refresh();
        $this->assertSame(OrderStatus::Unfulfillable, $order->status);
        $this->assertFalse((bool) $order->prescription_usable);
        $this->assertSame(['released'], $this->states($order));
        $this->assertSame(4, $this->onHand());
        $this->assertSame('unpaid', $order->payment_status);
        $this->assertSame(1, DB::table('order_status_events')->where('order_id', $order->id)->where('event', 'prescription_unusable')->count());
        $this->assertSame(0, DB::table('order_status_events')->where('order_id', $order->id)->where('event', 'not_collected')->count());
        $shown = $this->withToken($this->token)->getJson('/api/v1/orders/'.$order->public_id)->assertOk();
        $shown->assertJsonPath('data.status', 'unfulfillable');
        $this->assertStringContainsString('prescription', (string) $shown->json('data.notices.0'));

        app(PrescriptionWorkflow::class)->delete($this->customer, $prescription->refresh());
        $this->assertSame(1, DB::table('order_status_events')->where('order_id', $order->id)->where('event', 'prescription_unusable')->count());

        $ready = $this->markReady($this->confirmedSelected(1));
        $second = Prescription::query()->findOrFail($ready->prescription_id);
        app(PrescriptionWorkflow::class)->share($this->customer, $second, 'selected_items', (string) $this->pharmacy->id);
        $ready->refresh();
        $this->assertSame(OrderStatus::Unfulfillable, $ready->status);
        $this->assertSame(['released'], $this->states($ready));
        $this->assertNotNull($ready->pickup_window_starts_at);
        $this->assertSame(4, $this->onHand());
    }

    public function test_pending_unusable_prescription_stays_pending_and_cannot_be_confirmed(): void
    {
        $order = $this->selectedOrder(1);
        $prescription = Prescription::query()->findOrFail($order->prescription_id);
        app(PrescriptionWorkflow::class)->withdraw($this->customer, $prescription);
        $order->refresh();
        $this->assertSame(OrderStatus::PendingPharmacyReview, $order->status);
        $this->assertFalse((bool) $order->prescription_usable);
        $this->assertSame(0, DB::table('order_reservations')->where('order_id', $order->id)->count());

        try {
            $this->fulfillment()->confirm($this->pharmacist, $this->pharmacy, $order->public_id);
            $this->fail('An unusable prescription was confirmed.');
        } catch (ApiProblem $exception) {
            $this->assertSame('prescription_unusable', $exception->errorCode);
        }
    }

    public function test_no_show_and_late_collection_compete_without_changing_on_hand_until_collection(): void
    {
        $clock = $this->clockAfterInventoryVerification();
        $left = $this->catalogOrder(1);
        $this->fulfillment()->confirm($this->pharmacist, $this->pharmacy, $left->public_id);
        $firstWindow = $this->pickupAfter($clock);
        $left = $this->fulfillment()->markReady($this->pharmacist, $this->pharmacy, $left->public_id, $firstWindow['start'], $firstWindow['end']);
        Carbon::setTestNow(Carbon::parse($firstWindow['afterGrace']));
        $closed = $this->fulfillment()->notCollected($this->pharmacist, $this->pharmacy, $left->public_id, 'Customer did not arrive');
        $repeat = $this->fulfillment()->notCollected($this->pharmacist, $this->pharmacy, $left->public_id, 'Again');
        $this->assertSame(OrderStatus::NotCollected, $closed->status);
        $this->assertSame($closed->status, $repeat->status);
        $this->assertSame('unpaid', $closed->payment_status);
        $this->assertNull($closed->cash_collected_paisa);
        $this->assertSame(['released'], $this->states($left));
        $this->assertSame(4, $this->onHand());
        $this->assertSame(1, DB::table('order_status_events')->where('order_id', $left->id)->where('event', 'not_collected')->count());

        $this->reprice(2500);
        $right = $this->catalogOrder(1);
        $this->fulfillment()->confirm($this->pharmacist, $this->pharmacy, $right->public_id);
        $secondWindow = $this->pickupAfter(now()->utc());
        $this->fulfillment()->markReady($this->pharmacist, $this->pharmacy, $right->public_id, $secondWindow['start'], $secondWindow['end']);
        $right->refresh();
        $before = $this->fulfillmentSideEffects();
        $onHand = $this->onHand();
        Carbon::setTestNow(Carbon::parse((string) $right->collect_until)->utc());
        try {
            $this->fulfillment()->collect($this->pharmacist, $this->pharmacy, $right->public_id, 2500);
            $this->fail('Collection succeeded at collect_until.');
        } catch (ApiProblem $exception) {
            $this->assertSame('pickup_not_open', $exception->errorCode);
        }
        $right->refresh();
        $this->assertSame(OrderStatus::ReadyForPickup, $right->status);
        $this->assertSame('unpaid', $right->payment_status);
        $this->assertNull($right->cash_collected_paisa);
        $this->assertSame(['active'], $this->states($right));
        $this->assertSame($onHand, $this->onHand());
        $this->assertSame($before, $this->fulfillmentSideEffects());
        $this->fulfillment()->notCollected($this->pharmacist, $this->pharmacy, $right->public_id, 'Customer did not arrive');
        $this->assertSame(OrderStatus::NotCollected, $right->refresh()->status);
        $this->assertSame(['released'], $this->states($right));
        $this->assertSame($onHand, $this->onHand());
    }

    public function test_collection_is_allowed_only_on_the_half_open_pickup_interval(): void
    {
        $clock = $this->clockAfterInventoryVerification();
        $order = $this->catalogOrder(1);
        $this->fulfillment()->confirm($this->pharmacist, $this->pharmacy, $order->public_id);
        $window = $this->pickupAfter($clock);
        $order = $this->fulfillment()->markReady($this->pharmacist, $this->pharmacy, $order->public_id, $window['start'], $window['end']);
        $start = Carbon::parse((string) $order->pickup_window_starts_at)->utc();
        $end = Carbon::parse((string) $order->pickup_window_ends_at)->utc();
        $until = Carbon::parse((string) $order->collect_until)->utc();
        $this->assertTrue($until->equalTo($end->copy()->addSeconds((int) $order->pickup_grace_seconds)));

        Carbon::setTestNow($start->copy()->subSecond());
        $before = $this->snapshotPickup($order);
        try {
            $this->fulfillment()->collect($this->pharmacist, $this->pharmacy, $order->public_id, 2500);
            $this->fail('Collection succeeded before the window start.');
        } catch (ApiProblem $exception) {
            $this->assertSame('pickup_not_open', $exception->errorCode);
        }
        $this->assertSame($before, $this->snapshotPickup($order));

        Carbon::setTestNow($start->copy());
        $atStart = $this->fulfillment()->collect($this->pharmacist, $this->pharmacy, $order->public_id, 2500);
        $this->assertSame(OrderStatus::Collected, $atStart->status);
        $this->assertSame(['consumed'], $this->states($order));
        $this->assertSame(3, $this->onHand());
        Carbon::setTestNow($until->copy()->addSecond());
        $again = $this->fulfillment()->collect($this->pharmacist, $this->pharmacy, $order->public_id, 2500);
        $this->assertSame($atStart->id, $again->id);
        $this->assertSame(OrderStatus::Collected, $again->status);
        $this->assertSame(3, $this->onHand());
        $this->assertSame(1, DB::table('order_status_events')->where('order_id', $order->id)->where('event', 'collected')->count());

        $grace = $this->catalogOrder(1);
        $this->fulfillment()->confirm($this->pharmacist, $this->pharmacy, $grace->public_id);
        $second = $this->pickupAfter(now()->utc());
        $grace = $this->fulfillment()->markReady($this->pharmacist, $this->pharmacy, $grace->public_id, $second['start'], $second['end']);
        $graceEnd = Carbon::parse((string) $grace->pickup_window_ends_at)->utc();
        Carbon::setTestNow($graceEnd->copy());
        $duringGrace = $this->fulfillment()->collect($this->pharmacist, $this->pharmacy, $grace->public_id, 2500);
        $this->assertSame(OrderStatus::Collected, $duringGrace->status);
        $this->assertSame(2, $this->onHand());

        $cutoff = $this->catalogOrder(1);
        $this->fulfillment()->confirm($this->pharmacist, $this->pharmacy, $cutoff->public_id);
        $third = $this->pickupAfter(now()->utc());
        $cutoff = $this->fulfillment()->markReady($this->pharmacist, $this->pharmacy, $cutoff->public_id, $third['start'], $third['end']);
        $cutoffUntil = Carbon::parse((string) $cutoff->collect_until)->utc();
        Carbon::setTestNow($cutoffUntil->copy()->subSecond());
        $justBefore = $this->fulfillment()->collect($this->pharmacist, $this->pharmacy, $cutoff->public_id, 2500);
        $this->assertSame(OrderStatus::Collected, $justBefore->status);
        $this->assertSame(1, $this->onHand());
    }

    public function test_forbidden_roles_cash_mismatch_and_rejection_release_nothing(): void
    {
        $manager = $this->user(UserRole::PharmacyStaff, 'phase6-manager@example.test', null);
        PharmacyMembership::query()->forceCreate([
            'pharmacy_id' => $this->pharmacy->id,
            'user_id' => $manager->id,
            'staff_role' => StaffRole::Manager,
            'status' => MembershipStatus::Active,
            'credential_status' => CredentialStatus::Unverified,
        ]);
        $order = $this->catalogOrder(1);
        try {
            $this->fulfillment()->confirm($manager, $this->pharmacy, $order->public_id);
            $this->fail('A manager confirmed an order.');
        } catch (ApiProblem $exception) {
            $this->assertSame('forbidden', $exception->errorCode);
        }

        $rejected = $this->fulfillment()->reject($this->pharmacist, $this->pharmacy, $order->public_id, 'Cannot prepare this request');
        $this->assertSame(OrderStatus::Rejected, $rejected->status);
        $this->assertSame(0, DB::table('order_reservations')->where('order_id', $order->id)->count());
        $this->assertSame(4, $this->onHand());

        $ready = $this->readyCatalog(1);
        try {
            $this->fulfillment()->collect($this->pharmacist, $this->pharmacy, $ready->public_id, 1);
            $this->fail('A mismatched cash amount was recorded.');
        } catch (ApiProblem $exception) {
            $this->assertSame('cash_mismatch', $exception->errorCode);
        }
        $ready->refresh();
        $this->assertSame(OrderStatus::ReadyForPickup, $ready->status);
        $this->assertSame('unpaid', $ready->payment_status);
        $this->assertSame(['active'], $this->states($ready));
        $this->assertSame(4, $this->onHand());

        $this->actingAs($this->pharmacist, 'web');
        Filament::setCurrentPanel(Filament::getPanel('pharmacy'));
        Filament::setTenant($this->pharmacy, true);
        Livewire::test(PickupOrders::class)->assertSee('Cash is recorded at pickup', false);
    }

    public function test_submitted_snapshot_stays_immutable_and_audit_metadata_stays_bounded(): void
    {
        $order = $this->catalogOrder(1);
        $this->fulfillment()->confirm($this->pharmacist, $this->pharmacy, $order->public_id);
        $id = (string) $order->id;

        try {
            DB::transaction(function () use ($id): void {
                DB::table('orders')->where('id', $id)->update(['customer_note' => 'rewritten']);
            });
            $this->fail('The order note was rewritten.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('immutable', $exception->getMessage());
        }

        $metadata = (string) DB::table('audit_events')->where('action', 'order_confirmed')->value('metadata');
        $this->assertStringNotContainsString('original note', $metadata);
        $this->assertStringNotContainsString('quantity_on_hand', $metadata);
    }

    public function test_quote_lifetime_authorization_and_last_unit_collection(): void
    {
        $order = $this->catalogOrder(1);
        $lines = [[
            'medicine_id' => (string) $this->medicine->id,
            'quantity' => 1,
            'unit_price_paisa' => 2500,
        ]];
        $first = $this->quotes()->propose($this->pharmacist, $this->pharmacy, $order->public_id, $lines);
        $second = $this->quotes()->propose($this->pharmacist, $this->pharmacy, $order->public_id, $lines);
        $this->assertSame(2, (int) $second->version);
        $this->assertSame('superseded', DB::table('order_quotes')->where('id', $first->id)->value('state'));

        $this->withToken($this->token)->postJson('/api/v1/orders/'.$order->public_id.'/quote-acceptance', [
            'quote_id' => $first->id,
            'version' => $first->version,
        ])->assertStatus(409)->assertJsonPath('error.code', 'quote_stale');

        $other = $this->user(UserRole::Customer, 'phase6-other@example.test', '+923001110702');
        $otherToken = $other->createToken('customer', ['customer:access'], now()->addDay())->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->withToken($otherToken)->postJson('/api/v1/orders/'.$order->public_id.'/quote-acceptance', [
            'quote_id' => $second->id,
            'version' => (int) $second->version,
        ])->assertNotFound()->assertJsonPath('error.code', 'not_found');

        $longToken = $this->customer->createToken('customer', ['customer:access'], now()->addDays(3))->plainTextToken;
        Carbon::setTestNow(now()->addHours(25));
        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->withToken($longToken)->postJson('/api/v1/orders/'.$order->public_id.'/quote-acceptance', [
            'quote_id' => $second->id,
            'version' => $second->version,
        ])->assertStatus(409)->assertJsonPath('error.code', 'quote_expired');
        $this->assertSame('invalid', DB::table('order_quotes')->where('id', $second->id)->value('state'));
        Carbon::setTestNow();

        $inventory = $this->user(UserRole::PharmacyStaff, 'phase6-inventory@example.test', null);
        PharmacyMembership::query()->forceCreate([
            'pharmacy_id' => $this->pharmacy->id,
            'user_id' => $inventory->id,
            'staff_role' => StaffRole::InventoryStaff,
            'status' => MembershipStatus::Active,
            'credential_status' => CredentialStatus::Unverified,
        ]);
        $this->assertForbidden($inventory, $this->pharmacy, $order->public_id);

        $unverified = app(PharmacyWorkflow::class)->assignPharmacist(
            $this->admin,
            $this->pharmacy,
            $this->user(UserRole::PharmacyStaff, 'phase6-unverified@example.test', null),
            'PH-UNVERIFIED',
        );
        $this->assertForbidden(User::query()->findOrFail($unverified->user_id), $this->pharmacy, $order->public_id);

        $elsewhere = $this->eligiblePharmacy('Phase 6 Other Pharmacy');
        $outsider = $this->pharmacist($elsewhere, 'phase6-outsider@example.test');
        $this->assertForbidden($outsider, $this->pharmacy, $order->public_id);
        try {
            $this->fulfillment()->confirm($outsider, $elsewhere, $order->public_id);
            $this->fail('Another pharmacy confirmed this order.');
        } catch (ApiProblem $exception) {
            $this->assertSame('not_found', $exception->errorCode);
        }

        app(PharmacyWorkflow::class)->suspend($this->admin, $this->pharmacy->refresh(), 'Counter is closed');
        $this->assertForbidden($this->pharmacist, $this->pharmacy, $order->public_id);
        app(PharmacyWorkflow::class)->reinstate($this->admin, $this->pharmacy->refresh());

        $version = (int) DB::table('pharmacy_inventory')->where('medicine_id', $this->medicine->id)->value('version');
        app(InventoryBook::class)->save($this->admin, $this->pharmacy, [
            'medicine_id' => (string) $this->medicine->id,
            'stock_status' => 'in_stock',
            'quantity_on_hand' => 1,
            'unit_price_paisa' => 2500,
            'is_available_for_pickup' => true,
            'version' => $version,
        ]);
        $last = $this->readyCatalog(1);
        $collected = $this->fulfillment()->collect($this->pharmacist, $this->pharmacy, $last->public_id, 2500);
        $this->assertSame(OrderStatus::Collected, $collected->status);
        $this->assertSame('paid', $collected->payment_status);
        $this->assertSame(2500, (int) $collected->cash_collected_paisa);
        $row = DB::table('pharmacy_inventory')->where('medicine_id', $this->medicine->id)->first();
        $this->assertSame(0, (int) $row->quantity_on_hand);
        $this->assertSame('out_of_stock', $row->stock_status);
        $this->assertFalse((bool) $row->is_available_for_pickup);
        $this->assertSame($version + 2, (int) $row->version);

        $this->reprice(2500);
        $early = $this->readyCatalog(1);
        try {
            $this->fulfillment()->notCollected($this->pharmacist, $this->pharmacy, $early->public_id, 'Too soon');
            $this->fail('A no-show was recorded before the pickup deadline.');
        } catch (ApiProblem $exception) {
            $this->assertSame('not_collected_too_early', $exception->errorCode);
        }
        $early->refresh();
        $this->assertSame(OrderStatus::ReadyForPickup, $early->status);
        $this->assertSame(['active'], $this->states($early));
        try {
            app(OrderService::class)->cancel($this->customer, $early->public_id, 'Changed my mind');
            $this->fail('A ready order was cancelled.');
        } catch (ApiProblem $exception) {
            $this->assertSame('order_not_cancellable', $exception->errorCode);
        }

        $confirmed = $this->confirmCatalog(1);
        try {
            $this->fulfillment()->reject($this->pharmacist, $this->pharmacy, $confirmed->public_id, 'Too late to reject');
            $this->fail('A confirmed order was rejected.');
        } catch (ApiProblem $exception) {
            $this->assertSame('order_not_pending', $exception->errorCode);
        }
        $this->assertSame(OrderStatus::Confirmed, $confirmed->refresh()->status);

        try {
            $this->fulfillment()->collect($this->pharmacist, $this->pharmacy, $order->public_id, 2500);
            $this->fail('A pending order was collected.');
        } catch (ApiProblem $exception) {
            $this->assertSame('order_not_ready', $exception->errorCode);
        }
    }

    public function test_pickup_queue_reaches_every_active_order(): void
    {
        $seed = $this->catalogOrder(1);
        $tieAt = '2026-09-30 00:00:00';
        $lowId = '00000000-0000-4000-8000-000000000001';
        $highId = '00000000-0000-4000-8000-000000000002';
        $this->replicateOrder($seed, 'TIELOW0000000001', $lowId, $tieAt);
        $this->replicateOrder($seed, 'TIEHIGH000000001', $highId, $tieAt);
        $newest = '';

        for ($index = 1; $index <= 49; $index++) {
            $newest = sprintf('Q%015d', $index);
            $this->replicateOrder($seed, $newest, (string) Str::uuid(), now()->addSeconds($index)->format('Y-m-d H:i:s'));
        }

        $other = $this->eligiblePharmacy('Other Queue Pharmacy');
        $this->replicateOrder($seed, 'OTHERPHARM000001', (string) Str::uuid(), $tieAt, (string) $other->id);
        app(OrderService::class)->cancel($this->customer, $seed->public_id, 'Queue fixture');

        $this->actingAs($this->pharmacist, 'web');
        Filament::setCurrentPanel(Filament::getPanel('pharmacy'));
        Filament::setTenant($this->pharmacy, true);
        $component = Livewire::test(PickupOrders::class);
        $first = $component->instance()->orders()->pluck('reference')->all();
        $this->assertSame('TIELOW0000000001', $first[0]);
        $this->assertSame('TIEHIGH000000001', $first[1]);
        $this->assertCount(50, $first);
        $this->assertNotContains($newest, $first);
        $this->assertNotContains('OTHERPHARM000001', $first);
        $component->assertDontSee('OTHERPHARM000001', false);
        $component->call('nextQueuePage');
        $second = $component->instance()->orders()->pluck('reference')->all();
        $this->assertSame([$newest], $second);
        $component->assertSee($newest, false);
        $component->assertDontSee('TIELOW0000000001', false);
        $component->assertDontSee('OTHERPHARM000001', false);
        $component->call('previousQueuePage');
        $this->assertSame($first, $component->instance()->orders()->pluck('reference')->all());
        $component->set('queuePage', 999);
        $this->assertSame([$newest], $component->instance()->orders()->pluck('reference')->all());
        $this->assertSame(51, $component->instance()->orders()->count() + 50);
    }

    public function test_quote_draft_cannot_be_sent_for_another_order(): void
    {
        $bytes = 'local-development-prescription-bytes';
        $prescription = $this->storedPrescription();
        app(PrescriptionObjectStore::class)->put((string) $prescription->object_key, $bytes);
        $image = $this->fullImageOrder($prescription);
        $catalog = $this->catalogOrder(1);
        $this->quotes()->propose($this->pharmacist, $this->pharmacy, $catalog->public_id, [[
            'medicine_id' => (string) $this->medicine->id,
            'quantity' => 1,
            'unit_price_paisa' => 2500,
        ]]);

        $this->actingAs($this->pharmacist, 'web');
        Filament::setCurrentPanel(Filament::getPanel('pharmacy'));
        Filament::setTenant($this->pharmacy, true);
        $draft = [[
            'medicine_id' => strtolower((string) $this->medicine->id),
            'quantity' => 1,
            'unit_price_paisa' => 50,
        ]];
        $component = Livewire::test(PickupOrders::class);
        $component->set('draftLines', $draft);
        $component->call('proposeQuote', $catalog->public_id);
        $this->assertSame(1, DB::table('order_quotes')->where('order_id', $catalog->id)->count());
        $this->assertSame(2500, (int) DB::table('order_quotes')->where('order_id', $catalog->id)->where('state', 'open')->value('total_paisa'));
        $this->assertSame(0, DB::table('order_quotes')->where('order_id', $image->id)->count());
        $component->assertSet('draftLines', $draft);

        $component->call('selectOrder', $image->public_id);
        $component->assertSee($image->reference, false);
        $component->assertSee('prescription_full_image', false);
        $component->assertSee('Quote version', false);
        $component->set('medicineId', (string) $this->medicine->id);
        $component->set('quantity', '1');
        $component->set('unitPrice', '50');
        $component->call('addLine');
        $component->call('proposeQuote', $catalog->public_id);
        $this->assertSame(1, DB::table('order_quotes')->where('order_id', $catalog->id)->count());
        $this->assertSame(0, DB::table('order_quotes')->where('order_id', $image->id)->count());
        $component->assertSet('draftLines.0.unit_price_paisa', 50);
        $component->assertDontSee('rx/', false);
        $component->set('quoteOrderId', $catalog->public_id);
        $component->assertSet('draftLines', []);
        $component->call('selectOrder', $image->public_id);
        $component->set('medicineId', (string) $this->medicine->id);
        $component->set('quantity', '1');
        $component->set('unitPrice', '50');
        $component->call('addLine');

        $component->call('viewPrescription', $image->public_id)
            ->assertFileDownloaded('prescription', null, 'image/jpeg');
        $downloaded = base64_decode((string) data_get($component->effects, 'download.content'), true);
        $this->assertIsString($downloaded);
        $this->assertSame(hash('sha256', $bytes), hash('sha256', $downloaded));
        $read = DB::table('audit_events')->where('action', 'prescription_file_read')->first();
        $this->assertNotNull($read);
        $this->assertSame([], $this->auditMetadata($read->metadata));

        $component->call('selectOrder', $catalog->public_id);
        $component->assertSet('draftLines', []);
        $component->assertSee($catalog->reference, false);
        $component->assertSee('catalog_items', false);
        $component->assertSee('Paracetamol', false);
        $component->assertSee('Quote version 1', false);
        $component->assertSee('total 2500', false);

        $component->call('selectOrder', $image->public_id);
        $component->set('medicineId', (string) $this->medicine->id);
        $component->set('quantity', '1');
        $component->set('unitPrice', '50');
        $component->call('addLine');
        app(OrderService::class)->cancel($this->customer, $image->public_id, 'Changed my mind');
        $component->call('proposeQuote', $image->public_id);
        $this->assertSame(0, DB::table('order_quotes')->where('order_id', $image->id)->count());
        $component->assertSet('draftLines.0.medicine_id', strtolower((string) $this->medicine->id));
        $component->call('discardDraft');
        $component->assertSet('draftLines', []);

        $other = $this->eligiblePharmacy('Other Quote Pharmacy');
        $outsider = $this->pharmacist($other, 'phase6-quote-outsider@example.test');
        $this->actingAs($outsider, 'web');
        Filament::setTenant($other, true);

        try {
            Livewire::test(PickupOrders::class)->call('viewPrescription', $image->public_id);
            $this->fail('Another pharmacy opened the prescription file.');
        } catch (ApiProblem $exception) {
            $this->assertSame(404, $exception->status);
            $this->assertSame('not_found', $exception->errorCode);
        }

        $this->actingAs($this->customer, 'web');
        Filament::setTenant($this->pharmacy, true);
        $this->assertNull(Livewire::test(PickupOrders::class)->instance());
    }

    public function test_order_file_access_is_limited_to_a_pending_full_image_order(): void
    {
        $bytes = 'phase6-order-file-fixture';
        $prescription = $this->storedPrescription();
        app(PrescriptionObjectStore::class)->put((string) $prescription->object_key, $bytes);
        $pendingShare = app(PrescriptionWorkflow::class)->share($this->customer, $prescription, 'full_image', (string) $this->pharmacy->id);
        $this->putCart([
            'pharmacy_id' => $this->pharmacy->id,
            'mode' => 'prescription_full_image',
            'prescription_id' => $pendingShare->public_id,
            'items' => [],
        ])->assertOk();
        $pendingOrder = Order::query()->where('public_id', $this->submit()->assertCreated()->json('data.id'))->firstOrFail();
        $this->openPickupOrders();
        $this->assertOrderFileDenied($pendingOrder->public_id);
        Livewire::test(ReviewPrescriptions::class)
            ->call('download', $pendingShare->public_id)
            ->assertFileDownloaded('prescription', null, 'image/jpeg');

        app(PrescriptionWorkflow::class)->review($this->pharmacist, $this->pharmacy, $pendingShare->refresh(), (int) $pendingShare->content_version, 'verified', null);
        $order = $pendingOrder->refresh();
        $this->assertOrderFileDownloaded($order->public_id, $bytes);
        $this->assertOrderFileHeaders($order->public_id, $bytes);
        $this->assertOrderFileDenied($this->catalogOrder(1)->public_id);
        $this->assertOrderFileDenied($this->selectedOrder(1)->public_id);

        foreach ([
            OrderStatus::Confirmed,
            OrderStatus::ReadyForPickup,
            OrderStatus::Collected,
            OrderStatus::NotCollected,
            OrderStatus::Rejected,
            OrderStatus::Cancelled,
            OrderStatus::Unfulfillable,
        ] as $status) {
            $copy = $this->replicateFullImage($order);
            $this->forceOrderStatus($copy, $status);
            $this->assertOrderFileDenied($copy->public_id);
        }

        $workflow = app(PrescriptionWorkflow::class);
        $workflow->withdraw($this->customer, $prescription->refresh());
        $order->refresh();
        $this->assertFalse($order->prescription_usable);
        RateLimiter::clear('prescription:share:'.$this->customer->id);
        $reshared = $workflow->share($this->customer, $prescription->refresh(), 'full_image', (string) $this->pharmacy->id);
        $workflow->review($this->pharmacist, $this->pharmacy, $reshared->refresh(), (int) $reshared->content_version, 'verified', null);
        $this->assertSame('verified', $prescription->refresh()->share_state);
        $this->assertSame((int) $order->prescription_content_version, (int) $prescription->content_version);
        $this->assertFalse($order->refresh()->prescription_usable);
        $this->assertOrderFileDenied($order->public_id);

        $versioned = $this->storedPrescription();
        app(PrescriptionObjectStore::class)->put((string) $versioned->object_key, $bytes);
        $versionOrder = $this->fullImageOrder($versioned);
        DB::table('prescriptions')->where('id', $versioned->id)->update([
            'content_version' => (int) $versioned->content_version + 1,
        ]);
        $this->assertOrderFileDenied($versionOrder->public_id);

        $deleted = $this->storedPrescription();
        app(PrescriptionObjectStore::class)->put((string) $deleted->object_key, $bytes);
        $deletedOrder = $this->fullImageOrder($deleted);
        $this->assertTrue($deletedOrder->prescription_usable);
        $this->assertSame(OrderStatus::PendingPharmacyReview, $deletedOrder->status);
        DB::table('prescriptions')->where('id', $deleted->id)->update([
            'deleted_at' => now(),
            'object_state' => 'purge_pending',
        ]);
        $deletedOrder->refresh();
        $deletedRow = DB::table('prescriptions')->where('id', $deleted->id)->first();
        $this->assertNotNull($deletedRow);
        $this->assertNotNull($deletedRow->deleted_at);
        $this->assertSame('purge_pending', $deletedRow->object_state);
        $this->assertSame('verified', $deletedRow->share_state);
        $this->assertTrue($deletedOrder->prescription_usable);
        $this->assertSame(OrderStatus::PendingPharmacyReview, $deletedOrder->status);
        $this->assertOrderFileDenied($deletedOrder->public_id);

        $stored = $this->storedPrescription();
        app(PrescriptionObjectStore::class)->put((string) $stored->object_key, $bytes);
        $storedOrder = $this->fullImageOrder($stored);
        $this->app->instance(PrescriptionObjectStore::class, new class extends PrescriptionObjectStore
        {
            public function get(string $key): string
            {
                throw new ApiProblem('storage_unavailable', 'The prescription file is unavailable.', 503);
            }
        });
        $this->assertStorageFailureDenied($this->openPickupOrders(), $storedOrder->public_id);
        $this->app->forgetInstance(PrescriptionObjectStore::class);
    }

    public function test_order_file_access_denies_the_wrong_actor(): void
    {
        $bytes = 'phase6-order-file-actor';
        $prescription = $this->storedPrescription();
        app(PrescriptionObjectStore::class)->put((string) $prescription->object_key, $bytes);
        $order = $this->fullImageOrder($prescription);

        $component = $this->openPickupOrders();
        PharmacyMembership::query()->where('user_id', $this->pharmacist->id)->update([
            'credential_status' => CredentialStatus::Expired->value,
        ]);
        $this->assertActorFileDenied($component, $order->public_id);
        PharmacyMembership::query()->where('user_id', $this->pharmacist->id)->update([
            'credential_status' => CredentialStatus::Verified->value,
        ]);

        $component = $this->openPickupOrders();
        PharmacyMembership::query()->where('user_id', $this->pharmacist->id)->update([
            'credential_status' => CredentialStatus::Rejected->value,
        ]);
        $this->assertActorFileDenied($component, $order->public_id);
        PharmacyMembership::query()->where('user_id', $this->pharmacist->id)->update([
            'credential_status' => CredentialStatus::Verified->value,
        ]);

        $component = $this->openPickupOrders();
        PharmacyMembership::query()->where('user_id', $this->pharmacist->id)->update([
            'status' => MembershipStatus::Suspended->value,
            'credential_status' => CredentialStatus::Unverified->value,
        ]);
        $this->assertActorFileDenied($component, $order->public_id);
        PharmacyMembership::query()->where('user_id', $this->pharmacist->id)->update([
            'status' => MembershipStatus::Active->value,
            'credential_status' => CredentialStatus::Verified->value,
        ]);

        $component = $this->openPickupOrders();
        $this->pharmacist->forceFill(['status' => AccountStatus::Suspended->value])->save();
        $this->assertActorFileDenied($component, $order->public_id);
        $this->pharmacist->forceFill(['status' => AccountStatus::Active->value])->save();

        $component = $this->openPickupOrders();
        app(PharmacyWorkflow::class)->suspend($this->admin, $this->pharmacy->refresh(), 'Counter is closed');
        $this->assertActorFileDenied($component, $order->public_id);
        app(PharmacyWorkflow::class)->reinstate($this->admin, $this->pharmacy->refresh());
        $this->assertOrderFileDownloaded($order->public_id, $bytes);

        $other = $this->eligiblePharmacy('File Access Other Pharmacy');
        $outsider = $this->pharmacist($other, 'phase6-file-outsider@example.test');
        $this->actingAs($outsider, 'web');
        Filament::setCurrentPanel(Filament::getPanel('pharmacy'));
        Filament::setTenant($other, true);
        $this->assertActorFileDenied(Livewire::test(PickupOrders::class), $order->public_id);

        $beforeCustomer = $this->fileReadCount();
        $this->actingAs($this->customer, 'web');
        Filament::setCurrentPanel(Filament::getPanel('pharmacy'));
        Filament::setTenant($this->pharmacy, true);
        $customerPage = Livewire::test(PickupOrders::class);
        $this->assertNull($customerPage->instance());
        $this->assertSame(403, $this->livewireStatus($customerPage));
        $customerPage->assertNoFileDownloaded();
        $this->assertSame($beforeCustomer, $this->fileReadCount());
        $this->assertDirectOrderFileDenied($order->public_id);

        $this->assertSame(1, $this->fileReadCount());
    }

    public function test_reviewed_line_editor_keeps_the_ten_line_limit(): void
    {
        $order = $this->catalogOrder(1);
        $limit = (int) config('medifind.discovery.max_medicines');
        $this->assertSame(10, $limit);
        $this->actingAs($this->pharmacist, 'web');
        Filament::setCurrentPanel(Filament::getPanel('pharmacy'));
        Filament::setTenant($this->pharmacy, true);
        $component = Livewire::test(PickupOrders::class);
        $component->call('selectOrder', $order->public_id);
        $ids = [];

        for ($index = 0; $index < $limit + 1; $index++) {
            $id = (string) Str::uuid();
            $ids[] = $id;
            $component->set('medicineId', $id);
            $component->set('quantity', '1');
            $component->set('unitPrice', '0');
            $component->call('addLine');
        }

        $this->assertCount($limit, $component->get('draftLines'));
        $this->assertSame($ids[0], $component->get('draftLines')[0]['medicine_id']);
        $this->assertSame($ids[$limit - 1], $component->get('draftLines')[$limit - 1]['medicine_id']);
        $component->assertSee('at most '.$limit, false);
    }

    public function test_full_image_quote_stops_at_the_cart_medicine_limit(): void
    {
        $limit = (int) config('medifind.discovery.max_medicines');
        $this->assertSame(10, $limit);
        $lines = [];

        for ($index = 1; $index <= $limit + 1; $index++) {
            $medicine = $this->medicine('Reviewed '.$index, '10 mg', 'Tablet', 'Example Labs', 'Reviewed '.$index);
            $this->stockMedicine($medicine, 4, 100);
            $lines[] = [
                'medicine_id' => (string) $medicine->id,
                'quantity' => 1,
                'unit_price_paisa' => 100,
            ];
        }

        $accepted = $this->fullImageOrder($this->storedPrescription());
        $quote = $this->quotes()->propose($this->pharmacist, $this->pharmacy, $accepted->public_id, array_slice($lines, 0, $limit));
        $this->assertCount($limit, $quote->lines);
        $blocked = $this->replicateFullImage($accepted);
        $before = $this->fulfillmentSideEffects();

        try {
            $this->quotes()->propose($this->pharmacist, $this->pharmacy, $blocked->public_id, $lines);
            $this->fail('An eleventh reviewed line was stored.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('at most '.$limit, (string) $exception->validator->errors()->first('lines'));
        }

        $this->assertSame($before, $this->fulfillmentSideEffects());
        $this->assertSame(0, DB::table('order_quotes')->where('order_id', $blocked->id)->count());
        $this->assertSame(1, DB::table('order_quotes')->where('order_id', $accepted->id)->count());
    }

    public function test_pickup_window_check_rejects_incomplete_rows(): void
    {
        $seed = $this->catalogOrder(1);
        $fields = [
            'pickup_window_starts_at',
            'pickup_window_ends_at',
            'pickup_grace_seconds',
            'collect_until',
        ];

        foreach (['ready_for_pickup', 'collected', 'not_collected'] as $status) {
            $cleared = $this->windowShape($status);
            foreach ($fields as $field) {
                $cleared[$field] = null;
            }
            $this->assertWindowRejected($seed, $cleared);

            foreach ($fields as $field) {
                $partial = $this->windowShape($status);
                $partial[$field] = null;
                $this->assertWindowRejected($seed, $partial);
            }
        }

        foreach ($fields as $field) {
            $partial = $this->windowShape('unfulfillable');
            $partial[$field] = null;
            $this->assertWindowRejected($seed, $partial);
        }

        $equalEnds = $this->windowShape('ready_for_pickup');
        $equalEnds['pickup_window_ends_at'] = $equalEnds['pickup_window_starts_at'];
        $this->assertWindowRejected($seed, $equalEnds);
        $zeroGrace = $this->windowShape('ready_for_pickup');
        $zeroGrace['pickup_grace_seconds'] = 0;
        $zeroGrace['collect_until'] = $zeroGrace['pickup_window_ends_at'];
        $this->assertWindowRejected($seed, $zeroGrace);
        $wrongUntil = $this->windowShape('ready_for_pickup');
        $wrongUntil['collect_until'] = '2026-10-02 10:00:01+00';
        $this->assertWindowRejected($seed, $wrongUntil);
        $pendingWindow = $this->windowShape('pending_pharmacy_review');
        $pendingWindow['confirmed_total_paisa'] = null;
        $this->assertWindowRejected($seed, $pendingWindow);

        foreach (['ready_for_pickup', 'collected', 'not_collected', 'unfulfillable'] as $status) {
            $this->assertWindowAccepted($seed, $this->windowShape($status));
        }

        $this->assertWindowAccepted($seed, [
            'status' => 'unfulfillable',
            'confirmed_total_paisa' => 2500,
            'payment_status' => 'unpaid',
            'cash_collected_paisa' => null,
            'pickup_window_starts_at' => null,
            'pickup_window_ends_at' => null,
            'pickup_grace_seconds' => null,
            'collect_until' => null,
        ]);
        $this->assertWindowAccepted($seed, [
            'status' => 'confirmed',
            'confirmed_total_paisa' => 2500,
            'payment_status' => 'unpaid',
            'cash_collected_paisa' => null,
            'pickup_window_starts_at' => null,
            'pickup_window_ends_at' => null,
            'pickup_grace_seconds' => null,
            'collect_until' => null,
        ]);
    }

    private function assertForbidden(User $actor, Pharmacy $pharmacy, string $publicId): void
    {
        try {
            $this->fulfillment()->confirm($actor, $pharmacy, $publicId);
            $this->fail('An unauthorized account confirmed an order.');
        } catch (ApiProblem $exception) {
            $this->assertSame('forbidden', $exception->errorCode);
        }
    }

    private function fulfillment(): FulfillmentService
    {
        return app(FulfillmentService::class);
    }

    private function quotes(): QuoteService
    {
        return app(QuoteService::class);
    }

    private function catalogOrder(int $quantity): Order
    {
        $this->putCart([
            'pharmacy_id' => $this->pharmacy->id,
            'mode' => 'catalog_items',
            'customer_note' => 'original note',
            'items' => [['medicine_id' => $this->medicine->id, 'quantity' => $quantity]],
        ])->assertOk();
        $version = (int) $this->withToken($this->token)->getJson('/api/v1/cart')->json('data.version');
        $created = $this->submit($version)->assertCreated();

        return Order::query()->where('public_id', $created->json('data.id'))->firstOrFail();
    }

    private function readyCatalog(int $quantity): Order
    {
        return $this->markReady($this->confirmCatalog($quantity));
    }

    private function confirmCatalog(int $quantity): Order
    {
        $order = $this->catalogOrder($quantity);
        $this->fulfillment()->confirm($this->pharmacist, $this->pharmacy, $order->public_id);

        return $order->refresh();
    }

    private function clockAfterInventoryVerification(): Carbon
    {
        $verified = Carbon::parse((string) DB::table('pharmacy_inventory')->where('medicine_id', $this->medicine->id)->value('last_verified_at'))->utc();
        $clock = $verified->copy()->addSecond();
        Carbon::setTestNow($clock);

        return $clock;
    }

    /**
     * @return array{start: string, end: string, afterGrace: string}
     */
    private function pickupAfter(Carbon $clock): array
    {
        $start = $clock->copy()->timezone('Asia/Karachi')->addHours(2)->addMinute()->startOfMinute();
        $end = $start->copy()->addHours(3);
        $afterGrace = $end->copy()->utc()->addSeconds((int) config('medifind.orders.pickup_grace_seconds') + 1);

        return [
            'start' => $start->format('Y-m-d H:i'),
            'end' => $end->format('Y-m-d H:i'),
            'afterGrace' => $afterGrace->utc()->toIso8601String(),
        ];
    }

    private function markReady(Order $order): Order
    {
        Carbon::setTestNow(Carbon::parse('2026-10-01 09:00:00', 'UTC'));
        $ready = $this->fulfillment()->markReady(
            $this->pharmacist,
            $this->pharmacy,
            $order->public_id,
            '2026-10-01 15:00',
            '2026-10-01 18:00',
        );
        Carbon::setTestNow(Carbon::parse((string) $ready->pickup_window_starts_at)->utc());

        return $ready;
    }

    private function selectedOrder(int $quantity): Order
    {
        $workflow = app(PrescriptionWorkflow::class);
        $prescription = $this->storedPrescription();
        $item = new PrescriptionItem;
        $item->forceFill([
            'prescription_id' => $prescription->id,
            'extraction_version' => $prescription->extraction_version,
            'line_index' => 0,
            'extracted_text' => 'not-a-catalog-line',
            'match_status' => 'exact',
            'medicine_id' => $this->medicine->id,
            'customer_selected' => false,
        ])->save();
        $workflow->correct($this->customer, $prescription, $item, null, (string) $this->medicine->id, true);
        $shared = $workflow->share($this->customer, $prescription->refresh(), 'selected_items', (string) $this->pharmacy->id);
        $workflow->review($this->pharmacist, $this->pharmacy, $shared->refresh(), (int) $shared->content_version, 'verified', null);
        $this->putCart([
            'pharmacy_id' => $this->pharmacy->id,
            'mode' => 'prescription_selected_items',
            'prescription_id' => $shared->public_id,
            'customer_note' => 'original note',
            'items' => [['medicine_id' => $this->medicine->id, 'quantity' => $quantity]],
        ])->assertOk();
        $version = (int) $this->withToken($this->token)->getJson('/api/v1/cart')->json('data.version');
        $created = $this->submit($version)->assertCreated();

        return Order::query()->where('public_id', $created->json('data.id'))->firstOrFail();
    }

    private function confirmedSelected(int $quantity): Order
    {
        $order = $this->selectedOrder($quantity);
        $this->fulfillment()->confirm($this->pharmacist, $this->pharmacy, $order->public_id);

        return $order->refresh();
    }

    private function submit(?int $version = null): TestResponse
    {
        return $this->withToken($this->token)->withHeader('Idempotency-Key', bin2hex(random_bytes(8)))->postJson('/api/v1/orders', [
            'cart_version' => $version ?? (int) DB::table('customer_carts')->value('version'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function putCart(array $body): TestResponse
    {
        return $this->withToken($this->token)->putJson('/api/v1/cart', $body);
    }

    private function reprice(int $price): void
    {
        $version = (int) DB::table('pharmacy_inventory')->where('medicine_id', $this->medicine->id)->value('version');
        app(InventoryBook::class)->save($this->admin, $this->pharmacy, [
            'medicine_id' => (string) $this->medicine->id,
            'stock_status' => 'in_stock',
            'quantity_on_hand' => 4,
            'unit_price_paisa' => $price,
            'is_available_for_pickup' => true,
            'version' => $version,
        ]);
    }

    private function stock(int $quantity, int $price): void
    {
        $this->stockMedicine($this->medicine, $quantity, $price);
    }

    private function stockMedicine(Medicine $medicine, int $quantity, int $price): void
    {
        app(InventoryBook::class)->save($this->admin, $this->pharmacy, [
            'medicine_id' => (string) $medicine->id,
            'stock_status' => 'in_stock',
            'quantity_on_hand' => $quantity,
            'unit_price_paisa' => $price,
            'is_available_for_pickup' => true,
        ]);
    }

    private function onHand(): int
    {
        return (int) DB::table('pharmacy_inventory')->where('medicine_id', $this->medicine->id)->value('quantity_on_hand');
    }

    private function available(): int
    {
        $reserved = (int) DB::table('order_reservations')
            ->where('medicine_id', $this->medicine->id)
            ->where('state', 'active')
            ->sum('quantity');

        return $this->onHand() - $reserved;
    }

    /**
     * @return list<string>
     */
    private function states(Order $order): array
    {
        return DB::table('order_reservations')->where('order_id', $order->id)->orderBy('id')->pluck('state')->all();
    }

    private function storedPrescription(): Prescription
    {
        $row = new Prescription;
        $row->forceFill([
            'customer_id' => $this->customer->id,
            'object_key' => 'rx/'.Str::uuid()->toString(),
            'display_name' => 'prescription',
            'mime_type' => 'image/jpeg',
            'byte_size' => 128,
            'sha256' => hash('sha256', Str::uuid()->toString()),
            'extraction_state' => 'queued',
            'extraction_version' => 1,
            'content_version' => 1,
            'share_state' => 'none',
            'object_state' => 'stored',
        ])->save();

        return $row->refresh();
    }

    private function pharmacist(Pharmacy $pharmacy, string $email): User
    {
        $workflow = app(PharmacyWorkflow::class);
        $staff = $this->user(UserRole::PharmacyStaff, $email, null);
        $staff->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $staff->saveAppAuthenticationRecoveryCodes(['recovery-code-phase6']);
        $membership = $workflow->assignPharmacist($this->admin, $pharmacy, $staff, 'PH-'.Str::upper(Str::random(6)));
        $workflow->verifyCredential($this->admin, $membership, 'Reviewed pharmacist certificate');

        return $staff;
    }

    private function eligiblePharmacy(string $name): Pharmacy
    {
        $workflow = app(PharmacyWorkflow::class);
        $pharmacy = $workflow->create($this->admin, [
            'name' => $name,
            'address_text' => $name.' Susan Road',
            'latitude' => '31.450400',
            'longitude' => '73.135000',
            'license_number' => 'LIC-'.Str::upper(Str::random(8)),
            'phone_e164' => '+92300'.random_int(1000000, 9999999),
        ]);
        $workflow->verifyLicense($this->admin, $pharmacy, 'Reviewed license file '.$name);
        $workflow->approveLocation($this->admin, $pharmacy, 'Reviewed map pin');
        $workflow->approve($this->admin, $pharmacy->refresh());

        return $pharmacy->refresh();
    }

    private function medicine(string $generic, string $strength, string $form, string $manufacturer, ?string $brand): Medicine
    {
        $catalog = app(MedicineCatalog::class);
        $medicine = $catalog->create($this->admin, [
            'generic_name' => $generic,
            'dosage_form' => $form,
            'strength' => $strength,
            'manufacturer' => $manufacturer,
            'brand_name' => $brand,
            'prescription_required' => false,
            'controlled_drug' => false,
        ]);
        $catalog->activate($this->admin, $medicine);

        return $medicine->refresh();
    }

    private function user(UserRole $role, ?string $email, ?string $phone): User
    {
        return User::query()->forceCreate([
            'public_id' => (string) Str::uuid(),
            'email' => $email,
            'phone_e164' => $phone,
            'password' => $role === UserRole::Customer ? null : 'not-a-login-secret',
            'role' => $role,
            'status' => AccountStatus::Active,
        ]);
    }

    private function openPickupOrders()
    {
        $this->pharmacist->refresh();
        $this->pharmacy->refresh();
        $this->actingAs($this->pharmacist, 'web');
        Filament::setCurrentPanel(Filament::getPanel('pharmacy'));
        Filament::setTenant($this->pharmacy, true);

        return Livewire::test(PickupOrders::class);
    }

    private function assertOrderFileDownloaded(string $publicId, string $bytes): void
    {
        $before = $this->fileReadCount();
        $component = $this->openPickupOrders();
        $component->call('viewPrescription', $publicId)
            ->assertFileDownloaded('prescription', null, 'image/jpeg');
        $downloaded = base64_decode((string) data_get($component->effects, 'download.content'), true);
        $this->assertIsString($downloaded);
        $this->assertSame(hash('sha256', $bytes), hash('sha256', $downloaded));
        $this->assertSame($before + 1, $this->fileReadCount());
        $metadata = DB::table('audit_events')->where('action', 'prescription_file_read')->orderByDesc('created_at')->value('metadata');
        $this->assertSame([], $this->auditMetadata($metadata));
    }

    private function assertOrderFileHeaders(string $publicId, string $bytes): void
    {
        $pharmacy = $this->pharmacy;
        $this->openPickupOrders();
        Route::middleware('web')->get('/_phase6_order_file/{publicId}', function (string $publicId) use ($pharmacy) {
            Filament::setCurrentPanel(Filament::getPanel('pharmacy'));
            Filament::setTenant($pharmacy, true);

            return (new PickupOrders)->viewPrescription($publicId);
        });
        $response = $this->get('/_phase6_order_file/'.$publicId);
        $response->assertOk();
        $this->assertStringStartsWith('attachment;', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('filename="prescription"', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertFalse($response->isRedirect());
        $this->assertSame(hash('sha256', $bytes), hash('sha256', $response->streamedContent()));
    }

    private function assertOrderFileDenied(string $publicId): void
    {
        $before = $this->fileReadCount();
        $component = $this->openPickupOrders();
        $this->assertNotNull($component->instance());

        try {
            $component->call('viewPrescription', $publicId);
        } catch (ApiProblem $exception) {
            $this->assertSame(404, $exception->status);
            $this->assertSame('not_found', $exception->errorCode);
            $this->assertNoExposedObjectKey($exception->getMessage());
            $component->assertNoFileDownloaded();
            $this->assertSame($before, $this->fileReadCount());

            return;
        } catch (\Throwable $exception) {
            $this->fail('Unexpected denial exception: '.$exception::class);
        }

        $component->assertNoFileDownloaded();
        $status = $this->livewireStatus($component);
        if ($status === 200) {
            $this->fail('The Livewire request succeeded without a denial.');
        }

        $this->fail('viewPrescription did not deny the order. Livewire status '.$status.'.');
    }

    /**
     * A mounted page can be rejected by Filament's hydrate access check before
     * viewPrescription() runs. Livewire's test client keeps that HttpException
     * as a 403 response, so call() does not throw. The direct call then proves
     * the action's own persisted-state check.
     */
    private function assertActorFileDenied(mixed $component, string $publicId): void
    {
        $before = $this->fileReadCount();
        $this->assertNotNull($component->instance());

        try {
            $component->call('viewPrescription', $publicId);
        } catch (ApiProblem $exception) {
            $this->assertSame(404, $exception->status);
            $this->assertSame('not_found', $exception->errorCode);
            $this->assertNoExposedObjectKey($exception->getMessage());
            $component->assertNoFileDownloaded();
            $this->assertSame($before, $this->fileReadCount());
            $this->assertDirectOrderFileDenied($publicId);

            return;
        } catch (\Throwable $exception) {
            $this->fail('Unexpected denial exception: '.$exception::class);
        }

        $component->assertNoFileDownloaded();
        $this->assertSame($before, $this->fileReadCount());
        $status = $this->livewireStatus($component);
        if ($status === 200) {
            $this->fail('The Livewire request succeeded without a denial.');
        }

        $this->assertSame(403, $status);
        $this->assertDirectOrderFileDenied($publicId);
    }

    private function assertDirectOrderFileDenied(string $publicId): void
    {
        $before = $this->fileReadCount();

        try {
            (new PickupOrders)->viewPrescription($publicId);
            $this->fail('viewPrescription returned a file.');
        } catch (ApiProblem $exception) {
            $this->assertSame(404, $exception->status);
            $this->assertSame('not_found', $exception->errorCode);
            $this->assertNoExposedObjectKey($exception->getMessage());
        } catch (\Throwable $exception) {
            $this->fail('Unexpected direct denial exception: '.$exception::class);
        }

        $this->assertSame($before, $this->fileReadCount());
    }

    private function assertStorageFailureDenied(mixed $component, string $publicId): void
    {
        $before = $this->fileReadCount();

        try {
            $component->call('viewPrescription', $publicId);
        } catch (ApiProblem $exception) {
            $this->assertSame(503, $exception->status);
            $this->assertSame('storage_unavailable', $exception->errorCode);
            $this->assertNoExposedObjectKey($exception->getMessage());
            $component->assertNoFileDownloaded();
            $this->assertSame($before, $this->fileReadCount());

            return;
        } catch (\Throwable $exception) {
            $this->fail('Unexpected storage exception: '.$exception::class);
        }

        $component->assertNoFileDownloaded();
        $response = \Livewire\invade($component)->lastState->getResponse();
        $status = $response->getStatusCode();
        if ($status === 200) {
            $this->fail('A storage failure returned a successful response.');
        }

        $this->assertSame(503, $status);
        $this->assertNoExposedObjectKey((string) $response->getContent());
        $this->assertSame($before, $this->fileReadCount());
    }

    private function livewireStatus(mixed $component): int
    {
        return \Livewire\invade($component)->lastState->getResponse()->getStatusCode();
    }

    private function assertNoExposedObjectKey(string $value): void
    {
        if (str_contains($value, 'rx/')) {
            $this->fail('A response exposed an object key.');
        }
    }

    private function forceOrderStatus(Order $order, OrderStatus $status): void
    {
        if ($status === OrderStatus::Rejected || $status === OrderStatus::Cancelled) {
            DB::table('orders')->where('id', $order->id)->update(['status' => $status->value]);

            return;
        }

        if ($status === OrderStatus::Confirmed || $status === OrderStatus::Unfulfillable) {
            DB::table('orders')->where('id', $order->id)->update([
                'status' => $status->value,
                'confirmed_total_paisa' => 0,
            ]);

            return;
        }

        DB::table('orders')->where('id', $order->id)->update([
            'status' => OrderStatus::ReadyForPickup->value,
            'confirmed_total_paisa' => 2500,
            'pickup_window_starts_at' => '2026-10-02 05:00:00+00',
            'pickup_window_ends_at' => '2026-10-02 08:00:00+00',
            'pickup_grace_seconds' => 7200,
            'collect_until' => '2026-10-02 10:00:00+00',
        ]);

        if ($status === OrderStatus::Collected) {
            DB::table('orders')->where('id', $order->id)->update([
                'status' => OrderStatus::Collected->value,
                'payment_status' => 'paid',
                'cash_collected_paisa' => 2500,
            ]);
        }

        if ($status === OrderStatus::NotCollected) {
            DB::table('orders')->where('id', $order->id)->update([
                'status' => OrderStatus::NotCollected->value,
            ]);
        }
    }

    private function fileReadCount(): int
    {
        return (int) DB::table('audit_events')->where('action', 'prescription_file_read')->count();
    }

    private function auditMetadata(mixed $metadata): array
    {
        if (is_array($metadata)) {
            return $metadata;
        }

        $decoded = json_decode((string) $metadata, true);

        return is_array($decoded) ? $decoded : ['unreadable'];
    }

    private function fullImageOrder(Prescription $prescription): Order
    {
        $workflow = app(PrescriptionWorkflow::class);
        $shared = $workflow->share($this->customer, $prescription, 'full_image', (string) $this->pharmacy->id);
        $workflow->review($this->pharmacist, $this->pharmacy, $shared->refresh(), (int) $shared->content_version, 'verified', null);
        $this->putCart([
            'pharmacy_id' => $this->pharmacy->id,
            'mode' => 'prescription_full_image',
            'prescription_id' => $shared->public_id,
            'items' => [],
        ])->assertOk();
        $created = $this->submit()->assertCreated();

        return Order::query()->where('public_id', $created->json('data.id'))->firstOrFail();
    }

    private function replicateFullImage(Order $seed): Order
    {
        $id = (string) Str::uuid();
        $this->replicateOrder($seed, substr(str_replace('-', '', (string) Str::uuid()), 0, 16), $id, now()->format('Y-m-d H:i:s'));

        return Order::query()->findOrFail($id);
    }

    /**
     * @return array{status: string, payment: string, cash: mixed, states: list<string>, on_hand: int, events: int, audits: int}
     */
    private function snapshotPickup(Order $order): array
    {
        $order->refresh();

        return [
            'status' => $order->status->value,
            'payment' => (string) $order->payment_status,
            'cash' => $order->cash_collected_paisa,
            'states' => $this->states($order),
            'on_hand' => $this->onHand(),
            'events' => (int) DB::table('order_status_events')->where('order_id', $order->id)->count(),
            'audits' => (int) DB::table('audit_events')->where('subject_id', (string) $order->id)->count(),
        ];
    }

    /**
     * @return array{quotes: int, acceptances: int, reservations: int, events: int, audits: int}
     */
    private function fulfillmentSideEffects(): array
    {
        return [
            'quotes' => DB::table('order_quotes')->count(),
            'acceptances' => DB::table('order_quote_acceptances')->count(),
            'reservations' => DB::table('order_reservations')->count(),
            'events' => DB::table('order_status_events')->count(),
            'audits' => DB::table('audit_events')->count(),
        ];
    }

    private function replicateOrder(Order $seed, string $reference, string $id, string $createdAt, ?string $pharmacyId = null): void
    {
        $row = (array) DB::table('orders')->where('id', $seed->id)->first();
        $row['id'] = $id;
        $row['public_id'] = (string) Str::uuid();
        $row['reference'] = $reference;
        $row['created_at'] = $createdAt;
        $row['updated_at'] = $createdAt;

        if ($pharmacyId !== null) {
            $row['pharmacy_id'] = $pharmacyId;
        }

        DB::table('orders')->insert($row);

        if ($row['request_mode'] === 'prescription_full_image') {
            return;
        }

        foreach (DB::table('order_items')->where('order_id', $seed->id)->get() as $item) {
            $copy = (array) $item;
            $copy['id'] = (string) Str::uuid();
            $copy['order_id'] = $id;
            DB::table('order_items')->insert($copy);
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function insertShapedOrder(Order $seed, array $overrides): string
    {
        $row = (array) DB::table('orders')->where('id', $seed->id)->first();
        $id = (string) Str::uuid();
        $row['id'] = $id;
        $row['public_id'] = (string) Str::uuid();
        $row['reference'] = substr(str_replace('-', '', (string) Str::uuid()), 0, 16);
        $row['created_at'] = now()->format('Y-m-d H:i:s');
        $row['updated_at'] = $row['created_at'];

        foreach ($overrides as $column => $value) {
            $row[$column] = $value;
        }

        DB::table('orders')->insert($row);

        if ($row['request_mode'] !== 'prescription_full_image') {
            foreach (DB::table('order_items')->where('order_id', $seed->id)->get() as $item) {
                $copy = (array) $item;
                $copy['id'] = (string) Str::uuid();
                $copy['order_id'] = $id;
                DB::table('order_items')->insert($copy);
            }
        }

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function windowShape(string $status): array
    {
        $shape = [
            'status' => $status,
            'confirmed_total_paisa' => 2500,
            'payment_status' => 'unpaid',
            'cash_collected_paisa' => null,
            'pickup_window_starts_at' => '2026-10-02 05:00:00+00',
            'pickup_window_ends_at' => '2026-10-02 08:00:00+00',
            'pickup_grace_seconds' => 7200,
            'collect_until' => '2026-10-02 10:00:00+00',
        ];

        if ($status === 'collected') {
            $shape['payment_status'] = 'paid';
            $shape['cash_collected_paisa'] = 2500;
        }

        return $shape;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function assertWindowRejected(Order $seed, array $overrides): void
    {
        DB::statement('SAVEPOINT window_probe');

        try {
            $this->insertShapedOrder($seed, $overrides);
            DB::statement('ROLLBACK TO SAVEPOINT window_probe');
            $this->fail('An incomplete pickup window was stored for '.$overrides['status'].'.');
        } catch (QueryException $exception) {
            DB::statement('ROLLBACK TO SAVEPOINT window_probe');
            $this->assertStringContainsString('orders_pickup_window_check', $exception->getMessage());
        }

        DB::statement('RELEASE SAVEPOINT window_probe');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function assertWindowAccepted(Order $seed, array $overrides): void
    {
        $id = $this->insertShapedOrder($seed, $overrides);
        $this->assertNotNull(DB::table('orders')->where('id', $id)->value('status'));
    }
}
