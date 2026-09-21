# ADR 0004: Separate development and test Redis

## Status

Accepted for Phase 1A.

## Context

OTP, cache, queues, and rate limits will use Redis. Tests must not flush or pollute development Redis.

## Decision

Run `redis` (development) and `redis_test` (automated tests) as internal services with distinct passwords. Tests may FLUSHDB only on `redis_test` after `APP_ENV=testing` and host checks. Never FLUSHALL.

## Consequences

PHPUnit forces `REDIS_HOST=redis_test`. Application runtime uses `redis`.
