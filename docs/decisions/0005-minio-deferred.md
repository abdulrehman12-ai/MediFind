# ADR 0005: MinIO deferred

## Status

Accepted for Phase 1A.

## Context

MinIO is unused until prescription upload. The image source was unresolved during planning.

## Decision

Do not run MinIO in Phase 1. Introduce private S3-compatible storage in Phase 4 with prescription upload.

## Consequences

No object-storage ports, volumes, or credentials in Phase 1A.
