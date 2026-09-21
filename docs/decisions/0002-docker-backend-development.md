# ADR 0002: Docker-based backend development

## Status

Accepted for Phase 1A.

## Context

The Windows development host does not have PHP, Composer, PostgreSQL, or Redis installed, and native Windows Redis/Postgres is a poor fit.

## Decision

Run Nginx, PHP-FPM, PostgreSQL/PostGIS, Redis, queue, scheduler, Mailpit, migrate, and bootstrap via Docker Compose. Pin images by tag and digest. Do not publish Postgres or Redis ports.

## Consequences

Backend work does not require system-wide PHP. Flutter remains a separate host install.
