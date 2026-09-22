# Internal bigint ids and public UUIDs

`users.id` and `user_addresses.id` remain internal bigints for foreign keys. API resources expose `public_id` as `id`. Phase 1A's users migration is not rewritten; Phase 1B adds the public identifier in a forward migration and backfills existing rows.
