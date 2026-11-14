## ADDED Requirements

### Requirement: Direct contract attachment upload
The mini program SHALL allow an authorized user to upload contract attachments within a project without invoking AI.

#### Scenario: Append to an existing contract
- **WHEN** a user selects a file and confirms its target contract
- **THEN** the system appends the file, displays the saved attachment and retains existing files, contract fields and other contracts

#### Scenario: Project has no contract
- **WHEN** a user chooses to create a contract and upload its first attachment
- **THEN** the system requests explicit contract details, applies existing evidence validation and retains unknown amounts as blank rather than zero

### Requirement: Authorization and safe upload outcomes
The system MUST enforce existing project scope, contract maintenance authorization and file validation on the server.

#### Scenario: Unauthorized or cross-project upload
- **WHEN** an unauthorized user submits a file or selects a contract outside the specified project
- **THEN** the server rejects the mutation and does not expose or modify another project's attachments

#### Scenario: Failure or session change
- **WHEN** upload fails, its outcome is uncertain or the account changes
- **THEN** the client does not report unverified success or blindly retry, preserves a retryable selection only in the same session and prevents stale responses from affecting the new session

### Requirement: Preserved adjacent capabilities
The mini program SHALL preserve project overview, collections, editing, attachment preview and the existing AI contract intake workflow.

#### Scenario: Direct upload completes
- **WHEN** an authorized upload succeeds
- **THEN** existing attachment previews and project sections remain available and the separate AI intake entry remains unchanged
