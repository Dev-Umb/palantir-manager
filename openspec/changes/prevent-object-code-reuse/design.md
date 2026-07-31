## Context

`CreateObjectRecord` currently queries the maximum code among live records for a daily prefix. That makes deleted codes reusable and allows concurrent transactions to calculate the same next value. Production uses PostgreSQL while local tests use SQLite, so the design must use portable uniqueness plus transactional row locking and verify collision behavior without claiming SQLite proves PostgreSQL locking.

## Goals / Non-Goals

**Goals:**

- Keep daily sequence numbers monotonic after deletion.
- Serialize concurrent allocation per object prefix and date.
- Preserve the existing `{PREFIX}-{YYYYMMDD}-{NNN}` format and daily reset.
- Initialize safely when historical records predate the sequence table.

**Non-Goals:**

- Rewrite old codes, eliminate historical gaps, or make codes a replacement primary key.
- Clean online demonstration data.
- Introduce a database-specific sequence dependency.

## Decisions

### Persistent sequence identity

`code_sequences` will have a unique `(prefix, sequence_date)` identity and a `last_number`. Prefix is used rather than business-object ID so the persisted identity matches the public code namespace and survives metadata record replacement.

Alternative considered: key by `business_object_id`. Rejected because two objects could then accidentally issue codes in the same public prefix namespace.

### Atomic allocation

Allocation remains inside `CreateObjectRecord`'s transaction. It obtains the sequence row with `lockForUpdate`, initializes it from the highest matching historical code when absent, increments `last_number`, and saves before record creation. Initial-row unique contention is retried through the enclosing database transaction.

Alternative considered: continue querying live records and add a unique code index. Rejected because deletion still permits reuse and retries do not retain history.

### Historical bootstrap

The first allocation for a prefix/date reads the maximum numeric suffix from matching existing codes, then persists the next value. No migration backfill rewrites data.

Alternative considered: prepopulate every object/date in the migration. Rejected because migrations should not mix schema and mutable business-data interpretation.

## Risks / Trade-offs

- [Two first writers race to create the sequence row] → Enforce a composite unique index and retry the transaction on integrity/deadlock failure.
- [SQLite does not emulate PostgreSQL row locking] → Prove persistence and uniqueness locally, then keep deployment migration and production concurrency verification as separate evidence.
- [Metadata reuses a prefix across objects] → Sharing one prefix sequence intentionally prevents public-code collisions.
- [Rollback loses future sequence history] → Rollback is only safe before issuing codes with the new allocator; otherwise roll forward rather than dropping the table.

## Migration Plan

1. Create `code_sequences` with a composite unique index.
2. Deploy migration before serving code that uses the model.
3. The first allocation lazily initializes each date/prefix from existing records.
4. Verify deletion followed by creation and concurrent allocation in a dedicated deployment check.
5. Roll back application code and table only if no new codes were issued; otherwise retain the table and roll forward.

## Open Questions

- None. The owner selected persistent scheme A on 2026-07-31.
