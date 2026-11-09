## Proposal
- [x] Inspect current login, mini program pages, reimbursement contracts and authorization.
- [x] Confirm scope is existing reimbursement approval only.
- [x] Obtain explicit proposal approval before implementation.

## Implementation after approval
- [x] Review all affected callers and activate relevant skills; consult version-specific documentation before code changes.
- [x] Add server-authorized current-decision inbox filtering without changing existing review queries; verify counts before pagination.
- [x] Add administrator workbench entry and reuse reimbursement details for approval confirmation and required-reason rejection.
- [x] Handle duplicate clicks, expired nodes, validation, uncertain responses, account switching and refreshed roles.
- [x] Preserve ordinary account features, attachments, histories, PC non-admin assigned approval and Android behavior.

## Verification and release
- [x] Add focused backend tests for current-node inbox, unauthorized actor, pagination, any/all voting, stale/repeated decisions and preserved query contracts.
- [x] Add mini program tests for entry visibility, ordinary retained features, approval/rejection, empty/error state, duplicate clicks and session isolation.
- [x] Run affected tests and composer openspec:validate; run composer quality:gate before implementation delivery.
- [x] Compile modified workbench, inbox and detail WXML with WeChat developer tools.
- [ ] Review isolated changes, merge PR before deployment, and track server deployment and mini program upload separately.
- [ ] Verify real-device attachment access and approval using explicitly authorized acceptance records; do not treat detail GET as side-effect-free.
