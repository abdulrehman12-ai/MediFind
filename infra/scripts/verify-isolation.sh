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
  for required in APP_KEY POSTGRES_PASSWORD POSTGRES_APP_PASSWORD POSTGRES_TEST_PASSWORD REDIS_PASSWORD REDIS_TEST_PASSWORD; do
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

echo "isolation-ok"
