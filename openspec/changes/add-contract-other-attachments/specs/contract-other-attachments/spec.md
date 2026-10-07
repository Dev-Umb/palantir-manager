## ADDED Requirements
### Requirement: Contract other attachments
Contracts SHALL expose an other_attachments files field through existing project contract maintenance and contract detail, with original names, authorized preview/download and explicit token-based binding removal.

#### Scenario: Upload and remove a commitment
- **WHEN** an authorized salesperson uploads or removes an other attachment in a contract detail
- **THEN** the attachment binding and original name are saved without changing existing contract, letter or statement attachments, status or amount

#### Scenario: Shared original binding
- **WHEN** a binding is removed from one contract
- **THEN** the original file and other authorized project bindings remain accessible

#### Scenario: Henan commitment correction
- **WHEN** the identified Henan Hongxing commitment is reclassified
- **THEN** it appears as other attachments of existing contract008 under its Xiangyang project with original identity and Xinbo binding retained
