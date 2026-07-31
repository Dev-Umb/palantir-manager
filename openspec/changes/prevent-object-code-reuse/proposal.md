## Why

Daily object codes are derived from currently existing records, so deleting the highest record allows its business code to be reused. Persisting sequence state is required to keep issued codes historically unique even when records are deleted.

## What Changes

- Add a `code_sequences` table keyed by business-object prefix and local sequence date.
- Allocate the next number atomically inside the existing record-creation transaction.
- Seed a missing sequence from the highest historical record number for that prefix and date, then persist all future advances independently of record deletion.
- Preserve all existing code formats, object prefixes, and automatic record creation behavior.

## Capabilities

### New Capabilities

- `persistent-object-code-sequences`: Covers monotonic, deletion-resistant, transaction-safe daily business-code allocation.

### Modified Capabilities

- None.

## Impact

- Adds a reversible database migration and a `CodeSequence` model.
- Changes `CreateObjectRecord::nextCode()` and any internal creator that maintains a separate copy of the same code-allocation behavior.
- Adds PHPUnit persistence, deletion, and contention-oriented regression coverage.
- Does not rewrite historical codes, fill gaps, clean records, or change public routes and code formats.
