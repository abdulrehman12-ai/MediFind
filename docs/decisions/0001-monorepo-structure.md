# ADR 0001: Monorepo structure

## Status

Accepted for Phase 1A.

## Context

MediFind has a Laravel API and a future Flutter customer app that must version together.

## Decision

Use a single GitHub repository with `apps/api`, `apps/mobile`, `infra`, and `docs`.

## Consequences

Shared CI and documentation. Phase 7.1 adds the Android-first Flutter customer app. iOS remains unverified.
