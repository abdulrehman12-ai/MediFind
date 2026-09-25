# Phase 3 customer discovery

Phase 3 lets a signed-in customer search the active medicine catalog and see pharmacies near one location that can offer every selected medicine for pickup. The result is discovery information. It does not reserve stock, confirm a price, create an order, or start checkout.

Flutter, prescription upload, OCR, medicine recommendations, orders, payments, delivery, and production map or SMS providers are out of scope. Draft medicine-use text stays unpublished.

## Contract

Both routes use `auth:sanctum` and `customer.access`. Success responses keep `{ "data", "meta": { "request_id", ... } }`. Errors keep `{ "error": { "code", "message", "details" }, "meta" }`.

`GET /api/v1/medicines` searches active, non-controlled medicines by normalized generic name, brand name, and alias. A controlled medicine stays in the internal catalog and is not a customer search result. The query `q` is required. Both the minimum and the maximum length are applied after `CatalogText` normalization: 2 to 80 characters. A shorter raw string whose normalized form is longer than 80 characters is rejected. `%`, `_`, and `\` in the customer's text are escaped before `LIKE`, so they are not SQL wildcards. Ranking is exact match, then prefix, then substring, then medicine UUID. Each physical variant is a separate row. An alias that points at several medicines returns each of those medicines. One strength, form, or manufacturer is never replaced with another.

`GET /api/v1/availability` accepts 1 to 10 distinct medicine UUIDs and exactly one origin: `address_id` for an address owned by the customer, or `latitude` and `longitude` together. Ids are compared in lowercase. An uppercase UUID is accepted. Two spellings of the same UUID are one id and return `422 validation_failed`. Another customer's address is `404 not_found`. Sending both origins, neither origin, a duplicate medicine id, or an out-of-range coordinate is `422 validation_failed`. The server-generated `request_id` is written after any extra response metadata, so a caller cannot replace it.

If any selected id is unknown, inactive, controlled, or missing its catalog identity, the response is `422 medicine_unavailable`. The message and details do not say which id failed or why. The service does not drop that id and return pharmacies for the rest.

An empty pharmacy list is `200` with `data: []`. That means no eligible pharmacy matched. It does not mean a selected medicine was ignored.

Pagination defaults to 20 rows and allows at most 50. The offset `(page - 1) * per_page` must be below 500. `has_more` is true when another row exists after the page. There is no unbounded pharmacy dump.

The default radius is 10 km (`radius_meters` 10000). The maximum is 50 km. Inventory must have been verified within the previous 24 hours, including a timestamp exactly 24 hours old, and not in the future. These limits live in `config/medifind.php` under `discovery`.

## Eligibility

A pharmacy is included only when all of the following are true:

- status `approved`
- license review `verified`
- location review `approved`
- city is the active Faisalabad row (`country_code` PK, `name` Faisalabad, `is_active`)
- generated PostGIS `location` is present

There is no guessed Faisalabad polygon. A pharmacy outside that city row is excluded even if its coordinates are near the customer. Deactivating or renaming the Faisalabad city row removes every pharmacy from discovery until the row is restored.

For every selected medicine the pharmacy needs an inventory row that is available for pickup, `in_stock` or `low_stock`, and verified inside the freshness window. `in_stock` may have a null quantity. `low_stock` needs a positive quantity. `unknown`, `out_of_stock`, pickup disabled, stale, and future `last_verified_at` values do not count. The same rules apply to a one-medicine request and a multi-medicine request. A pharmacy missing any requested medicine is omitted.

The page of pharmacies and the medicine summaries on that page come from one PostgreSQL statement. That statement joins only the inventory rows that pass the pickup rules, groups them by pharmacy, and keeps a pharmacy when the grouped rows contain every requested medicine. The JSON summaries are those same grouped rows, so a result cannot pair an eligible pharmacy with an out-of-stock or pickup-disabled summary from a later read. `open_now` is read after that statement and does not affect eligibility.

Controlled or inactive medicines never produce pickup availability. The database constraints from Phase 2 still reject pickup rows for those medicines. The API rejects the request before it looks for pharmacies.

## Distance, map, and hours

Distance uses PostGIS geography: `ST_DWithin` for the radius and `ST_Distance` for ordering, with the origin bound as `ST_MakePoint(longitude, latitude)`. Results are ordered by that distance, then pharmacy UUID. The response field `distance_meters` is the rounded straight-line distance. `distance_method` is `straight_line` on each pharmacy and in `meta`. It is not a driving distance or a travel time.

Each page object contains the coordinates used by a map. The list and the map read the same objects. There is no second payload.

`open_now` uses the existing Asia/Karachi operating-hour rows. Split shifts, overnight intervals, Sunday-to-Monday wrap, and all-day intervals follow the Phase 2 half-open clock. A pharmacy with no hour rows has `open_now: null` (unknown). A pharmacy with rows that do not cover the current minute has `false`. Opening state does not decide eligibility.

Customer responses include pharmacy name, address, coordinates, straight-line distance, the selected medicines' public identity, stock status, indicative price in paisa, and last verification time. They omit staff, license evidence, phone numbers, exact quantities, audit rows, and medicine-use text. `meta.confirmation` states that stock and price require pharmacy confirmation later.

## Indexes

The additive migration `2026_09_25_130000_add_discovery_indexes` adds:

- partial btree indexes on active normalized generic and brand names, for left-anchored prefix search
- a partial index on pickup inventory by medicine, pharmacy, and verification time
- a partial GiST index on eligible pharmacies' geography

Substring search cannot use the btree indexes. Alias lookup uses the existing `medicine_aliases_normalized_alias_index`. No new PostgreSQL extension is required. PostGIS is already enabled in the development and test databases.

## Prescription reuse

A later prescription flow can collect explicit medicine variant UUIDs and call the same availability query. Matching still requires every selected medicine. The prescription phase must not turn OCR text or a draft medicine-use note into a silent substitution, and it must not treat this response as a held reservation.
