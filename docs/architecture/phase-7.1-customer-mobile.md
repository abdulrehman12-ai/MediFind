# Phase 7.1 customer mobile (catalog pickup)

Phase 7.1 is an Android-first Flutter customer app in `apps/mobile`. It consumes the existing `/api/v1` customer contract. It does not confirm orders, collect cash, reserve stock, or talk to S3. iOS is not verified in this checkpoint. Prescription upload, private file viewing, share/withdraw, prescription carts, and quote acceptance are Phase 7.2.

Launch rules stay Faisalabad, one pharmacy per order, and cash on pickup.

## Tooling

Pinned for this repository: Flutter 3.47.x (install the current 3.47 stable patch, currently 3.47.6), JDK 17, Android SDK command-line tools. Android Studio is not required. SDK licenses are accepted by the developer, not by automation.

## Isolated HTTP API

The development Compose project (`medifind`, host port 8080) must not receive the automated emulator journey.

`infra/docker-compose.mobile-http-test.yml` is a separate project (`medifind-mobile-http-test`) on `127.0.0.1:18080`. It uses its own Postgres volume `medifind_mobile_http_postgres`, network `medifind_mobile_http`, Redis tmpfs, `APP_ENV=testing`, `medifind_testing` / `medifind_test`, `redis_test`, and `prescriptions_testing`. App, nginx, and PostGIS images are tagged `phase7.1-mobile-http-test` so isolated rebuilds never overwrite development `phase1a-localdev` tags. Credentials live in gitignored `infra/mobile-http-test.secrets` and must not be copied from `infra/local.secrets`.

Before migrate or seed, `php artisan medifind:assert-isolated-http-runtime` fails closed unless those identities match. OTP uses the existing local provider and a six-digit `LOCAL_OTP_CODE` supplied only to the isolated app and the test runner. The API never returns the code.

The Android emulator reaches the host via `10.0.2.2:18080`, not `127.0.0.1`. Cleartext HTTP is allowed only in debug/test. Release builds require HTTPS and reject localhost and emulator loopback URLs.

Cleanup: `docker compose --project-name medifind-mobile-http-test -f infra/docker-compose.mobile-http-test.yml down`. Do not delete development volumes.

## Address and location

The API requires latitude and longitude. There is no map or geocoding vendor.

- Preferred: the customer grants location permission; the app copies GPS coordinates and the customer types the street label separately. The UI states that the street text was not geocoded.
- Fallback: the customer types coordinates themselves, with the same honesty copy.
- The app never invents a city-centre pin.

The server currently labels saved addresses with the Faisalabad city catalog row. That is a server catalog choice, not proof that the pin was geocoded.

## Catalog-only checkout

Search and availability still return prescription-required, non-controlled medicines. Phase 7.1 must not present those lines as orderable. The user is told that prescription ordering arrives in 7.2. The app does not fabricate a share.

Cart versions and submit idempotency keys are preserved across retries and process death until the result is reconciled. A `200` replay is the same order.

## Status copy

Pending is a request. An accepted quote (read-only in 7.1 if present) still awaits pharmacist confirmation. Confirmed is not ready. Ready for pickup is unpaid until collection. Collected follows `payment_status` and `cash_collected_paisa`. Stock is reserved at confirmation; on-hand falls at collection.

Pickup instants are stored as UTC and shown in Asia/Karachi with a visible zone label.

## Privacy

Bearer tokens live in platform secure storage, not ordinary preferences. Android backup of sensitive data is disabled. OTP, full phone numbers, tokens, notes, and medical content are not logged. The client does not send `X-Request-Id`.
