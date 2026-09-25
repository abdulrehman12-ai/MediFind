# MediFind

MediFind - medicine search and pharmacy pickup platform for Faisalabad

Phase 1A provides the Laravel 13 backend environment (Nginx + PHP-FPM, PostgreSQL/PostGIS, Redis, Mailpit). Phase 1B adds customer OTP login, profiles, saved addresses, and Sanctum access tokens. Phase 2 adds pharmacy profiles, staff invitations, a medicine catalog, inventory, and Filament admin and pharmacy panels. Customer search, Flutter, prescriptions, orders, and payments are not in this checkpoint. See `docs/architecture/phase-2-pharmacy-catalog.md`.

The `medifind-app:phase1a-localdev`, `medifind-nginx:phase1a-localdev`, and `medifind-postgis:phase1a-localdev` images are local-development images. They are not production-ready.

## Architecture

- Monorepo: `apps/api` (Laravel), `infra` (Compose), `docs`
- HTTP: dedicated Nginx image `127.0.0.1:8080` (baked `public/` from the same application build) → PHP-FPM
- Data: PostgreSQL 16 + PostGIS 3.5 (internal)
- Cache/queue: Redis 7.4 (internal development instance + separate ephemeral `redis_test`)
- Mail: Mailpit UI `127.0.0.1:8025` (email inspection only; not SMS)
- MinIO / private S3 is deferred to Phase 4 (prescription upload)
- Flutter is deferred until the mobile gate (JDK 17, Android Studio/SDK, Flutter 3.47.x)

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

- Never commit `.env`, `infra/local.secrets`, tokens, or passwords
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
