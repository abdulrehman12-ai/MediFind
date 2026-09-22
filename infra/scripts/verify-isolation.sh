#!/bin/sh
set -eu

# Orchestration-layer isolation checks using Compose service environments.
# Application and phpunit containers must not run this script.
# Never print secret values.

if [ -n "${COMPOSE_ENV_FILE:-}" ]; then
  if [ ! -f "$COMPOSE_ENV_FILE" ]; then
    echo "COMPOSE_ENV_FILE is set but the file does not exist or is not a regular file." >&2
    exit 1
  fi
  compose() {
    docker compose --project-name "${COMPOSE_PROJECT_NAME:-medifind}" --env-file "$COMPOSE_ENV_FILE" -f infra/docker-compose.yml "$@"
  }
else
  missing=0
  for required in APP_KEY POSTGRES_PASSWORD POSTGRES_APP_PASSWORD POSTGRES_TEST_PASSWORD REDIS_PASSWORD REDIS_TEST_PASSWORD OTP_HMAC_KEY OTP_TEST_HMAC_KEY; do
    eval "value=\${${required}-}"
    if [ -z "$value" ]; then
      echo "${required} is not set. Export the Compose variables or set COMPOSE_ENV_FILE=infra/local.secrets" >&2
      missing=1
    fi
  done
  if [ "$missing" -ne 0 ]; then
    exit 1
  fi
  compose() {
    docker compose --project-name "${COMPOSE_PROJECT_NAME:-medifind}" -f infra/docker-compose.yml "$@"
  }
fi

fail() {
  echo "$1" >&2
  exit 1
}

echo "=== database role isolation ==="

if compose exec -T postgres bash -lc 'PGPASSWORD="$POSTGRES_APP_PASSWORD" psql -h 127.0.0.1 -U medifind_dev -d medifind_testing -c "select 1"' >/dev/null 2>&1; then
  fail "medifind_dev was able to connect to medifind_testing"
fi
echo "medifind_dev cannot connect to medifind_testing"

if compose exec -T postgres bash -lc 'PGPASSWORD="$POSTGRES_TEST_PASSWORD" psql -h 127.0.0.1 -U medifind_test -d medifind -c "select 1"' >/dev/null 2>&1; then
  fail "medifind_test was able to connect to medifind"
fi
echo "medifind_test cannot connect to medifind"

echo "=== redis credential isolation ==="

dev_from_test="$(compose exec -T redis_test redis-cli -h redis ping 2>&1 || true)"
case "$dev_from_test" in
  *WRONGPASS*) echo "redis_test credentials cannot authenticate to development redis" ;;
  *) fail "expected WRONGPASS when using redis_test credentials against development redis" ;;
esac

test_from_dev="$(compose exec -T redis redis-cli -h redis_test ping 2>&1 || true)"
case "$test_from_dev" in
  *WRONGPASS*) echo "development redis credentials cannot authenticate to redis_test" ;;
  *) fail "expected WRONGPASS when using development redis credentials against redis_test" ;;
esac

echo "=== otp hmac isolation ==="

read_source() {
  name="$1"
  if [ -n "${COMPOSE_ENV_FILE:-}" ]; then
    awk -F= -v k="$name" '$1==k { print substr($0, index($0, "=")+1) }' "$COMPOSE_ENV_FILE" | tr -d '\r\n'
  else
    eval "printf '%s' \"\${${name}-}\"" | tr -d '\r\n'
  fi
}

dev_key="$(read_source OTP_HMAC_KEY)"
test_key="$(read_source OTP_TEST_HMAC_KEY)"

if [ -z "$dev_key" ] || [ -z "$test_key" ]; then
  fail "OTP_HMAC_KEY and OTP_TEST_HMAC_KEY must both be non-empty"
fi
if [ "$dev_key" = "$test_key" ]; then
  fail "OTP_HMAC_KEY and OTP_TEST_HMAC_KEY must differ"
fi

fingerprint() {
  printf '%s' "$1" | sha256sum | awk '{print $1}'
}

dev_fp="$(fingerprint "$dev_key")"
test_fp="$(fingerprint "$test_key")"

assert_empty_source_fails() {
  var="$1"
  errfile="$(mktemp)"
  if (
    eval "export ${var}="
    compose config --quiet
  ) >"$errfile" 2>&1; then
    rm -f "$errfile"
    fail "${var} was accepted when empty"
  fi
  if grep -Eq 'base64:[A-Za-z0-9+/]{16,}|[A-Fa-f0-9]{48,}' "$errfile"; then
    rm -f "$errfile"
    fail "empty ${var} check leaked a secret"
  fi
  if ! grep -q "${var} must be set" "$errfile"; then
    rm -f "$errfile"
    fail "empty ${var} did not fail with its variable name"
  fi
  rm -f "$errfile"
}

assert_empty_source_fails OTP_HMAC_KEY
assert_empty_source_fails OTP_TEST_HMAC_KEY

hash_line() {
  tr -d '\r' | grep -Eo '[a-f0-9]{64}' | tail -n 1
}

assert_runtime_match() {
  service="$1"
  actual="$2"
  if [ "$actual" != "$dev_fp" ]; then
    fail "${service} OTP_HMAC_KEY does not match the development source"
  fi
  if [ "$actual" = "$test_fp" ]; then
    fail "${service} received the test OTP HMAC key"
  fi
}

assert_exec_runtime() {
  service="$1"
  if compose exec -T "$service" sh -c 'printenv OTP_TEST_HMAC_KEY >/dev/null 2>&1'; then
    fail "${service} contains OTP_TEST_HMAC_KEY"
  fi
  actual="$(compose exec -T "$service" php -r 'echo hash("sha256", (string) getenv("OTP_HMAC_KEY"));' | hash_line)"
  assert_runtime_match "$service" "$actual"
}

assert_run_runtime() {
  service="$1"
  if compose run --rm --no-deps --entrypoint sh "$service" -c 'printenv OTP_TEST_HMAC_KEY >/dev/null 2>&1'; then
    fail "${service} contains OTP_TEST_HMAC_KEY"
  fi
  actual="$(compose run --rm --no-deps --entrypoint php "$service" -r 'echo hash("sha256", (string) getenv("OTP_HMAC_KEY"));' | hash_line)"
  assert_runtime_match "$service" "$actual"
}

assert_exec_runtime app
assert_exec_runtime queue
assert_exec_runtime scheduler
assert_run_runtime migrate
assert_run_runtime bootstrap

if compose --profile test run --rm --no-deps --entrypoint sh phpunit -c 'printenv OTP_TEST_HMAC_KEY >/dev/null 2>&1'; then
  fail "phpunit contains OTP_TEST_HMAC_KEY"
fi
phpunit_fp="$(compose --profile test run --rm --no-deps --entrypoint php phpunit -r 'echo hash("sha256", (string) getenv("OTP_HMAC_KEY"));' | hash_line)"
if [ "$phpunit_fp" != "$test_fp" ]; then
  fail "phpunit OTP_HMAC_KEY does not match OTP_TEST_HMAC_KEY"
fi
if [ "$phpunit_fp" = "$dev_fp" ]; then
  fail "phpunit received the development OTP HMAC key"
fi

echo "development and test OTP HMAC keys differ"
echo "isolation-ok"
