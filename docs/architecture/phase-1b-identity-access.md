# Phase 1B customer identity and access

Phase 1B adds Pakistani mobile OTP login, Sanctum customer tokens, profiles, and saved Faisalabad addresses. Pharmacy staff, medicine search, orders, notifications, and real SMS are out of scope.

The local image tag remains `medifind-app:phase1a-localdev` / `medifind-nginx:phase1a-localdev`. These images are still local-development images.

## Identity

`users.id` stays an internal bigint. API clients see `users.public_id` as `id`. `full_name`, `email`, and `password` stay null until the customer sets them. OTP registration does not invent those values. `name` remains only so the Phase 1B user migration can roll back; application code does not treat it as the profile name.

`status` is `active` or `suspended`. Deletion is `deleted_at`. Active phone numbers and active emails are unique. A soft-deleted phone or email can be registered again. Suspended accounts cannot.

OTP login refuses `pharmacy_staff` and `admin` rows with `403 account_role_forbidden`. Customer resources expose `phone_masked` (for example `+92300***4567`). They do not expose `phone_number`, `phone_e164`, the internal user id, the password, `remember_token`, or the legacy `name`. The stored phone remains canonical E.164. Generic `User` and `UserAddress` serialization hides those internal fields as well. Profile and address JSON is produced only by the profile objects.

## OTP

Redis keys use the prefix `mf:otp:v1:` and HMAC-SHA256 identifiers. Development runtime services use `OTP_HMAC_KEY`, which is independent from `APP_KEY`. The phpunit service uses a separate required Compose input, `OTP_TEST_HMAC_KEY`, and exposes that value to Laravel as `OTP_HMAC_KEY`. It does not receive the development key, and runtime services do not receive `OTP_TEST_HMAC_KEY`. The two source keys are generated independently and must differ. Phone, IP, and OTP values use the domain prefixes `phone|`, `ip|`, and `otp|`. Redis does not store the plaintext OTP, the raw phone number, or the raw IP address.

`app/Identity/Otp/otp_request.lua` is the only request mutation. It reads Redis `TIME`, trims 60-minute rolling windows, enforces the IP cap (20) and phone send cap (5), honors the 60-second resend cooldown, invalidates the previous challenge, and writes the new challenge. The sorted-set member is a server-generated request UUID, so two events in the same millisecond both count.

Verification takes `mf:otp:v1:lock:{challengeId}` with `SET NX PX 5000` and a random 128-bit token. The lock is released with a compare-and-delete. OTP comparison uses PHP `hash_equals`. A successful challenge is deleted before the customer row and token are created. If that database work fails, the API returns `503 otp_unavailable` and does not restore the challenge.

Provider send failures delete that challenge, clear its active pointer only when it still names that challenge, leave the rate-limit counters consumed, and return `503 otp_delivery_unavailable`. Logs contain the event name and request ID only. A cleanup error after that failure does not replace the delivery response.

Redis connection and command failures throw `OtpStoreUnavailable`. Both public OTP endpoints render that as `503 otp_unavailable` with a generic message. The exception is not reported, so the response and logs do not include the Redis host, port, password, command text, or stack trace.

A successful OTP request returns `challenge_id`, `expires_in`, and `retry_after`. `retry_after` is the cooldown value returned by the Redis script. HTTP 429 responses also send `Retry-After`.

Both public OTP routes share a coarse limiter of 120 requests per minute per server-controlled IP. The limiter key is the domain-separated IP HMAC, so malformed requests and unknown challenges count before the business rules run. The business limits stay at 20 valid attempts per rolling hour per IP, 5 accepted sends per rolling hour per phone, a 60-second phone cooldown, and `medifind.otp.max_attempts` failed verifications per challenge.

`SMS_OTP_PROVIDER=local` requires a six-digit `LOCAL_OTP_CODE` at boot, except when the Artisan command is exactly `package:discover`. That command is the first non-option argument after the `artisan` script, compared exactly. An argument that merely contains the text, such as `queue:work --name=package:discover`, does not skip validation. The memory provider is allowed only when `APP_ENV=testing`. Production boot fails closed when the provider is local or memory, when `LOCAL_OTP_CODE` is set, when the HMAC key is missing or invalid, or when no production provider exists. Web, queue, scheduler, and unrelated console boots do not skip that check.

Security events are `otp_requested`, `otp_throttled`, `otp_verify_failed`, `otp_verify_succeeded`, `token_revoked`, and `authorization_denied`. Each record is the event name plus `request_id`.

## Access tokens

Sanctum personal access tokens expire after 30 days and carry only `customer:access`. Stateful domains are empty, and `EnsureFrontendRequestsAreStateful` is not enabled. Logout revokes the current token. Logout-all revokes every token for that user. A suspended account is rejected with `403 account_inactive` while a still-valid token exists. Suspension also deletes tokens in `AccountStatusService` and in the PostgreSQL trigger `medifind_user_token_revocation`. The trigger runs when `status`, `deleted_at`, or `role` is updated. It deletes tokens when an account transitions to `suspended`, when `deleted_at` changes from null to a timestamp, or when `role` changes away from `customer`. Returning the account to `customer` does not restore deleted tokens. Profile updates that do not change those columns leave tokens in place. A soft-deleted owner resolves as `401 unauthenticated`.

## Addresses

The first bootstrap creates one Faisalabad row with `country_code` PK, timezone `Asia/Karachi`, and `is_active` true. Later bootstrap runs stay idempotent. They may correct non-operational metadata such as the timezone, and they do not overwrite `is_active`. An intentionally inactive Faisalabad row stays inactive, so address creation continues to return `503 address_unavailable`.

`user_addresses` stores a public UUID, an internal user foreign key, and the active Faisalabad city (`country_code = PK`, `name = Faisalabad`, `is_active = true`). If that city is inactive, address creation returns `503 address_unavailable`. The client cannot send `city_id`. The response `city` is the related city's name. Latitude and longitude are `numeric(9,6)` and database checks reject NaN, infinities, and out-of-range values. A generated PostGIS geography point has a GiST index. One non-deleted default per user is enforced by a partial unique index and by a transaction advisory lock. A one-default race is retried once. A second violation of `user_addresses_one_default_unique` returns `409 address_conflict`. Other unique violations are not mapped to that code. The first address is the default, including when create sends `is_default: false`. `PATCH` accepts `is_default: true` as promotion and rejects `is_default: false` with `422`. Deleting the default promotes the remaining row with the latest `updated_at`. The cap is 10 addresses.

Profile and address routes act on the authenticated customer. Address reads and writes go through owner-scoped queries before any mutation, so another customer's UUID is `404`. That boundary is the customer-access middleware plus `AddressBook` ownership checks. A separate policy class is not used, because a policy denial would surface as `403` and would reveal that the UUID exists.

## Browser CORS

Allowed origins come from `CORS_ALLOWED_ORIGINS`, defaulting to `http://127.0.0.1:8080`. Allowed headers are `Accept`, `Authorization`, `Content-Type`, and `X-Request-Id`. Origins and headers are explicit lists. Credentialed CORS stays disabled, so a permitted browser client can complete a bearer-token preflight.

## Client IP

Nginx overwrites `HTTP_X_MEDIFIND_CLIENT_IP` from `$remote_addr` and clears `X-Forwarded-For` and `X-Real-IP`. It does not add a public client-IP response header. PHPUnit requests that do not pass through Nginx use `REMOTE_ADDR`.

## API

Successful responses other than `204` use `{ "data": {}, "meta": { "request_id": "..." } }`. Errors use `{ "error": { "code", "message", "details" }, "meta": { "request_id" } }`. Health responses keep the Phase 1A shape. Mutation requests reject unknown fields. `docs/api/openapi-v1.yaml` is checked for OpenAPI 3.x structure, unique operation IDs, exact Phase 1B route alignment, and the critical customer, OTP, address, and error fields. Laravel's automatic `HEAD` routes are excluded. That test is not a full schema certification.

## Tests and rollout

Automated tests run against `medifind_testing` / `medifind_test` / `redis_test`. The `phase1b-upgrade` group is not part of the parallel suite. It wipes only the testing database, migrates the Phase 1A boundary, upgrades one sentinel user, checks rollback order, and then `migrate:fresh` restores the testing schema. CI repeats that restore with `if: always()` before the suite that excludes the upgrade group.
