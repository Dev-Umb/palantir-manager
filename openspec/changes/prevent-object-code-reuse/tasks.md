## 1. Persistent sequence

- [x] 1.1 Generate a reversible `code_sequences` migration with a composite unique identity.
- [x] 1.2 Add a convention-aligned `CodeSequence` model.
- [x] 1.3 Allocate codes atomically within record creation, bootstrapping from existing historical codes.
- [x] 1.4 Reuse the allocator in other internal record creators instead of retaining duplicate max-live-record logic.

## 2. Regression

- [x] 2.1 Test initial historical bootstrap and the preserved code format.
- [x] 2.2 Test that deletion of the latest record does not permit reuse.
- [x] 2.3 Test distinct allocation under the strongest portable local contention setup and document the PostgreSQL evidence boundary.
- [x] 2.4 Test an adjacent derived-record creation workflow remains unchanged.

## 3. Verification

- [x] 3.1 Rehearse migrate, rollback, and migrate for the new schema.
- [x] 3.2 Run focused PHPUnit coverage and Pint.
- [x] 3.3 Run strict OpenSpec validation and the full local quality gate.
- [x] 3.4 Keep deployment migration and online concurrency verification explicitly unclaimed until separately executed.
