# MediFind

MediFind - medicine search and pharmacy pickup platform for Faisalabad

Phase 1A provides the Laravel 13 backend environment (Nginx + PHP-FPM, PostgreSQL/PostGIS, Redis, Mailpit). Phase 1B adds customer OTP login, profiles, saved addresses, and Sanctum access tokens. Phase 2 adds pharmacy profiles, staff invitations, a medicine catalog, inventory, and Filament admin and pharmacy panels. Phase 3 adds signed-in customer medicine search and nearby pickup availability. Phase 4 adds private prescription upload, a local-only synthetic extraction stand-in that fails closed without a fixture, customer medicine selection, pharmacy sharing, and pharmacist review. See `docs/architecture/phase-4-prescription-understanding.md`. Phase 5 adds the customer pickup-order API: one pharmacy per cart, cash on pickup, and orders that stay pending until a pharmacist reviews them. Phase 6 adds the Filament pharmacy pickup queue, quoting, reservation at confirmation, collection, and cash recording. See `docs/architecture/phase-6-pharmacy-fulfillment.md`. Phase 7.1 adds an Android-first Flutter customer app for catalog pickup in `apps/mobile`. Prescription upload, sharing, and quote acceptance are Phase 7.2 and are not in this checkpoint. See `docs/architecture/phase-7.1-customer-mobile.md`.

The `medifind-app:phase1a-localdev`, `medifind-nginx:phase1a-localdev`, and `medifind-postgis:phase1a-localdev` images are local-development images. They are not production-ready.

The `medifind-app:phase1a-localdev`, `medifind-nginx:phase1a-localdev`, and `medifind-postgis:phase1a-localdev` images are local-development images. They are not production-ready.

## Architecture

- Monorepo: `apps/api` (Laravel), `apps/mobile` (Flutter customer app, Android-first), `infra` (Compose), `docs`
- HTTP: dedicated Nginx image `127.0.0.1:8080` (baked `public/` from the same application build) → PHP-FPM
- Isolated mobile HTTP tests use a separate Compose project on `127.0.0.1:18080` (`infra/docker-compose.mobile-http-test.yml`). Do not send the emulator journey to port 8080.
- Data: PostgreSQL 16 + PostGIS 3.5 (internal)
- Cache/queue: Redis 7.4 (internal development instance + separate ephemeral `redis_test`)
- Mail: Mailpit UI `127.0.0.1:8025` (email inspection only; not SMS)
- Private prescription storage is Amazon S3. Tests use a separate local disk and never call AWS. No production bucket is provisioned by this repository, and no production retention period is configured.
- Flutter 3.47.x, JDK 17, and the Android SDK command-line tools. Android Studio is not required. iOS is not a Phase 7.1 verification target.

## Prerequisites

- Docker Engine with Compose and `linux/amd64` (Docker Desktop on this Windows host)
- Git
- Do not install native PHP, Composer, PostgreSQL, or Redis on Windows

## Local setup

1. Copy `infra/.env.example` to gitignored `infra/local.secrets`.
2. Set strong local values for every required variable. Never commit that file.
   - `APP_KEY`
   - `POSTGRES_PASSWORD`
   - `POSTGRES_APP_PASSWORD`
   - `POSTGRES_TEST_PASSWORD`
   - `REDIS_PASSWORD`
   - `REDIS_TEST_PASSWORD`
   - `OTP_HMAC_KEY`
   - `OTP_TEST_HMAC_KEY`
   - `LOCAL_OTP_CODE` as six digits for the local OTP provider
3. `APP_KEY`, `OTP_HMAC_KEY`, and `OTP_TEST_HMAC_KEY` are independent values. Development runtime services use `OTP_HMAC_KEY`. The phpunit service receives `OTP_TEST_HMAC_KEY` as Laravel's `OTP_HMAC_KEY` and does not receive the development key. Generate `APP_KEY` with `php artisan key:generate --show` inside a one-off container after the image exists, or use an equivalent 32-byte base64 key.
4. From the repository root:

```powershell
docker compose --project-name medifind --env-file infra/local.secrets -f infra/docker-compose.yml up -d --build --wait
```

## Services

| Service | Purpose | Host ports |
|---|---|---|
| postgres | PostgreSQL/PostGIS | none |
| redis | Development Redis | none |
| redis_test | Ephemeral automated-test Redis | none |
| migrate | One-shot `artisan migrate` | none |
| bootstrap | One-shot idempotent Faisalabad city seed | none |
| app | PHP-FPM (www-data) | none (FPM healthcheck only) |
| queue | `queue:work` (www-data) | none |
| scheduler | `schedule:work` (www-data); no product schedules yet | none |
| phpunit | Dedicated test profile (`APP_ENV=testing`) | none |
| nginx | HTTP reverse proxy (`medifind-nginx:phase1a-localdev`) | `127.0.0.1:8080` |
| mailpit | Local email UI | `127.0.0.1:8025` |

App, queue, scheduler, migrate, bootstrap, and phpunit run as `www-data` with `no-new-privileges` and `cap_drop: ALL`. Postgres remains root because the official image must initialize data directories. Nginx remains the official master process so it can bind port 80; static files are copied into `medifind-nginx:phase1a-localdev` at image build time (no `app_public` volume). Development Redis starts as root so the official entrypoint can chown the data volume (SETUID/`gosu`); ephemeral `redis_test` runs as `redis` with `cap_drop: ALL`.

App healthcheck probes PHP-FPM FastCGI (`/ping`), not Nginx. Nginx healthcheck calls `GET /api/v1/health/live`. Full-stack readiness is `GET /api/v1/health/ready` (PostgreSQL/PostGIS + development Redis). Live does not query dependencies. `/up` is not registered.

## Database and Redis separation

- `medifind` + role `medifind_dev` only (runtime containers)
- `medifind_testing` + role `medifind_test` only (phpunit service)
- Runtime containers do not receive test credentials. The phpunit service does not receive development credentials.
- `OTP_HMAC_KEY` and `OTP_TEST_HMAC_KEY` are independent. Runtime services do not receive `OTP_TEST_HMAC_KEY`. The phpunit service receives only the test key, as `OTP_HMAC_KEY`.
- `PUBLIC` CONNECT revoked; opposite roles cannot connect
- PostGIS enabled in both databases
- Cross-role checks run from `infra/scripts/verify-isolation.sh`, not application tests
- `migrate:fresh` / `db:wipe` abort unless `APP_ENV=testing` and the live session is `medifind_test` on `medifind_testing`
- Redis cleanup may `FLUSHDB` on `redis_test` only, never `FLUSHALL`, never development Redis
- `redis_test` has no named volume and no AOF/RDB persistence

## Commands

```powershell
docker compose --project-name medifind --env-file infra/local.secrets -f infra/docker-compose.yml ps
docker compose --project-name medifind --env-file infra/local.secrets -f infra/docker-compose.yml logs -f app
docker compose --project-name medifind --env-file infra/local.secrets -f infra/docker-compose.yml exec postgres psql -U medifind_dev -d medifind
docker compose --project-name medifind --env-file infra/local.secrets -f infra/docker-compose.yml exec redis redis-cli ping
docker compose --project-name medifind --env-file infra/local.secrets --profile test -f infra/docker-compose.yml run --rm phpunit
docker compose --project-name medifind --env-file infra/local.secrets --profile test -f infra/docker-compose.yml run --rm --no-deps phpunit vendor/bin/pint --test
docker compose --project-name medifind --env-file infra/local.secrets -f infra/docker-compose.yml exec -T app php /var/www/html/scripts/queue-smoke.php
docker compose --project-name medifind --env-file infra/local.secrets -f infra/docker-compose.yml down
```

Isolation checks stay in `infra/scripts/verify-isolation.sh` (orchestration only). They never print secret values.

PowerShell (repository root, Git Bash):

```powershell
$env:COMPOSE_ENV_FILE = "infra/local.secrets"
& "C:\Program Files\Git\bin\bash.exe" infra/scripts/verify-isolation.sh
```

POSIX shell (repository root):

```sh
COMPOSE_ENV_FILE=infra/local.secrets sh infra/scripts/verify-isolation.sh
```

CI continues to pass Compose variables through `GITHUB_ENV` and can run `sh infra/scripts/verify-isolation.sh` without `COMPOSE_ENV_FILE`.

Re-run bootstrap safely:

```powershell
docker compose --project-name medifind --env-file infra/local.secrets -f infra/docker-compose.yml run --rm --no-deps bootstrap
```

## Health

- `GET http://127.0.0.1:8080/api/v1/health/live`
- `GET http://127.0.0.1:8080/api/v1/health/ready` (HTTP 503 if a required dependency is down)

Responses include a server-generated `X-Request-Id`. Client-supplied `X-Request-Id` values are ignored. They do not expose credentials, hostnames, versions, or stack traces.

## Secrets

- Never commit `.env`, `infra/local.secrets`, `infra/mobile-http-test.secrets`, tokens, or passwords
- Never log OTPs, Sanctum tokens, passwords, Authorization headers, Redis/DB secrets, or full phone numbers
- Nginx access logs record `$uri` (no query string) and the Laravel-generated `$upstream_http_x_request_id`. Client `X-Request-Id` is not forwarded to PHP-FPM and is not the authoritative log field.
- CI generates ephemeral credentials per job and does not store them as repository secrets

## Image pins

See `docs/architecture/phase-1a-foundation.md` for verified tags, digests, and the local-development image label.

## Troubleshooting

- Compose config fails: missing variables in `--env-file`
- Postgres init ran only on an empty volume; `down -v` is required to recreate roles
- Nginx 502: wait for `app` healthy (FPM ping) before expecting HTTP. Nginx re-resolves `app` via Docker DNS (`127.0.0.11`) so a recreated PHP-FPM container does not leave a stale upstream IP.
- Tests must use the `phpunit` Compose service / `test` profile, never the `app` container
- The Android emulator catalog journey must use the isolated project on `127.0.0.1:18080` (`10.0.2.2:18080` from the emulator), never development port 8080

## Phase 7.1 Flutter app

See `apps/mobile/README.md` and `docs/architecture/phase-7.1-customer-mobile.md`.

Isolated HTTP API (does not use the development database volume or port 8080):

```powershell
python infra/scripts/init-mobile-http-test-secrets.py
docker compose --project-name medifind-mobile-http-test --env-file infra/mobile-http-test.secrets -f infra/docker-compose.mobile-http-test.yml up -d --build --wait
```

Stop only that project:

```powershell
docker compose --project-name medifind-mobile-http-test -f infra/docker-compose.mobile-http-test.yml down
```

Do not pass `-v` against the development `medifind` project.
