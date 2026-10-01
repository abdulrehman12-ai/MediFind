<?php

namespace Tests\Feature;

use App\Catalog\MedicineCatalog;
use App\Enums\AccountStatus;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Inventory\InventoryBook;
use App\Models\Medicine;
use App\Models\Order;
use App\Models\Pharmacy;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\User;
use App\Orders\OrderPrescriptionGuard;
use App\Pharmacy\PharmacyWorkflow;
use App\Prescriptions\PrescriptionObjectStore;
use App\Prescriptions\PrescriptionWorkflow;
use App\Prescriptions\SelectionIdentity;
use Database\Seeders\CitySeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

class PickupOrderingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $customer;

    private string $token;

    private Pharmacy $pharmacy;

    private Medicine $medicine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CitySeeder::class);
        $this->admin = $this->user(UserRole::Admin, 'admin-orders@example.test', null);
        $this->customer = $this->customerUser('orders@example.test', '+923001110501');
        $this->token = $this->token($this->customer);
        $this->pharmacy = $this->eligiblePharmacy('Order Pharmacy');
        $this->medicine = $this->medicine('Paracetamol', '500 mg', 'Tablet', 'Example Labs', 'Example', false);
        $this->stock($this->pharmacy, $this->medicine, 'in_stock', 8, 2500, true);
        $this->assertSame(10, (int) config('medifind.discovery.max_medicines'));
    }

    public function test_estimate_at_the_database_limit_submits_and_above_it_does_not(): void
    {
        $inventoryVersion = (int) DB::table('pharmacy_inventory')->where('medicine_id', $this->medicine->id)->value('version');
        app(InventoryBook::class)->save($this->admin, $this->pharmacy, [
            'medicine_id' => (string) $this->medicine->id,
            'stock_status' => 'in_stock',
            'quantity_on_hand' => 20,
            'unit_price_paisa' => 1000000000,
            'is_available_for_pickup' => true,
            'version' => $inventoryVersion,
        ]);

        $this->putCart($this->cartBody(null, 10))->assertOk();
        $created = $this->submit(1)->assertCreated();
        $created->assertJsonPath('data.estimate_total_paisa', 10000000000);
        $this->assertSame(10000000000, (int) DB::table('orders')->value('estimate_total_paisa'));

        $this->putCart($this->cartBody(null, 11))->assertOk();
        $cartVersion = (int) DB::table('customer_carts')->value('version');
        $orders = DB::table('orders')->count();
        $submissions = DB::table('order_submissions')->count();
        $events = DB::table('order_status_events')->count();
        $audits = DB::table('audit_events')->where('action', 'order_submitted')->count();

        $this->submit($cartVersion)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'order_estimate_too_large');

        $this->assertSame($cartVersion, (int) DB::table('customer_carts')->value('version'));
        $this->assertSame(11, (int) DB::table('customer_cart_items')->value('quantity'));
        $this->assertSame($orders, DB::table('orders')->count());
        $this->assertSame($submissions, DB::table('order_submissions')->count());
        $this->assertSame($events, DB::table('order_status_events')->count());
        $this->assertSame($audits, DB::table('audit_events')->where('action', 'order_submitted')->count());
    }

    public function test_catalog_checkout_is_pending_and_does_not_reserve_stock(): void
    {
        $note = 'Leave the sealed pack at the counter.';
        $this->putCart($this->cartBody($note, 2))->assertOk()->assertJsonPath('data.version', 1);
        $beforeQuantity = (int) DB::table('pharmacy_inventory')->where('medicine_id', $this->medicine->id)->value('quantity_on_hand');
        $beforeVersion = (int) DB::table('pharmacy_inventory')->where('medicine_id', $this->medicine->id)->value('version');
        $created = $this->submit(1)->assertCreated();
        $created->assertJsonPath('data.status', 'pending_pharmacy_review');
        $created->assertJsonPath('data.payment_method', 'cash_on_pickup');
        $created->assertJsonPath('data.payment_status', 'unpaid');
        $created->assertJsonPath('data.amounts_are_estimates', true);
        $created->assertJsonPath('data.estimate_total_paisa', 5000);
        $created->assertJsonPath('data.confirmed_total_paisa', null);
        $created->assertJsonPath('data.pickup_window', null);
        $created->assertJsonPath('data.cash_collected_paisa', null);
        $created->assertJsonPath('data.customer_note', $note);
        $created->assertJsonPath('data.items.0.quantity', 2);
        $created->assertJsonPath('data.items.0.generic_name', 'Paracetamol');
        $created->assertJsonPath('data.timeline.0.event', 'submitted');
        $created->assertJsonPath('data.timeline.0.actor', 'customer');
        $notices = $created->json('data.notices');
        $this->assertIsArray($notices);
        $this->assertContains('Awaiting pharmacist review.', $notices);
        $this->assertContains('The price is an estimate until the pharmacy confirms the order.', $notices);
        $this->assertContains('Cash is paid at pickup, not at checkout.', $notices);
        $this->assertMatchesRegularExpression('/^MF[A-Z2-9]{8}$/', (string) $created->json('data.reference'));
        $this->assertSame($beforeQuantity, (int) DB::table('pharmacy_inventory')->where('medicine_id', $this->medicine->id)->value('quantity_on_hand'));
        $this->assertSame($beforeVersion, (int) DB::table('pharmacy_inventory')->where('medicine_id', $this->medicine->id)->value('version'));
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('inventory_reservations'));
        $this->assertSame(OrderStatus::PendingPharmacyReview, Order::query()->firstOrFail()->status);
        $this->assertNull(app(OrderPrescriptionGuard::class)->prescriptionConfirmationBlock(Order::query()->firstOrFail()));
        $this->assertSame(OrderStatus::PendingPharmacyReview, Order::query()->firstOrFail()->status);
        $this->withToken($this->token)->getJson('/api/v1/cart')->assertOk()->assertJsonPath('data', null);

        foreach (Route::getRoutes() as $route) {
            $this->assertStringNotContainsString('confirm', $route->uri());
        }
    }

    public function test_selected_items_and_full_image_checkout(): void
    {
        $selected = $this->shareSelected($this->customer, $this->pharmacy, $this->medicine);
        $this->putCart([
            'pharmacy_id' => $this->pharmacy->id,
            'mode' => 'prescription_selected_items',
            'prescription_id' => $selected->public_id,
            'items' => [['medicine_id' => $this->medicine->id, 'quantity' => 1]],
        ])->assertOk();
        $order = $this->submit(1)->assertCreated();
        $order->assertJsonPath('data.request_mode', 'prescription_selected_items');
        $order->assertJsonPath('data.prescription_id', $selected->public_id);
        $order->assertJsonPath('data.items.0.generic_name', 'Paracetamol');
        $order->assertJsonPath('data.status', 'pending_pharmacy_review');
        $this->assertNotSame('not-a-catalog-line', $order->json('data.items.0.generic_name'));

        $image = $this->shareFullImage($this->customer, $this->pharmacy);
        $unselected = new PrescriptionItem;
        $unselected->forceFill([
            'prescription_id' => $image->id,
            'extraction_version' => $image->extraction_version,
            'line_index' => 0,
            'extracted_text' => 'Unread line that must not become an order',
            'match_status' => 'unmatched',
            'customer_selected' => false,
        ])->save();
        $this->putCart([
            'version' => null,
            'pharmacy_id' => $this->pharmacy->id,
            'mode' => 'prescription_full_image',
            'prescription_id' => $image->public_id,
            'items' => [],
        ])->assertOk()->assertJsonPath('data.items', []);
        $full = $this->submit((int) $this->withToken($this->token)->getJson('/api/v1/cart')->json('data.version'))->assertCreated();
        $full->assertJsonPath('data.items', []);
        $full->assertJsonPath('data.estimate_total_paisa', null);
        $full->assertJsonPath('data.status', 'pending_pharmacy_review');
        $this->assertContains('Sharing the prescription image does not mean its contents have been identified or are in stock.', $full->json('data.notices'));
    }

    public function test_exact_selection_identity_is_rechecked_and_legacy_fingerprints_fail(): void
    {
        $identity = app(SelectionIdentity::class);
        $medicine = $this->medicine('Selection Salt', '500 mg', 'Tablet', 'Example Labs', 'Selecta', true);
        $this->stock($this->pharmacy, $medicine, 'in_stock', 6, 3200, true);
        $changes = [
            'brand_name' => 'Other Brand',
            'generic_name' => 'Acetaminophen',
            'strength' => '650 mg',
            'dosage_form' => 'Capsule',
            'manufacturer' => 'Other Labs',
        ];

        foreach ($changes as $field => $value) {
            $shared = $this->shareSelected($this->customer, $this->pharmacy, $medicine->refresh());
            $before = (string) DB::table('prescription_items')->where('prescription_id', $shared->id)->value('selected_fingerprint');
            $this->assertSame($identity->fingerprint($medicine->refresh()), $before);
            $this->medicineCatalog()->update($this->admin, $medicine->refresh(), $this->identityChange($medicine->refresh(), $field, $value));
            $medicine->refresh();
            $this->putCart([
                'pharmacy_id' => $this->pharmacy->id,
                'mode' => 'prescription_selected_items',
                'prescription_id' => $shared->public_id,
                'items' => [['medicine_id' => $medicine->id, 'quantity' => 1]],
            ])->assertStatus(409)->assertJsonPath('error.code', 'prescription_unavailable');
            $this->putCart([
                'pharmacy_id' => $this->pharmacy->id,
                'mode' => 'catalog_items',
                'prescription_id' => $shared->public_id,
                'items' => [['medicine_id' => $medicine->id, 'quantity' => 1]],
            ])->assertStatus(409)->assertJsonPath('error.code', 'prescription_unavailable');
            $this->assertSame($before, (string) DB::table('prescription_items')->where('prescription_id', $shared->id)->value('selected_fingerprint'));
            $this->assertSame(strtolower((string) $medicine->id), strtolower((string) DB::table('prescription_items')->where('prescription_id', $shared->id)->value('selected_medicine_id')));
            $this->assertSame(0, Order::query()->count());
        }

        $shared = $this->shareSelected($this->customer, $this->pharmacy, $medicine->refresh());
        $this->putCart([
            'pharmacy_id' => $this->pharmacy->id,
            'mode' => 'prescription_selected_items',
            'prescription_id' => $shared->public_id,
            'items' => [['medicine_id' => $medicine->id, 'quantity' => 1]],
        ])->assertOk();
        $version = (int) $this->withCustomerToken($this->token)->getJson('/api/v1/cart')->json('data.version');
        $before = (string) DB::table('prescription_items')->where('prescription_id', $shared->id)->value('selected_fingerprint');
        $this->medicineCatalog()->update($this->admin, $medicine->refresh(), $this->identityChange($medicine->refresh(), 'brand_name', 'Checkout Brand'));
        $this->submit($version)->assertStatus(409)->assertJsonPath('error.code', 'prescription_unavailable');
        $this->assertSame(0, Order::query()->count());
        $this->assertSame($version, (int) $this->withCustomerToken($this->token)->getJson('/api/v1/cart')->json('data.version'));
        $this->assertSame($before, (string) DB::table('prescription_items')->where('prescription_id', $shared->id)->value('selected_fingerprint'));

        $legacy = $this->legacyFingerprint($medicine->refresh());
        $this->assertNotSame($identity->fingerprint($medicine->refresh()), $legacy);
        DB::table('prescription_items')->where('prescription_id', $shared->id)->update(['selected_fingerprint' => $legacy]);
        $this->medicineCatalog()->update($this->admin, $medicine->refresh(), $this->identityChange($medicine->refresh(), 'brand_name', 'Restored Brand'));
        $medicine->refresh();
        $this->withCustomerToken($this->token)->deleteJson('/api/v1/cart', ['version' => $version])->assertNoContent();
        $this->putCart([
            'pharmacy_id' => $this->pharmacy->id,
            'mode' => 'prescription_selected_items',
            'prescription_id' => $shared->public_id,
            'items' => [['medicine_id' => $medicine->id, 'quantity' => 1]],
        ])->assertStatus(409)->assertJsonPath('error.code', 'prescription_unavailable');
        $this->assertSame($legacy, trim((string) DB::table('prescription_items')->where('prescription_id', $shared->id)->value('selected_fingerprint')));

        $item = PrescriptionItem::query()->where('prescription_id', $shared->id)->firstOrFail();
        $workflow = app(PrescriptionWorkflow::class);
        $workflow->correct($this->customer, $shared->refresh(), $item, null, (string) $medicine->id, true);
        $shared = $workflow->share($this->customer, $shared->refresh(), 'selected_items', (string) $this->pharmacy->id);
        $this->assertSame($identity->fingerprint($medicine->refresh()), trim((string) DB::table('prescription_items')->where('prescription_id', $shared->id)->value('selected_fingerprint')));
        $this->putCart([
            'pharmacy_id' => $this->pharmacy->id,
            'mode' => 'prescription_selected_items',
            'prescription_id' => $shared->public_id,
            'items' => [['medicine_id' => $medicine->id, 'quantity' => 1]],
        ])->assertOk();
        $this->submit((int) $this->withCustomerToken($this->token)->getJson('/api/v1/cart')->json('data.version'))
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending_pharmacy_review');

        $controlled = $this->medicine('Selection Control', '10 mg', 'Tablet', 'Example Labs', 'Controla', true);
        $this->stock($this->pharmacy, $controlled, 'in_stock', 4, 1800, true);
        $controlledShare = $this->shareSelected($this->customer, $this->pharmacy, $controlled);
        $this->medicineCatalog()->update($this->admin, $controlled->refresh(), $this->identityChange($controlled->refresh(), 'controlled_drug', '1'));
        $this->putCart([
            'pharmacy_id' => $this->pharmacy->id,
            'mode' => 'prescription_selected_items',
            'prescription_id' => $controlledShare->public_id,
            'items' => [['medicine_id' => $controlled->id, 'quantity' => 1]],
        ])->assertStatus(409)->assertJsonPath('error.code', 'prescription_unavailable');

        $inactive = $this->medicine('Selection Quiet', '10 mg', 'Tablet', 'Example Labs', 'Quieta', true);
        $this->stock($this->pharmacy, $inactive, 'in_stock', 4, 1800, true);
        $inactiveShare = $this->shareSelected($this->customer, $this->pharmacy, $inactive);
        $this->medicineCatalog()->deactivate($this->admin, $inactive->refresh());
        $this->putCart([
            'pharmacy_id' => $this->pharmacy->id,
            'mode' => 'prescription_selected_items',
            'prescription_id' => $inactiveShare->public_id,
            'items' => [['medicine_id' => $inactive->id, 'quantity' => 1]],
        ])->assertStatus(409)->assertJsonPath('error.code', 'prescription_unavailable');

        $image = $this->shareFullImage($this->customer, $this->pharmacy);
        $selectedLine = $this->addExactItem($image, $medicine->refresh());
        $workflow->correct($this->customer, $image->refresh(), $selectedLine, null, (string) $medicine->id, true);
        $image = $workflow->share($this->customer, $image->refresh(), 'full_image', (string) $this->pharmacy->id);
        $this->putCart([
            'pharmacy_id' => $this->pharmacy->id,
            'mode' => 'prescription_full_image',
            'prescription_id' => $image->public_id,
            'items' => [],
        ])->assertOk()->assertJsonPath('data.items', []);
        $full = $this->submit((int) $this->withCustomerToken($this->token)->getJson('/api/v1/cart')->json('data.version'))->assertCreated();
        $full->assertJsonPath('data.items', []);
        $full->assertJsonPath('data.estimate_total_paisa', null);
        $this->assertSame(0, DB::table('order_items')->where('order_id', Order::query()->where('public_id', $full->json('data.id'))->value('id'))->count());
    }

    public function test_pending_share_does_not_authorize_confirmation(): void
    {
        $shared = $this->shareSelected($this->customer, $this->pharmacy, $this->medicine);
        $this->putCart([
            'pharmacy_id' => $this->pharmacy->id,
            'mode' => 'prescription_selected_items',
            'prescription_id' => $shared->public_id,
            'items' => [['medicine_id' => $this->medicine->id, 'quantity' => 1]],
        ])->assertOk();
        $created = $this->submit(1)->assertCreated();
        $order = Order::query()->where('public_id', $created->json('data.id'))->firstOrFail();
        $guard = app(OrderPrescriptionGuard::class);
        $this->assertSame('prescription_not_verified', $guard->prescriptionConfirmationBlock($order));
        $this->assertTrue((bool) $order->refresh()->prescription_usable);
        $this->assertSame(OrderStatus::PendingPharmacyReview, $order->status);

        $pharmacist = $this->pharmacist($this->pharmacy, 'pharmacist-verify@example.test');
        app(PrescriptionWorkflow::class)->review($pharmacist, $this->pharmacy, $shared->refresh(), $shared->content_version, 'verified', null);
        $this->assertNull($guard->prescriptionConfirmationBlock($order->refresh()));
        $this->assertSame(OrderStatus::PendingPharmacyReview, $order->refresh()->status);
        $this->assertTrue((bool) $order->prescription_usable);
    }

    public function test_prescription_problems_are_rejected_without_leaking_other_customers(): void
    {
        $other = $this->customerUser('other-rx@example.test', '+923001110502');
        $foreign = $this->shareFullImage($other, $this->pharmacy);
        $this->putCart($this->cartBody(null, 1, [
            'prescription_id' => $foreign->public_id,
            'mode' => 'prescription_full_image',
            'items' => [],
        ]))->assertNotFound()->assertJsonPath('error.code', 'not_found');
        $this->putCart($this->cartBody(null, 1, [
            'prescription_id' => (string) Str::uuid(),
            'mode' => 'prescription_full_image',
            'items' => [],
        ]))->assertNotFound();

        $own = $this->shareFullImage($this->customer, $this->pharmacy);
        $second = $this->eligiblePharmacy('Second Pharmacy');
        $this->stock($second, $this->medicine, 'in_stock', 4, 2500, true);
        $this->putCart([
            'pharmacy_id' => $second->id,
            'mode' => 'prescription_full_image',
            'prescription_id' => $own->public_id,
            'items' => [],
        ])->assertStatus(409)->assertJsonPath('error.code', 'prescription_unavailable');

        DB::table('prescriptions')->where('id', $own->id)->update(['content_version' => $own->content_version + 1]);
        $this->putCart([
            'pharmacy_id' => $this->pharmacy->id,
            'mode' => 'prescription_full_image',
            'prescription_id' => $own->public_id,
            'items' => [],
        ])->assertStatus(409)->assertJsonPath('error.code', 'prescription_unavailable');

        $withdrawn = $this->shareFullImage($this->customer, $this->pharmacy);
        app(PrescriptionWorkflow::class)->withdraw($this->customer, $withdrawn);
        $this->putCart([
            'pharmacy_id' => $this->pharmacy->id,
            'mode' => 'prescription_full_image',
            'prescription_id' => $withdrawn->public_id,
            'items' => [],
        ])->assertStatus(409);

        $rejected = $this->shareFullImage($this->customer, $this->pharmacy);
        $pharmacist = $this->pharmacist($this->pharmacy, 'pharmacist-orders@example.test');
        app(PrescriptionWorkflow::class)->review($pharmacist, $this->pharmacy, $rejected->refresh(), $rejected->content_version, 'rejected', 'Cannot dispense');
        $this->putCart([
            'pharmacy_id' => $this->pharmacy->id,
            'mode' => 'prescription_full_image',
            'prescription_id' => $rejected->public_id,
            'items' => [],
        ])->assertStatus(409);

        $deleted = $this->shareFullImage($this->customer, $this->pharmacy);
        app(PrescriptionWorkflow::class)->withdraw($this->customer, $deleted->refresh());
        app(PrescriptionWorkflow::class)->delete($this->customer, $deleted->refresh());
        $this->putCart([
            'pharmacy_id' => $this->pharmacy->id,
            'mode' => 'prescription_full_image',
            'prescription_id' => $deleted->public_id,
            'items' => [],
        ])->assertNotFound();

        $selected = $this->shareSelected($this->customer, $this->pharmacy, $this->medicine);
        $extra = $this->medicine('Ibuprofen', '200 mg', 'Tablet', 'Example Labs', 'Example Ibu', false);
        $this->stock($this->pharmacy, $extra, 'in_stock', 3, 1800, true);
        $this->putCart([
            'pharmacy_id' => $this->pharmacy->id,
            'mode' => 'prescription_selected_items',
            'prescription_id' => $selected->public_id,
            'items' => [['medicine_id' => $extra->id, 'quantity' => 1]],
        ])->assertStatus(409);
    }

    public function test_prescription_changes_revoke_confirmation_without_blocking_deletion(): void
    {
        $workflow = app(PrescriptionWorkflow::class);
        $shared = $this->shareFullImage($this->customer, $this->pharmacy);
        $orderId = $this->placeFullImage($shared);
        $workflow->correct($this->customer, $shared->refresh(), $this->addExactItem($shared, $this->medicine), null, (string) $this->medicine->id, true);
        $this->assertRevoked($orderId, 'prescription_corrected');

        $replaced = $this->shareFullImage($this->customer, $this->pharmacy);
        $replacedOrder = $this->placeFullImage($replaced);
        $bytes = $this->jpeg();
        app(PrescriptionObjectStore::class)->put($replaced->object_key, $bytes);
        $path = tempnam(sys_get_temp_dir(), 'rx');
        $this->assertIsString($path);
        file_put_contents($path, $bytes);
        $workflow->replaceFile($this->customer, $replaced->refresh(), new UploadedFile($path, 'note.jpg', 'image/jpeg', null, true));
        $this->assertRevoked($replacedOrder, 'prescription_replaced');

        $moved = $this->shareFullImage($this->customer, $this->pharmacy);
        $movedOrder = $this->placeFullImage($moved);
        $other = $this->eligiblePharmacy('Elsewhere Pharmacy');
        $workflow->share($this->customer, $moved->refresh(), 'full_image', (string) $other->id);
        $this->assertRevoked($movedOrder, 'prescription_reshared');
        $workflow->share($this->customer, $moved->refresh(), 'full_image', (string) $this->pharmacy->id);
        $this->assertFalse((bool) Order::query()->findOrFail($movedOrder)->prescription_usable);

        $withdrawn = $this->shareFullImage($this->customer, $this->pharmacy);
        $withdrawnOrder = $this->placeFullImage($withdrawn);
        $workflow->withdraw($this->customer, $withdrawn->refresh());
        $this->assertRevoked($withdrawnOrder, 'prescription_withdrawn');
        $this->assertContains($workflow->delete($this->customer, $withdrawn->refresh()), ['pending', 'completed']);
        $this->assertNotNull(Order::query()->find($withdrawnOrder));
    }

    public function test_delete_and_rejection_revoke_and_deletion_still_completes(): void
    {
        $workflow = app(PrescriptionWorkflow::class);
        $shared = $this->shareFullImage($this->customer, $this->pharmacy);
        $orderId = $this->placeFullImage($shared);
        DB::table('prescriptions')->where('id', $shared->id)->update([
            'share_state' => 'none',
            'share_mode' => null,
            'shared_content_version' => null,
            'pharmacy_id' => null,
        ]);
        $result = $workflow->delete($this->customer, $shared->refresh());
        $this->assertContains($result, ['pending', 'completed']);
        $this->assertRevoked($orderId, 'prescription_deleted');
        $this->assertNotNull(Order::query()->find($orderId));

        $rejected = $this->shareFullImage($this->customer, $this->pharmacy);
        $rejectedOrder = $this->placeFullImage($rejected);
        $pharmacist = $this->pharmacist($this->pharmacy, 'pharmacist-reject@example.test');
        $workflow->review($pharmacist, $this->pharmacy, $rejected->refresh(), $rejected->content_version, 'rejected', 'Not accepted');
        $this->assertRevoked($rejectedOrder, 'prescription_rejected');
        $this->assertSame('prescription_unusable', app(OrderPrescriptionGuard::class)->prescriptionConfirmationBlock(Order::query()->findOrFail($rejectedOrder)));
        $this->assertSame(OrderStatus::PendingPharmacyReview, Order::query()->findOrFail($rejectedOrder)->status);
    }

    public function test_catalog_prescription_rule_and_stale_share_block_later_confirmation(): void
    {
        $rx = $this->medicine('Amoxicillin', '250 mg', 'Capsule', 'Example Labs', 'Example Amox', true);
        $this->stock($this->pharmacy, $rx, 'in_stock', 5, 4000, true);
        $this->putCart([
            'pharmacy_id' => $this->pharmacy->id,
            'mode' => 'catalog_items',
            'items' => [['medicine_id' => $rx->id, 'quantity' => 1]],
        ])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');

        $shared = $this->shareFullImage($this->customer, $this->pharmacy);
        $this->putCart([
            'pharmacy_id' => $this->pharmacy->id,
            'mode' => 'catalog_items',
            'prescription_id' => $shared->public_id,
            'items' => [['medicine_id' => $rx->id, 'quantity' => 1]],
        ])->assertOk();
        $created = $this->submit(1)->assertCreated();
        $created->assertJsonPath('data.status', 'pending_pharmacy_review');
        $order = Order::query()->where('public_id', $created->json('data.id'))->firstOrFail();
        DB::table('prescriptions')->where('id', $shared->id)->update(['content_version' => $shared->content_version + 1]);
        $this->assertSame('prescription_unusable', app(OrderPrescriptionGuard::class)->prescriptionConfirmationBlock($order->refresh()));
        $this->assertFalse((bool) $order->refresh()->prescription_usable);
        $this->assertSame(OrderStatus::PendingPharmacyReview, $order->status);
    }

    public function test_eligibility_is_rechecked_at_submit(): void
    {
        $this->putCart($this->cartBody(null, 1))->assertOk();
        $this->medicineCatalog()->deactivate($this->admin, $this->medicine);
        $this->submit(1)->assertStatus(422)->assertJsonPath('error.code', 'medicine_unavailable');
        $this->assertSame(0, Order::query()->count());
        $this->withToken($this->token)->getJson('/api/v1/cart')->assertJsonPath('data.version', 1);
        $this->medicineCatalog()->activate($this->admin, $this->medicine->refresh());

        DB::table('pharmacy_inventory')->where('medicine_id', $this->medicine->id)->update([
            'last_verified_at' => now()->subHours(48),
        ]);
        $this->submit(1)->assertStatus(422)->assertJsonPath('error.code', 'medicine_unavailable');
        DB::table('pharmacy_inventory')->where('medicine_id', $this->medicine->id)->update([
            'last_verified_at' => now(),
            'is_available_for_pickup' => false,
        ]);
        $this->submit(1)->assertStatus(422);
        DB::table('pharmacy_inventory')->where('medicine_id', $this->medicine->id)->update([
            'is_available_for_pickup' => true,
        ]);

        $controlled = $this->medicine('Diazepam', '5 mg', 'Tablet', 'Example Labs', null, false, true);
        $this->putCart($this->cartBody(null, 1, [
            'version' => 1,
            'items' => [['medicine_id' => $controlled->id, 'quantity' => 1]],
        ]))->assertStatus(422)->assertJsonPath('error.code', 'medicine_unavailable');

        $inactive = $this->medicine('Inactive Salt', '10 mg', 'Tablet', 'Example Labs', null, false, false, false);
        $this->putCart($this->cartBody(null, 1, [
            'version' => 1,
            'items' => [['medicine_id' => $inactive->id, 'quantity' => 1]],
        ]))->assertStatus(422);

        app(PharmacyWorkflow::class)->suspend($this->admin, $this->pharmacy->refresh(), 'Closed');
        $this->submit(1)->assertStatus(422)->assertJsonPath('error.code', 'pharmacy_unavailable');
        app(PharmacyWorkflow::class)->reinstate($this->admin, $this->pharmacy->refresh());

        $cityId = (string) Str::uuid();
        DB::table('cities')->insert([
            'id' => $cityId,
            'name' => 'Multan',
            'country_code' => 'PK',
            'timezone' => 'Asia/Karachi',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('pharmacies')->where('id', $this->pharmacy->id)->update(['city_id' => $cityId]);
        $this->submit(1)->assertStatus(422)->assertJsonPath('error.code', 'pharmacy_unavailable');
        $this->assertSame(0, Order::query()->count());
    }

    public function test_cart_bounds_version_and_pharmacy_switch(): void
    {
        $this->putCart($this->cartBody('  keep me  ', 1))->assertOk()->assertJsonPath('data.customer_note', 'keep me');
        $this->putCart($this->cartBody(null, 1, ['version' => 99]))->assertStatus(409)->assertJsonPath('error.code', 'cart_stale');
        $this->withToken($this->token)->getJson('/api/v1/cart')->assertJsonPath('data.customer_note', 'keep me');

        $other = $this->eligiblePharmacy('Switch Pharmacy');
        $otherMedicine = $this->medicine('Cetirizine', '10 mg', 'Tablet', 'Example Labs', 'Example Cet', false);
        $this->stock($other, $otherMedicine, 'in_stock', 2, 900, true);
        $switched = $this->putCart([
            'version' => 1,
            'pharmacy_id' => $other->id,
            'mode' => 'catalog_items',
            'items' => [['medicine_id' => $otherMedicine->id, 'quantity' => 1]],
        ])->assertOk();
        $switched->assertJsonPath('data.pharmacy_id', $other->id);
        $switched->assertJsonPath('data.items.0.medicine_id', $otherMedicine->id);
        $switched->assertJsonCount(1, 'data.items');
        $this->assertNull($switched->json('data.prescription'));

        $this->putCart([
            'version' => 2,
            'pharmacy_id' => $other->id,
            'mode' => 'catalog_items',
            'items' => [['medicine_id' => $this->medicine->id, 'quantity' => 1]],
        ])->assertStatus(422);
        $this->withToken($this->token)->getJson('/api/v1/cart')->assertJsonPath('data.pharmacy_id', $other->id);

        $this->withToken($this->token)->deleteJson('/api/v1/cart', ['version' => 1])->assertStatus(409);
        $this->withToken($this->token)->deleteJson('/api/v1/cart', ['version' => 2])->assertNoContent();
        $this->withToken($this->token)->deleteJson('/api/v1/cart', ['version' => 2])->assertNoContent();
        $this->withToken($this->token)->getJson('/api/v1/cart')->assertJsonPath('data', null);

        $this->putCart($this->cartBody(null, 1, [
            'items' => [
                ['medicine_id' => $this->medicine->id, 'quantity' => 1],
                ['medicine_id' => strtoupper((string) $this->medicine->id), 'quantity' => 2],
            ],
        ]))->assertStatus(422)->assertJsonPath('error.details.items.0', 'Each medicine can appear once.');
        $this->putCart($this->cartBody(null, 0))->assertStatus(422);
        $this->putCart($this->cartBody(null, 100))->assertStatus(422);
        $this->putCart($this->cartBody(str_repeat('n', 501), 1))->assertStatus(422);
        $this->putCart($this->cartBody(null, 1, [
            'items' => [['medicine_id' => $this->medicine->id, 'quantity' => 1, 'unit_price_paisa' => 1]],
        ]))->assertStatus(422);

        $items = [];
        for ($index = 0; $index < 11; $index++) {
            $medicine = $this->medicine('Salt '.$index, '10 mg', 'Tablet', 'Example Labs', null, false);
            $this->stock($this->pharmacy, $medicine, 'in_stock', 1, 100, true);
            $items[] = ['medicine_id' => $medicine->id, 'quantity' => 1];
        }
        $this->putCart($this->cartBody(null, 1, ['items' => $items]))->assertStatus(422);
        $this->withToken($this->token)->getJson('/api/v1/cart')->assertJsonPath('data', null);
    }

    public function test_idempotent_submit_replays_conflicts_and_survives_rollback(): void
    {
        $note = 'idempotent-note-token';
        $key = $this->key();
        $this->putCart($this->cartBody($note, 1))->assertOk();
        Log::listen(function ($entry) use ($note, $key): void {
            $encoded = json_encode($entry);
            if (! is_string($encoded)) {
                return;
            }
            $this->assertStringNotContainsString($note, $encoded);
            $this->assertStringNotContainsString($key, $encoded);
        });
        $created = $this->submit(1, $key)->assertCreated();
        $id = (string) $created->json('data.id');
        $this->assertStringNotContainsString($key, (string) json_encode($created->json()));
        $metadata = (string) DB::table('audit_events')->where('action', 'order_submitted')->value('metadata');
        $this->assertStringNotContainsString($note, $metadata);
        $this->assertStringNotContainsString($key, $metadata);
        $this->assertSame(1, DB::table('order_submissions')->count());
        $this->assertNull(DB::table('order_submissions')->where('key_hash', $key)->first());

        $replay = $this->submit(1, $key)->assertOk();
        $replay->assertJsonPath('data.id', $id);
        $replay->assertJsonPath('data.status', 'pending_pharmacy_review');
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, DB::table('order_status_events')->where('event', 'submitted')->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'order_submitted')->count());

        $this->putCart($this->cartBody('different', 1))->assertOk();
        $version = (int) $this->withToken($this->token)->getJson('/api/v1/cart')->json('data.version');
        $this->submit($version, $key)->assertStatus(409)->assertJsonPath('error.code', 'idempotency_conflict');
        $this->assertSame(1, Order::query()->count());

        $this->withToken($this->token)->postJson('/api/v1/orders/'.$id.'/cancel')->assertOk();
        $this->submit(1, $key)->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame(1, Order::query()->count());

        $retryKey = $this->key();
        $this->withToken($this->token)->deleteJson('/api/v1/cart', ['version' => $version])->assertNoContent();
        $this->putCart($this->cartBody(null, 1))->assertOk();
        $retryVersion = (int) $this->withToken($this->token)->getJson('/api/v1/cart')->json('data.version');
        config(['medifind.orders.testing_hooks.before_submit_commit' => static function (): void {
            throw new RuntimeException('submit failed');
        }]);
        try {
            $failed = $this->submit($retryVersion, $retryKey);
            $failed->assertStatus(500);
            $this->assertStringNotContainsString('submit failed', (string) $failed->getContent());
        } finally {
            config(['medifind.orders.testing_hooks.before_submit_commit' => null]);
        }
        $this->assertSame(1, Order::query()->count());
        $this->withToken($this->token)->getJson('/api/v1/cart')->assertJsonPath('data.version', $retryVersion);
        $this->submit($retryVersion, $retryKey)->assertCreated();
        $this->assertSame(2, Order::query()->count());
    }

    public function test_cancellation_follows_the_allowed_states(): void
    {
        $this->putCart($this->cartBody(null, 1))->assertOk();
        $created = $this->submit(1)->assertCreated();
        $id = (string) $created->json('data.id');
        $cancelled = $this->withToken($this->token)->postJson('/api/v1/orders/'.$id.'/cancel', [
            'reason' => 'Changed my mind',
        ])->assertOk();
        $cancelled->assertJsonPath('data.status', 'cancelled');
        $cancelled->assertJsonPath('data.timeline.1.event', 'cancelled');
        $cancelled->assertJsonPath('data.timeline.1.reason', 'Changed my mind');
        $repeat = $this->withToken($this->token)->postJson('/api/v1/orders/'.$id.'/cancel', [
            'reason' => 'A different reason',
        ])->assertOk();
        $repeat->assertJsonPath('data.timeline.1.reason', 'Changed my mind');
        $this->assertSame(1, DB::table('order_status_events')->where('event', 'cancelled')->count());

        foreach ([OrderStatus::ReadyForPickup, OrderStatus::Collected, OrderStatus::Rejected, OrderStatus::NotCollected, OrderStatus::Unfulfillable] as $status) {
            $this->putCart($this->cartBody(null, 1))->assertOk();
            $version = (int) $this->withToken($this->token)->getJson('/api/v1/cart')->json('data.version');
            $next = $this->submit($version)->assertCreated();
            $publicId = (string) $next->json('data.id');
            $this->forceTerminalStatus($publicId, $status);
            $this->withToken($this->token)->postJson('/api/v1/orders/'.$publicId.'/cancel')
                ->assertStatus(409)
                ->assertJsonPath('error.code', 'order_not_cancellable');
            $this->assertSame($status->value, DB::table('orders')->where('public_id', $publicId)->value('status'));
        }

        $this->putCart($this->cartBody(null, 1))->assertOk();
        $version = (int) $this->withToken($this->token)->getJson('/api/v1/cart')->json('data.version');
        $confirmed = (string) $this->submit($version)->assertCreated()->json('data.id');
        DB::table('orders')->where('public_id', $confirmed)->update([
            'status' => OrderStatus::Confirmed->value,
            'confirmed_total_paisa' => 2500,
        ]);
        $this->withToken($this->token)->postJson('/api/v1/orders/'.$confirmed.'/cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->withToken($this->token)->postJson('/api/v1/orders/'.$id.'/cancel', [
            'reason' => str_repeat('r', 201),
        ])->assertStatus(422);
    }

    public function test_orders_are_isolated_and_suspended_customers_cannot_act(): void
    {
        $this->putCart($this->cartBody(null, 1))->assertOk();
        $id = (string) $this->submit(1)->assertCreated()->json('data.id');
        $other = $this->token($this->customerUser('other-order@example.test', '+923001110503'));
        $missing = $this->withCustomerToken($other)->getJson('/api/v1/orders/'.Str::uuid());
        $foreign = $this->withCustomerToken($other)->getJson('/api/v1/orders/'.$id);
        $missing->assertNotFound()->assertJsonPath('error.code', 'not_found');
        $foreign->assertNotFound()->assertJsonPath('error.code', 'not_found');
        $this->assertSame($missing->json('error.message'), $foreign->json('error.message'));
        $this->withCustomerToken($other)->getJson('/api/v1/orders')->assertOk()->assertJsonPath('data', []);
        $this->withCustomerToken($other)->postJson('/api/v1/orders/'.$id.'/cancel')->assertNotFound();

        DB::statement('ALTER TABLE users DISABLE TRIGGER medifind_user_token_revocation');
        try {
            $this->customer->forceFill(['status' => AccountStatus::Suspended])->save();
        } finally {
            DB::statement('ALTER TABLE users ENABLE TRIGGER medifind_user_token_revocation');
        }
        $this->withCustomerToken($this->token)->postJson('/api/v1/orders', [
            'cart_version' => 1,
        ], ['Idempotency-Key' => $this->key()])->assertForbidden()->assertJsonPath('error.code', 'account_inactive');
        $this->withCustomerToken($this->token)->postJson('/api/v1/orders/'.$id.'/cancel')->assertForbidden()->assertJsonPath('error.code', 'account_inactive');
        $this->withCustomerToken($this->token)->getJson('/api/v1/orders/'.$id)->assertForbidden();
    }

    public function test_order_history_pagination_keeps_equal_fractional_timestamps(): void
    {
        $prescription = $this->storedPrescription($this->customer);
        $ids = [];
        $newer = '2026-09-27 18:00:00.654321';
        $equal = '2026-09-27 18:00:00.123456';
        $older = '2026-09-27 17:00:00.000001';
        $reference = 0;

        foreach ([$newer => 4, $equal => 21, $older => 1] as $stamp => $count) {
            for ($index = 0; $index < $count; $index++) {
                $publicId = (string) Str::uuid();
                $ids[] = $publicId;
                $reference++;
                DB::table('orders')->insert([
                    'id' => (string) Str::uuid(),
                    'public_id' => $publicId,
                    'reference' => 'MFP'.str_pad((string) $reference, 6, '0', STR_PAD_LEFT),
                    'customer_id' => $this->customer->id,
                    'pharmacy_id' => $this->pharmacy->id,
                    'request_mode' => 'prescription_full_image',
                    'prescription_id' => $prescription->id,
                    'prescription_public_id' => $prescription->public_id,
                    'prescription_content_version' => $prescription->content_version,
                    'prescription_share_mode' => 'full_image',
                    'prescription_usable' => true,
                    'status' => 'pending_pharmacy_review',
                    'payment_method' => 'cash_on_pickup',
                    'payment_status' => 'unpaid',
                    'estimate_total_paisa' => null,
                    'created_at' => $stamp,
                    'updated_at' => $stamp,
                ]);
            }
        }

        $seen = [];
        $cursor = null;
        $pages = 0;
        $sawFraction = false;

        do {
            $uri = '/api/v1/orders'.($cursor === null ? '' : '?cursor='.urlencode($cursor));
            $page = $this->withToken($this->token)->getJson($uri)->assertOk();
            $rows = $page->json('data');
            $this->assertIsArray($rows);
            $this->assertLessThanOrEqual(20, count($rows));
            $this->assertSame(20, $page->json('meta.per_page'));

            foreach ($rows as $row) {
                $this->assertIsArray($row);
                $this->assertNotContains($row['id'], $seen);
                $seen[] = $row['id'];
                if (is_string($row['created_at']) && str_contains($row['created_at'], '.123456')) {
                    $sawFraction = true;
                }
            }

            $cursor = $page->json('meta.next_cursor');
            $pages++;
            if ($page->json('meta.has_more') === true) {
                $this->assertIsString($cursor);
                $this->assertNotSame('', $cursor);
            } else {
                $this->assertNull($cursor);
            }
        } while ($cursor !== null && $pages < 5);

        $this->assertEqualsCanonicalizing($ids, $seen);
        $this->assertCount(26, $seen);
        $this->assertTrue($sawFraction);
        $this->assertGreaterThan(1, $pages);
        $this->withToken($this->token)->getJson('/api/v1/orders?per_page=500')->assertJsonCount(20, 'data');
        $this->withToken($this->token)->getJson('/api/v1/orders?cursor=')->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        $this->withToken($this->token)->getJson('/api/v1/orders?cursor=not-a-cursor')->assertStatus(422);
    }

    public function test_order_snapshot_cannot_be_rewritten(): void
    {
        $this->putCart($this->cartBody('original note', 1))->assertOk();
        $created = $this->submit(1)->assertCreated();
        $id = (string) DB::table('orders')->where('public_id', $created->json('data.id'))->value('id');

        try {
            DB::transaction(function () use ($id): void {
                DB::table('orders')->where('id', $id)->update(['customer_note' => 'rewritten']);
            });
            $this->fail('The order note was rewritten.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('immutable', $exception->getMessage());
        }

        $this->assertSame('original note', DB::table('orders')->where('id', $id)->value('customer_note'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    /**
     * @return array{generic_name: string, dosage_form: string, strength: string, manufacturer: string, brand_name: ?string, prescription_required: bool, controlled_drug: bool}
     */
    private function identityChange(Medicine $medicine, string $field, string $value): array
    {
        return [
            'generic_name' => $field === 'generic_name' ? $value : (string) $medicine->generic_name,
            'dosage_form' => $field === 'dosage_form' ? $value : (string) $medicine->dosage_form,
            'strength' => $field === 'strength' ? $value : (string) $medicine->strength,
            'manufacturer' => $field === 'manufacturer' ? $value : (string) $medicine->manufacturer,
            'brand_name' => $field === 'brand_name' ? $value : $medicine->brand_name,
            'prescription_required' => (bool) $medicine->prescription_required,
            'controlled_drug' => $field === 'controlled_drug' || (bool) $medicine->controlled_drug,
        ];
    }

    private function legacyFingerprint(Medicine $medicine): string
    {
        return hash('sha256', implode("\n", [
            (string) $medicine->id,
            $medicine->normalized_generic_name,
            $medicine->normalized_strength,
            $medicine->normalized_dosage_form,
            $medicine->normalized_manufacturer,
            $medicine->controlled_drug ? '1' : '0',
            $medicine->status->value,
        ]));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function forceTerminalStatus(string $publicId, OrderStatus $status): void
    {
        if ($status === OrderStatus::Rejected) {
            DB::table('orders')->where('public_id', $publicId)->update(['status' => $status->value]);

            return;
        }

        if ($status === OrderStatus::Unfulfillable) {
            DB::table('orders')->where('public_id', $publicId)->update([
                'status' => $status->value,
                'confirmed_total_paisa' => 2500,
            ]);

            return;
        }

        DB::table('orders')->where('public_id', $publicId)->update([
            'status' => OrderStatus::ReadyForPickup->value,
            'confirmed_total_paisa' => 2500,
            'pickup_window_starts_at' => '2026-10-01 10:00:00+00',
            'pickup_window_ends_at' => '2026-10-01 12:00:00+00',
            'pickup_grace_seconds' => 7200,
            'collect_until' => '2026-10-01 14:00:00+00',
        ]);

        if ($status === OrderStatus::Collected) {
            DB::table('orders')->where('public_id', $publicId)->update([
                'status' => OrderStatus::Collected->value,
                'payment_status' => 'paid',
                'cash_collected_paisa' => 2500,
            ]);
        }

        if ($status === OrderStatus::NotCollected) {
            DB::table('orders')->where('public_id', $publicId)->update([
                'status' => OrderStatus::NotCollected->value,
            ]);
        }
    }

    private function cartBody(?string $note, int $quantity, array $overrides = []): array
    {
        return array_merge([
            'pharmacy_id' => $this->pharmacy->id,
            'mode' => 'catalog_items',
            'customer_note' => $note,
            'items' => [['medicine_id' => $this->medicine->id, 'quantity' => $quantity]],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function putCart(array $body): TestResponse
    {
        return $this->withCustomerToken($this->token)->putJson('/api/v1/cart', $body);
    }

    private function submit(int $version, ?string $key = null): TestResponse
    {
        return $this->withCustomerToken($this->token)->withHeader('Idempotency-Key', $key ?? $this->key())->postJson('/api/v1/orders', [
            'cart_version' => $version,
        ]);
    }

    private function key(): string
    {
        return bin2hex(random_bytes(8));
    }

    private function placeFullImage(Prescription $prescription): string
    {
        $this->putCart([
            'pharmacy_id' => $this->pharmacy->id,
            'mode' => 'prescription_full_image',
            'prescription_id' => $prescription->public_id,
            'items' => [],
        ])->assertOk();
        $version = (int) $this->withToken($this->token)->getJson('/api/v1/cart')->json('data.version');
        $created = $this->submit($version)->assertCreated();

        return (string) Order::query()->where('public_id', $created->json('data.id'))->value('id');
    }

    private function assertRevoked(string $orderId, string $cause): void
    {
        $order = Order::query()->findOrFail($orderId);
        $this->assertFalse((bool) $order->prescription_usable);
        $this->assertSame(OrderStatus::PendingPharmacyReview, $order->status);
        $this->assertTrue(DB::table('order_status_events')->where('order_id', $orderId)->where('cause', $cause)->exists());
        $this->assertSame('prescription_unusable', app(OrderPrescriptionGuard::class)->prescriptionConfirmationBlock($order->refresh()));
    }

    private function shareSelected(User $customer, Pharmacy $pharmacy, Medicine $medicine): Prescription
    {
        $prescription = $this->storedPrescription($customer);
        $item = $this->addExactItem($prescription, $medicine);
        $workflow = app(PrescriptionWorkflow::class);
        $workflow->correct($customer, $prescription, $item, null, (string) $medicine->id, true);

        return $workflow->share($customer, $prescription->refresh(), 'selected_items', (string) $pharmacy->id);
    }

    private function shareFullImage(User $customer, Pharmacy $pharmacy): Prescription
    {
        return app(PrescriptionWorkflow::class)->share($customer, $this->storedPrescription($customer), 'full_image', (string) $pharmacy->id);
    }

    private function addExactItem(Prescription $prescription, Medicine $medicine): PrescriptionItem
    {
        $item = new PrescriptionItem;
        $item->forceFill([
            'prescription_id' => $prescription->id,
            'extraction_version' => $prescription->extraction_version,
            'line_index' => (int) PrescriptionItem::query()->where('prescription_id', $prescription->id)->count(),
            'extracted_text' => 'not-a-catalog-line',
            'match_status' => 'exact',
            'medicine_id' => $medicine->id,
            'customer_selected' => false,
        ])->save();

        return $item->refresh();
    }

    private function storedPrescription(User $customer): Prescription
    {
        $row = new Prescription;
        $row->forceFill([
            'customer_id' => $customer->id,
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

    private function jpeg(): string
    {
        $image = imagecreatetruecolor(8, 8);
        $this->assertNotFalse($image);
        ob_start();
        imagejpeg($image);
        $bytes = ob_get_clean();
        imagedestroy($image);
        $this->assertIsString($bytes);

        return $bytes;
    }

    private function pharmacist(Pharmacy $pharmacy, string $email): User
    {
        $workflow = app(PharmacyWorkflow::class);
        $staff = $this->user(UserRole::PharmacyStaff, $email, null);
        $staff->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $staff->saveAppAuthenticationRecoveryCodes(['recovery-code-phase5']);
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

    private function medicine(string $generic, string $strength, string $form, string $manufacturer, ?string $brand, bool $prescription, bool $controlled = false, bool $activate = true): Medicine
    {
        $medicine = $this->medicineCatalog()->create($this->admin, [
            'generic_name' => $generic,
            'dosage_form' => $form,
            'strength' => $strength,
            'manufacturer' => $manufacturer,
            'brand_name' => $brand,
            'prescription_required' => $prescription,
            'controlled_drug' => $controlled,
        ]);

        if ($activate) {
            $this->medicineCatalog()->activate($this->admin, $medicine);
        }

        return $medicine->refresh();
    }

    private function medicineCatalog(): MedicineCatalog
    {
        return app(MedicineCatalog::class);
    }

    private function stock(Pharmacy $pharmacy, Medicine $medicine, string $status, ?int $quantity, int $price, bool $pickup): void
    {
        app(InventoryBook::class)->save($this->admin, $pharmacy, [
            'medicine_id' => (string) $medicine->id,
            'stock_status' => $status,
            'quantity_on_hand' => $quantity,
            'unit_price_paisa' => $price,
            'is_available_for_pickup' => $pickup,
        ]);
    }

    private function customerUser(string $email, string $phone): User
    {
        $user = $this->user(UserRole::Customer, $email, $phone);
        foreach (['upload', 'edit', 'share', 'read'] as $action) {
            RateLimiter::clear('prescription:'.$action.':'.$user->id);
        }

        return $user;
    }

    private function token(User $user): string
    {
        return $user->createToken('customer', ['customer:access'], now()->addDay())->plainTextToken;
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
}
