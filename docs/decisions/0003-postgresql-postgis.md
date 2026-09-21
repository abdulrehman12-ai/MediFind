# ADR 0003: PostgreSQL with PostGIS

## Status

Accepted for Phase 1A.

## Context

Later pharmacy distance queries need geospatial types. Development and tests must not share data or credentials.

## Decision

Use PostgreSQL 16 with PostGIS 3.5. Create `medifind`/`medifind_dev` and `medifind_testing`/`medifind_test` with CONNECT revoked for the opposite role and for PUBLIC. Enable PostGIS in both databases.

## Consequences

Tests cannot use SQLite. Destructive commands must prove they target the testing database and role.
