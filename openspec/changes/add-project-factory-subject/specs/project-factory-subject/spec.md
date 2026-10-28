## ADDED Requirements

### Requirement: Optional project factory subject
The project SHALL provide a factory_subject field labelled 工厂主体 with values 江苏晋武, 文水鑫源昌, 山西弘创重工有限公司, 山西汾阳丰源网架钢结构有限公司 , 文水县恒鑫达贸易有限公司, 文水县鑫源晟商贸有限公司, 恒鑫盛, 鑫辉, 精致诚 and 恒泰机械, and SHALL allow an empty value.

#### Scenario: Save without a known factory
- **WHEN** a user saves a project with no contract or an unknown factory and leaves factory_subject empty
- **THEN** the project saves under existing permissions without requiring a factory or contract

#### Scenario: Maintain a known factory
- **WHEN** an authorized user selects a factory or clears an existing selection
- **THEN** the value is persisted and shown in project detail, editing and the complete project table using existing field facilities

#### Scenario: Reject unsupported factory values
- **WHEN** a user submits a nonempty factory_subject outside the explicitly supported values
- **THEN** validation rejects the value without modifying the project

### Requirement: Preserve adjacent project information
Adding or maintaining factory_subject MUST preserve existing remarks, contracts, attachments, amounts, dates, relationships and permissions.

#### Scenario: Backfill confirmed recognition
- **WHEN** a project's original attachment evidence confirms a supported subject, without conflicting supplier evidence
- **THEN** only factory_subject is backfilled with backup and audit evidence
- **AND** all adjacent project data and contract records remain unchanged

#### Scenario: Leave unclear records empty
- **WHEN** the project has no original attachment or the supplier cannot be confirmed from the original, or different contract suppliers conflict
- **THEN** its factory_subject remains empty

#### Scenario: Preserve legacy factory values
- **WHEN** the recognized supplier list is extended
- **THEN** existing 江苏晋武 and 山西鑫源昌 values, filtering, editing and factory map behavior remain valid
- **AND** newly supported legal names do not imply a physical factory location

#### Scenario: Correct a source-proven value
- **WHEN** original supplier clauses clearly identify a supported subject and the current project value differs
- **THEN** the user-authorized correction changes only factory_subject with before/after backup and original file hash, page and quote audit evidence
- **AND** concurrent payload changes abort the update

### Requirement: User-confirmed spreadsheet backfill
A returned confirmation workbook MAY provide explicit project subject evidence without uploaded contracts. The update MUST match stable project codes, preserve user-entered distinct names and abbreviations, and change only factory_subject after backup and concurrency validation.

#### Scenario: Confirmed alias
- **WHEN** the user confirms 文水鑫源昌 and 山西鑫源昌 refer to the same entity and supplies 文水县鑫源昌钢结构有限公司
- **THEN** the stored project subject remains 山西鑫源昌 with source worksheet row and original value audited
- **AND** 文水县鑫源晟商贸有限公司 remains a distinct subject

#### Scenario: Distinct supplied names
- **WHEN** the user supplies 恒鑫盛, 鑫辉, 精致诚, 恒泰机械 or 山西弘创重工科技有限公司
- **THEN** the explicit value is retained without inventing full legal names or merging it with a similar existing name
- **AND** missing contracts, amounts, dates, remarks, permissions and factory locations remain unchanged

### Requirement: Explicitly confirmed subject alias merge
User confirmation supersedes the earlier distinct-name treatment of 山西弘创重工科技有限公司. It SHALL be merged into 山西弘创重工有限公司.

#### Scenario: Merge confirmed alias
- **WHEN** the user confirms the duplicate subject names belong to one company
- **THEN** projects using 山西弘创重工科技有限公司 are updated to 山西弘创重工有限公司 in the same transaction as removing the obsolete select option
- **AND** the merge records audit evidence and preserves all other payload fields and subjects
- **AND** running the merge again does not add audits or change unrelated records

#### Scenario: User-requested display rename
- **WHEN** the user requests 山西鑫源昌 be renamed 文水鑫源昌
- **THEN** all project values and the select option are renamed to 文水鑫源昌 atomically
- **AND** both historical and current values retain the same Wenshui factory map behavior
- **AND** company legal names in documents, project remarks and all adjacent data remain unchanged

#### Scenario: Simplify subject display names
- **WHEN** the user requests removing company-type suffixes
- **THEN** existing subject option and project values lose 有限公司 or 有限责任公司 suffixes
- **AND** 弘创 aliases converge on 山西弘创重工 while 文水鑫源昌 and 江苏晋武 remain short labels
- **AND** original documents, filenames, legal-name evidence and other project fields are preserved

#### Scenario: Explicit individual subject correction
- **WHEN** the user assigns XYC-20260815-160 to 恒鑫昌
- **THEN** 恒鑫昌 is supported as a distinct selectable subject and only that project subject is corrected with backup, audit and concurrency checks
- **AND** 恒鑫盛, 恒鑫达 and all other project fields remain unchanged
