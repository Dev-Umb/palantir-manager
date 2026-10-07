## Design
Reuse the existing files field, attachment identity/preview/ownership services and project authorization for `other_attachments`. Store attachment references on projects, not as fabricated contract records. The same original may have authorized bindings to both projects; removing a binding must not delete the other binding or original.

## Capability Mapping
| Existing capability or field | Carrier after change | Evidence |
| --- | --- | --- |
| Genuine unassigned processing letters | Existing `unassigned_processing_letter_attachments` | Existing processing-letter status and preview regression |
| Signed contracts, processing letters and statements | Existing contract attachment fields | Preserved contract008, status and amount regression |
| Misclassified guarantee on project043 | Project043 `other_attachments` with same original identity | Move/readback and original checksum |
| Additional guarantee association requested for project322 | Project322 `other_attachments` referencing same original | Both bindings visible and authorized |
| Project statuses and amount | Existing derivation using genuine contract/letter sources only | Other-only, mixed and zero-remnant cases |
| File removal and access | Existing authorization, audit and attachment service | Shared-binding removal and unauthorized access tests |

## Migration
Read current production records immediately before the targeted transaction. Assert the exact expected original reference `attachments/N3I6zDyf5zGhyp5xtvDfdWkSq0XcH6HUWhsNjCtt.pdf` belongs to project043. Retain a recoverable record snapshot and file identity; remove only that reference from the processing-letter field, and append it idempotently to other attachments on043 and322. Recompute contract status from genuine evidence, not by guessing a business stage. Inspect existing status derivation before deciding any overall-stage recalculation. Preserve financial, collection and notification anchors except a processing-letter-specific marker proven to derive solely from this misclassification. Verify all preserved fields explicitly.

## Alternatives and Risks
Leaving the guarantee in a letter field perpetuates wrong status. Attaching it as a contract implies the wrong category. A new contract type would introduce unnecessary financial semantics. A project other-attachment field is the narrow carrier. Repository checkout differs from deployed contract-maintenance code; identify a suitable current baseline before implementation and preserve dirty work. Inspect web/mobile supported entry points; do not silently downgrade mobile capability.

## Rollback and Evidence
Restore only targeted record fields and code from snapshots if verification fails, preserving subsequent edits through conflict checks. Local tests, quality gate, deployment and online readback are separate evidence. Production mutation is limited to these two projects and the explicitly identified original.
