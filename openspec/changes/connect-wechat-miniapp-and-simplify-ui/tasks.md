## Proposal
- [x] Inspect current mini program, platform routes and Flutter authentication.
- [x] Confirm production target and read-only online acceptance.
- [x] Research official mini program UI patterns and map existing capabilities.
- [x] Obtain explicit proposal approval.

## Implementation after approval
- [x] Add isolated JSON session routes with CSRF, regeneration, throttling and backward compatibility tests.
- [x] Implement fixed-origin mini program network/session handling, version recovery and failure states.
- [x] Replace demo project/customer/summary/notification sources with real contracts and pagination.
- [x] Adapt create/edit without dropping unedited fields; test using local fixtures only.
- [x] Redesign four primary pages and details; retain lower frequency tools with truthful integration states.
- [ ] Validate all mapped fields and permission boundaries; remove production access to demo identity switches.
- [x] Run backend focused tests, mini program unit/UI tests, compilation and repository quality gate.
- [ ] Merge isolated backend PR before any deployment; do not archive this change automatically.
- [ ] Verify configured request domain and actual-device compatibility.
- [ ] User signs in with administrator credentials; perform read-only production acceptance.
