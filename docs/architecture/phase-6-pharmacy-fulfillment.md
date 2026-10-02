# Phase 6 pharmacy fulfillment

Phase 6 is pharmacist review, quoting, reservation, pickup, and cash recording. It does not add a Flutter client, online payment, delivery, or SMS. Launch scope remains Faisalabad, one pharmacy per order, and cash on pickup.

Customer cancellation of `pending_pharmacy_review` and `confirmed` stays the Phase 5 API. There is still no public pharmacy fulfillment HTTP API; those actions run in the Filament pharmacy panel. The only new customer write is quote acceptance.

## Status

`OrderLifecycle` edges:

- `pending_pharmacy_review` → `confirmed`, `rejected`, or `cancelled`
- `confirmed` → `ready_for_pickup`, `cancelled`, or `unfulfillable`
- `ready_for_pickup` → `collected`, `not_collected`, or `unfulfillable`
- `collected`, `rejected`, `cancelled`, `not_collected`, and `unfulfillable` are terminal in the service

PostgreSQL checks the shape of a row (status, payment, window, confirmed total). It does not encode the transition graph. Direct SQL can still write a status the service would refuse. `collected` cannot be stored without `cash_collected_paisa`, and once cash is set the snapshot trigger freezes it.

## Quotes

A pending order may have at most one `open` quote and at most one `accepted` quote. A new proposal supersedes `open` and `accepted` rows, then inserts the next version. Quote lines, totals, expiry, and acceptances are immutable. `invalid` and `superseded` cannot return to `open`.

Catalog and selected-item quotes may change unit price only. Medicine identity and quantity stay as submitted. Full-image quotes list 1–10 distinct active, non-controlled catalog medicines that the pharmacy stocks. `propose` locks medicines, then the order, then those inventory rows, and compares every proposed unit price with that row's current `unit_price_paisa` before it supersedes anything. A missing inventory row or a different price is a validation error on `lines`. The pharmacist's figure is not replaced, discounted, or rewritten. Confirmation still rechecks inventory price, identity, stock, and freshness; a genuine price change after proposal invalidates the accepted quote (`quote_invalid`) and writes no reservation.

Customer `POST /api/v1/orders/{order}/quote-acceptance` is scoped to the owning customer. Another customer's order is `404 not_found`. Acceptance does not reserve stock or confirm the order. Expired quotes become `invalid` (`quote_expired`). A stale version is `quote_stale`.

## Reservations

Available quantity is `quantity_on_hand` minus active reservations. Confirmation inserts an `active` row and does not change on-hand. Release (`cancelled`, `not_collected`, prescription unusable after confirmation) marks `released` and does not change on-hand. Collection marks `consumed` and decrements on-hand once. A second collect or no-show is idempotent on the order and does not write a second event or a second stock change.

A constraint trigger refuses active reserved quantity above on-hand, or any active reservation when on-hand is null. Inventory updates cannot drop on-hand below active reservations. Reservation rows cannot be deleted or have their quantity rewritten.

## Pickup window

`markReady` stores Asia/Karachi wall times as UTC instants. `pickup_grace_seconds` comes from config (default 7200). `collect_until` is `pickup_window_ends_at + grace`.

Collection is allowed only while `pickup_window_starts_at <= now < collect_until`, comparing UTC instants at the stored timestamp precision. Missing or malformed window fields fail closed (`pickup_not_open`). A collection before the start, or at or after `collect_until`, leaves the order `ready_for_pickup`, the reservation `active`, on-hand, payment, events, and audit unchanged. Collection is allowed at the start and immediately before `collect_until`, including after the stated end while grace remains. An already `collected` order may be retried after the cutoff; the service returns the existing row without charging, decrementing, or writing another event.

No-show is allowed at `collect_until` and later (`not_collected_too_early` before that). Collection and no-show compete on the order row lock. At the cutoff, collection is refused and no-show may commit. At most one of consume or release occurs.

## Cash

Integer paisa only. Collection records `cash_collected_paisa` equal to `confirmed_total_paisa`. A positive total becomes `paid`. A confirmed zero total becomes `no_payment_due` with cash `0`. A mismatch is `cash_mismatch` and rolls back. `orders_collected_cash_present_check` requires cash present whenever `status = 'collected'`. `orders_payment_state_check` still requires the paid / no-payment-due pairing. The original Phase 6 migration is not rewritten; the cash-present check is a later migration. Rolling that later migration back drops only the new constraint.

## Authorization

Pharmacist writes lock the pharmacy, acting user, and membership. They require an operational user, an approved publicly eligible pharmacy, and an active verified pharmacist membership. Suspended accounts, suspended or unverified memberships, expired credentials, inventory staff, managers, and other pharmacies receive `forbidden` or the same `not_found` as a missing order. Cross-pharmacy lookups always include `pharmacy_id`.

## Prescription file read

Unchanged from Phase 4's review table. The extra order-based read is only for a `pending_pharmacy_review` `full_image` order with `prescription_usable` true, a currently verified share for that pharmacy and mode, and matching content versions. `confirmed` and `ready_for_pickup` recheck the share; they do not grant another file read. Authorization commits before storage is called. A withdrawal that commits after that decision, while bytes are still being read, is not undone; that already authorized read may finish, and the next read is denied. A storage failure returns no bytes and does not record `prescription_file_read`.

## Lock order

Pharmacist writes that touch a linked prescription: pharmacy, user, membership, prescription, medicines by id, order, quote, inventory by medicine id, reservations by id. Catalog orders skip the prescription lock. Quote proposal uses medicines, then order, then inventory. Customer cancellation locks the order, then inventory, then reservations. Inventory edits lock the inventory row. Those orders do not wait on each other in a cycle.

## Rollback

`2026_09_30_120000_phase6_pharmacy_fulfillment` refuses to drop quotes, reservations, or fulfillment columns while any such row exists. `2026_10_02_120000_phase6_collected_cash_present` rolls back by dropping `orders_collected_cash_present_check` only. Adding that check validates existing rows; invalid collected rows without cash fail the migration instead of being filled or deleted.
