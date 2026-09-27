# Phase 5 pickup ordering

Phase 5 is the customer pickup-order API. It is not a Flutter client, and it does not confirm orders, reserve stock, collect cash, or notify anyone. A pharmacist still has to accept the request in a later phase.

Launch scope remains Faisalabad, one pharmacy per order, and cash on pickup.

## Cart

One active customer has at most one server-owned cart, for exactly one pharmacy.

- `GET /api/v1/cart` returns that cart, or `data: null` when there is none.
- `PUT /api/v1/cart` creates or replaces the whole cart. Changing the pharmacy replaces items, the note, and any prescription together. Nothing is carried across silently.
- `DELETE /api/v1/cart` clears it. The body is `{ "version": n }`. Clearing an already empty cart returns 204. A stale version returns `cart_stale` and leaves the cart in place.
- `POST /api/v1/orders` submits the current cart.

The cart version starts at 1. Every successful replace increments it. Clear and submit retire that version; the next cart uses the next integer, so an old version cannot be reused. A replace or submit with a version that is not the current one returns `409 cart_stale` and changes nothing. Two devices therefore cannot overwrite each other without first reloading the cart.

Request modes:

- `catalog_items` needs 1 to 10 distinct medicines. Ten is `medifind.discovery.max_medicines`, and the database trigger enforces the same cap.
- `prescription_selected_items` must list exactly the customer's current explicit selections for that prescription version. Quantities are the customer's requested quantities. Extraction text is never turned into a line.
- `prescription_full_image` has zero catalog lines. It does not claim that the image contains any medicine or that the pharmacy has it.

Duplicate medicine IDs, quantities outside 1–99, more than 10 lines, and a note longer than 500 characters are rejected. They are not truncated. Prices and stock flags in the client body are rejected as unknown fields. The server reads price and eligibility itself.

The instruction note is plain text for the pharmacy. It is stored on the cart and copied onto the order. It is not written to audit metadata, logs, or exception messages, and it cannot override a prescription, a medicine rule, or stock.

Indicative prices on the cart can go stale. Submit checks again.

## Prescriptions

Cart replace and submit both resolve the prescription by its public id.

The prescription must belong to the customer and not be deleted. It must be shared with the chosen pharmacy, the share mode must match the request, `shared_content_version` must equal `content_version`, and the share must be `pending` or `verified`. Withdrawn, rejected, stale, wrong-pharmacy, and wrong-mode shares return `409 prescription_unavailable`. A missing, deleted, or other customer's prescription returns the same `404 not_found` as any other missing resource.

A catalog line that requires a prescription needs that current share. A `selected_items` share must include every prescription-only line. A `full_image` share covers those lines without identifying them and does not turn extraction text or an old selection into order lines. A catalog cart with no prescription-only line must not attach a prescription. Pending pharmacist review of the share is enough to create a `pending_pharmacy_review` order. It does not confirm the order.

Selected lines use one identity rule, `App\Prescriptions\SelectionIdentity`, for sharing and for cart replacement and submission. Every currently selected line on the current extraction version must have `match_status=exact`, `selected_medicine_id=medicine_id`, a fingerprint, and an active, non-controlled medicine with a complete catalog identity. The stored fingerprint is SHA-256 of the medicine UUID, normalized generic name, normalized brand name, normalized strength, normalized dosage form, normalized manufacturer, controlled flag, and status. Cart replacement and submission run that comparison again after the row locks, and they do not rewrite the stored fingerprint or substitute another variant. A fingerprint stored before brand was part of the identity does not match. The customer selects the line again, which stores a new fingerprint and a new content version, and then shares it again. The same failure is `409 prescription_unavailable`.

If a linked prescription is later corrected, replaced, reshared to a different pharmacy or mode or version, withdrawn, rejected, or deleted, every still-pending or confirmed order for it is marked `prescription_usable = false` in the same transaction, with one `prescription_unusable` history event. The order status is not rewritten and the snapshot stays. The flag is never set back to true. Customer deletion is not blocked; the order remains in history.

Two decisions stay separate. A pending or verified share that still matches the snapshot can create, and can keep, a pending order. `OrderPrescriptionGuard::prescriptionConfirmationBlock` is the later confirmation rule. It returns `prescription_not_verified` for a matching pending share and leaves the order usable. It returns `prescription_unusable` when the flag is false or the share is withdrawn, rejected, deleted, stale, the wrong pharmacy, or a different version. Null means the prescription does not block a future confirmation. It is not permission to confirm. Every order, including one with no prescription, still needs pharmacist stock and price review. The method never sets an order to confirmed, and Phase 6 must not treat a pending share as confirmed.

## Eligibility and price

`App\Discovery\PickupEligibility` holds the pharmacy and inventory SQL used by nearby search and by checkout. The cart's earlier snapshot is not trusted. Checkout locks, in this order: the idempotency row, the cart, the prescription, the selected prescription lines, the pharmacy, medicines by id, then that pharmacy's inventory. It re-reads the selection and the eligibility after those locks. Sharing, correction, withdrawal, replacement, review, and deletion lock the prescription before medicines and before orders. The confirmation guard reads the order without a lock, locks the prescription, then locks the order, and reads both again. It does not lock the order before the prescription. A share or correction that holds the prescription cannot wait on a medicine the checkout already holds, and a withdrawal cannot wait on an order while the guard holds that order and waits on the prescription.

A pending order does not reserve or decrement stock. On-hand quantity is not reduced and no reservation row is written. After submission, stock, price, pharmacy status, and the prescription share can all change. Those changes do not rewrite the submitted snapshot. They can make a later confirmation impossible when the prescription share no longer matches.

Known lines snapshot the medicine identity, requested quantity, inventory version, verification time, and indicative unit price in integer paisa. Totals are estimates. A full-image request has a null estimate and no lines; it is not stored as a zero-price order. Confirmed quantity, confirmed total, pickup window, and cash collection are unset. Payment method is `cash_on_pickup` and payment status is `unpaid`.

What can change after submission, without rewriting the snapshot: catalog identity, inventory quantity and price, pharmacy approval, and the prescription share. Phase 6 has to recheck before it confirms.

## Orders

Public order ids are UUIDs. The customer-facing reference is `MF` plus 8 unambiguous characters and is unique. User ids stay bigints. Pharmacy, medicine, and prescription foreign keys stay UUIDs.

The order, item snapshots, the initial `pending_pharmacy_review` history row, the idempotency row, and the `order_submitted` audit row commit together. A failure rolls all of them back and does not clear the cart. The note and the idempotency key are not part of the audit metadata.

Status graph, in `OrderLifecycle`:

- `pending_pharmacy_review` may later move to `confirmed`, `rejected`, or `cancelled`
- `confirmed` may later move to `ready_for_pickup` or `cancelled`
- `ready_for_pickup` may later move to `collected`
- `collected`, `rejected`, and `cancelled` are final

Phase 5 only creates `pending_pharmacy_review` and performs customer cancellation. There is no pharmacy transition endpoint. Cancellation is allowed from `pending_pharmacy_review` and `confirmed`, and denied from `ready_for_pickup` and every final state except an order that is already `cancelled`. The cancel locks the order row. A repeat cancel returns the same order and does not add another history event or change the stored reason. A concurrent later transition must lock the same row and re-read the status; both cannot commit a conflicting status.

History events and item snapshots cannot be updated or deleted. The order snapshot trigger rejects changes to the note, items' parent fields, pharmacy, mode, estimate, payment fields, public id, and reference. Status and `prescription_usable` can change. Phase 6 has to replace `orders_payment_status_check` and `medifind_guard_order_snapshot` before it stores a confirmed total or cash collection. It must not decrement stock in Phase 5's submit path.

Customer cancellation stores an optional reason of at most 200 characters on the history row only.

## Idempotency

`POST /api/v1/orders` requires `Idempotency-Key`: 16 to 128 letters, numbers, underscores, or hyphens. The raw key is not stored or logged. The table stores its SHA-256 and a fingerprint of the pharmacy, mode, prescription id and version, share mode, items, quantities, note, and cart version. The unique key is `(customer_id, key_hash)` and the row is inserted in the same transaction as the order, so a rollback does not poison the key. Concurrent inserts wait on that unique index; at most one order is created.

The same key and same cart version returns the original order, including when the cart was cleared, inventory changed, or the order was cancelled. It does not add another history or audit row. The response is 200. The first success is 201. The same key with a different cart version returns `409 idempotency_conflict` and writes nothing.

## Customer history

`GET /api/v1/orders` is keyset pagination on `created_at` descending and the internal id descending. The page size is fixed at 20. The cursor is opaque. Omit it for the first page. An empty or malformed cursor is `422 validation_failed`. Bounds are stored and queried as canonical `Y-m-d H:i:s.u` strings so fractional seconds are not dropped. `GET /api/v1/orders/{id}` and cancel use the public id. Another customer's order is `404 not_found`. A suspended customer is `403 account_inactive` and cannot submit or cancel.

Customer-facing copy:

- Pending means the order is awaiting pharmacist review.
- The price is an estimate until the pharmacy confirms it.
- Cash is paid at pickup, not at checkout.
- A full-image request does not mean the contents have been identified or are in stock.

## Schema

Migration `2026_09_27_180000_create_pickup_orders` adds `customer_cart_sequences`, `customer_carts`, `customer_cart_items`, `orders`, `order_items`, `order_status_events`, and `order_submissions`. Rolling it back takes an exclusive lock on `orders` and refuses if any order exists, so it does not drop history. An empty Phase 5 schema rolls back and leaves earlier tables in place.
