# Phase 1A foundation

This checkpoint produces a **local-development** stack.

- `medifind-app:phase1a-localdev`, `medifind-nginx:phase1a-localdev`, and `medifind-postgis:phase1a-localdev` are local-development images.
- They are **not approved for production deployment**.
- Production container selection and a new vulnerability review are mandatory before any deployment.
- Any newly available security fix must be applied through dependency and image updates.

## Components

- `apps/api`: Laravel 13 API (health, request IDs, city seed)
- `infra`: Docker Compose (baked Nginx, PHP-FPM, migrate, bootstrap, queue, scheduler, phpunit profile, derived PostGIS, Redis, redis_test, Mailpit)
- GitHub Actions: `backend.yml` for functional verification; `image-scan.yml` for SHA-pinned Trivy (reporting + blocking) and SBOM/report artifacts

## Assumptions

- UTC is the storage timezone. `Asia/Karachi` is the business/display timezone.
- Faisalabad (`PK`) is the only seeded city.
- PHP-FPM liveness is independent of Nginx to avoid a healthcheck cycle.
- MinIO is not deployed. Private S3-compatible prescription storage belongs to Phase 4.
- Flutter is not scaffolded until the mobile gate.
- The scheduler process runs with an empty schedule until a later phase registers real tasks.

## Authentication and product APIs

Not implemented in Phase 1A.

## Local infrastructure

See the root README. Migrations never run from app/queue/scheduler entrypoints. `migrate` then `bootstrap` are one-shot services. App, queue, and scheduler start only after both succeed.

Runtime services (`app`, `queue`, `scheduler`, `migrate`, `bootstrap`) receive only `medifind_dev` and development Redis credentials. Automated tests run only in the `phpunit` service (`profiles: [test]`) with `medifind_test` / `redis_test` credentials.

## Public asset delivery

Nginx is built from the same `infra/php/Dockerfile` as the application image (`target: nginx`), using the same repository revision and Composer/npm lockfiles. The `php_builder` stage installs PHP dependencies and runs the Vite build; the Nginx stage copies `/var/www/html/public` from that stage into `medifind-nginx:phase1a-localdev`.

The persistent `app_public` named volume is removed. Compose does not bind-mount `public/build` from the Windows host. A rebuild of the application/Nginx images is required to publish new static files.

The Nginx stage installs pinned Debian bookworm security packages on top of the pinned `nginx:1.28.0-bookworm` digest: `libgnutls30=3.7.9-2+deb12u7`, `libssl3=3.0.20-1~deb12u2`, `openssl=3.0.20-1~deb12u2`. This remains a local-development image.

## Process hardening

- Application PHP services run as `www-data` with `no-new-privileges:true` and `cap_drop: ALL`.
- Redis and Mailpit use `no-new-privileges` and `cap_drop: ALL` where the process can start without SETUID. Development Redis uses the official entrypoint as root so it can chown the persistent `/data` volume and then drop to the `redis` user via `gosu`; `cap_drop: ALL` and `no-new-privileges` would block that SETUID. Ephemeral `redis_test` runs as `redis` with those restrictions because it uses tmpfs, not a named volume.
- Postgres uses the official entrypoint as root so it can initialize data directories and shared memory. Extra capabilities are not added.
- Nginx uses the official image master process so it can bind TCP/80. Extra capabilities are not added.

## Verified image pins (linux/amd64)

| Image | Digest |
|---|---|
| php:8.4.25-fpm-bookworm | sha256:0a5638c2c48eb04987107ae448a398dd18345bcf659e15fd99a422617e754264 |
| composer:2.8.12 | sha256:5248900ab8b5f7f880c2d62180e40960cd87f60149ec9a1abfd62ac72a02577c |
| node:24.21.0-bookworm | sha256:64af3819f9275802414d7cdc38c27e9d82bd564dec4d4da87d008255d36c63b4 |
| nginx:1.28.0-bookworm | sha256:552e7481ca93ffccd046aa658dbbed22caefbc09c66fa7cd247cbb90b8a5c609 |
| postgis/postgis:16-3.5 (parent of derived image) | sha256:94146ac37bc61e2322f88016056c5920729cb8c64c8542ed590af8fc2abdac07 |
| tianon/gosu:1.19 (copied into derived PostGIS) | sha256:5afac3970da83806ba3d7789a3a42da92fbe4f703d94984c601e183208d035d3 |
| redis:7.4-alpine (Redis 7.4.11) | sha256:858f009f9709ce576febc734aa78b8f6d624b82571f9ddb6bda4377c833b3499 |
| axllent/mailpit:v1.31.2 | sha256:74d609a42ec279aa63c6b4622a6fa9b5408d1ad5b1d76a1c4be40a265ce0863d |
| docker/dockerfile:1.7 | sha256:a57df69d0ea827fb7266491f2813635de6f17269be881f696fbfdf2d83dda33e |
| pecl redis | 6.3.0 |

Local-development images built from those bases:

- `medifind-app:phase1a-localdev` (`Dockerfile` target `runtime`)
- `medifind-nginx:phase1a-localdev` (`Dockerfile` target `nginx`)
- `medifind-postgis:phase1a-localdev` (`infra/postgres/Dockerfile`)

## Redis fixable CRITICAL findings and resolution

Official `redis:7.4.6-alpine@sha256:3b73847e72874be07e6657b129a94761662b79bc0f679273757d4218573b2a98` had these **fixable CRITICAL** findings:

| CVE | Package | Installed | Fixed | Runtime path |
|---|---|---|---|---|
| CVE-2026-31789 | libcrypto3 / libssl3 | 3.3.5-r0 | 3.3.7-r0 | OpenSSL in the Alpine runtime; Redis may use it for TLS. This stack uses password auth on an internal Docker network, not Redis TLS. |
| CVE-2023-24538 | gosu Go stdlib | v1.18.2 | 1.19.8, 1.20.3 | `usr/local/bin/gosu` at container start only (privilege drop). |
| CVE-2023-24540 | gosu Go stdlib | v1.18.2 | 1.19.9, 1.20.4 | Same gosu start path. |
| CVE-2024-24790 | gosu Go stdlib | v1.18.2 | 1.21.11, 1.22.4 | Same gosu start path. |
| CVE-2025-68121 | gosu Go stdlib | v1.18.2 | 1.24.13, 1.25.7, 1.26.0-rc.3 | Same gosu start path. `crypto/tls` session resumption is not how Redis serves clients here. |

No `redis:7.4.7` / `7.4.8` tags existed. Official `redis:7.4-alpine@sha256:858f009f9709ce576febc734aa78b8f6d624b82571f9ddb6bda4377c833b3499` is Redis **7.4.11** on Alpine 3.21.8 (same 7.4 minor line, not a Redis major upgrade). Trivy `--severity CRITICAL --ignore-unfixed` on that digest reported **0** findings. Compose now pins that digest.

## PostGIS fixable CRITICAL findings and resolution

Official `postgis/postgis:16-3.5@sha256:94146ac37bc61e2322f88016056c5920729cb8c64c8542ed590af8fc2abdac07` (Debian 11.11) had these **fixable CRITICAL** findings:

| CVE | Package | Installed | Fixed | Runtime path |
|---|---|---|---|---|
| CVE-2026-33845 | libgnutls30 | 3.7.1-5+deb11u7 | 3.7.1-5+deb11u10 | GnuTLS is present in the Debian runtime; PostgreSQL in this image uses OpenSSL for server TLS. DTLS fragment DoS is not on the local Docker SQL path. |
| CVE-2026-42010 | libgnutls30 | 3.7.1-5+deb11u7 | 3.7.1-5+deb11u10 | GnuTLS username NUL bypass; not used for Postgres role authentication in this stack. |
| CVE-2023-24538 | gosu Go stdlib | v1.18.2 | 1.19.8, 1.20.3 | Official entrypoint `gosu` at start only. |
| CVE-2023-24540 | gosu Go stdlib | v1.18.2 | 1.19.9, 1.20.4 | Same. |
| CVE-2024-24790 | gosu Go stdlib | v1.18.2 | 1.21.11, 1.22.4 | Same. |
| CVE-2025-68121 | gosu Go stdlib | v1.18.2 | 1.24.13+ | Same. |

No newer official `postgis/postgis:16-3.5` digest and no `16-3.5.x` patch tags were published. `16-3.6` tags were not available. `postgis/postgis:16-3.5-alpine` still had fixable CRITICAL CVE-2025-68121 in gosu (Go 1.24.6). PostgreSQL/PostGIS majors were not changed.

`medifind-postgis:phase1a-localdev` is a **minimal derived local-development image**:

1. Parent: `postgis/postgis:16-3.5@sha256:94146ac37bc61e2322f88016056c5920729cb8c64c8542ed590af8fc2abdac07`
2. `apt-get install libgnutls30=3.7.1-5+deb11u10` (pinned Debian security update)
3. Replace `/usr/local/bin/gosu` with official `tianon/gosu:1.19@sha256:5afac3970da83806ba3d7789a3a42da92fbe4f703d94984c601e183208d035d3`

Installed versions recorded in the image at `/etc/medifind-localdev-packages.txt`:

- `libgnutls30=3.7.1-5+deb11u10`
- `gosu=1.19 (go1.24.6 on linux/amd64; gc)`

### Formal temporary local-development exception (PostGIS gosu)

Latest official gosu **1.19** is still linked against Go **1.24.6**, so Trivy still reports **fixable CRITICAL CVE-2025-68121** (`crypto/tls` session resumption; fixed in Go 1.24.13 / 1.25.7 / 1.26.0-rc.3). There is no newer official gosu image. Rebuilding gosu from source was not treated as a safe unreviewed workaround.

- **Present in runtime execution path?** Only the Postgres entrypoint privilege-drop at container start. gosu does not terminate PostgreSQL TLS or execute SQL.
- **Ignore file:** `infra/trivy/postgis-localdev.trivyignore` (blocking scan for this image only).
- **Review trigger:** a gosu release built with Go >= 1.24.13, or an official `postgis/postgis:16-3.5` digest that ships a patched gosu.

## PHP image remaining unfixed CRITICAL findings

These remain on `medifind-app:phase1a-localdev` after removing PHPIZE_DEPS shipped by official `php:8.4.25-fpm-bookworm`. Debian marks them unfixed or will-not-fix. The blocking Trivy pass uses `--ignore-unfixed` and therefore does not fail on them. They are **not** accepted as production risk.

| CVE | Package | Fix status | Application relevance | Current mitigation | Review trigger |
|---|---|---|---|---|---|
| CVE-2025-7458 | libsqlite3-0 3.40.1-2+deb12u2 | unfixed (`affected`, no Fixed Version) | Laravel uses PostgreSQL, not SQLite, for application data. SQLite is present because the official PHP base includes it. | Local-dev only; do not expose the image. Prefer PostgreSQL for all app data. | A PHP 8.4 bookworm (or successor) image that ships a patched libsqlite3. |
| CVE-2026-6653 | libxml2 2.9.14+dfsg-1.3~deb12u6 | `fix_deferred` | PHP XML/DOM extensions may link libxml2. Phase 1A has no untrusted XML ingest. | Local-dev only; no XML product endpoints. | Debian or official PHP image publishes a libxml2 fix. |
| CVE-2026-13221 | perl-base 5.36.0-7+deb12u3 | unfixed (`affected`) | Perl is not on the PHP-FPM request path. It remains as a Debian base package. | Local-dev only; application does not invoke Perl. | Bookworm perl-base security update or a PHP image without perl-base. |
| CVE-2026-8376 | perl-base 5.36.0-7+deb12u3 | unfixed (`affected`) | Same as above. | Same. | Same. |
| CVE-2026-42496 | perl-base 5.36.0-7+deb12u3 | `fix_deferred` | Same as above. | Same. | Same. |
| CVE-2023-45853 | zlib1g 1:1.2.13.dfsg-1 | `will_not_fix` | zlib is used widely (PHP). MiniZip-related issue in the zlib packaging; no product zip-bomb endpoint in Phase 1A. | Local-dev only; keep `client_max_body_size` limited. | A PHP base image with a zlib that Debian marks fixed, or successor distro. |

The runtime-stage `apt-get purge` of gcc/make/linux-libc-dev is intentional: those packages **are** installed by the official `php-fpm-bookworm` base, not by the MediFind `libpq5` install. Purging them is not a no-op.

## Vulnerability scan policy

`image-scan.yml` uses Trivy `0.67.0@sha256:94711c60051c6cab848a292e3a67f62623fcee361b2bb661f43b17184f4afdac` with a persistent cache volume.

1. **Reporting scan:** HIGH and CRITICAL, including unfixed. Table output is written under `reports/` and uploaded.
2. **Blocking scan:** CRITICAL, `--ignore-unfixed`, `--exit-code 1`. Fails the job when a **fixable** CRITICAL exists on app, nginx, redis, or postgis (postgis uses the documented gosu ignore file only).

Artifacts (SHA-pinned `actions/upload-artifact@ea165f8d65b6e75b540449e92b4886f43607fa02`, 14-day retention): application SPDX SBOM, application Trivy report, Redis Trivy report, PostGIS Trivy report. Credentials, env files, Compose config, and application logs are not uploaded.
