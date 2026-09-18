## ADDED Requirements

### Requirement: Dedicated operator isolation
The explicit timebook_operator role MUST limit effective permissions to the seven timebook permissions and ai.harness.view, even when combined with another role. Its authenticated routes MUST allow only timebook, AI conversation/query, own password settings and logout. Other accounts MUST retain existing behavior.
#### Scenario: Dedicated account navigation and direct access
- **WHEN** a dedicated operator signs in
- **THEN** only timebook and AI appear in business navigation, own password change remains accessible, and dashboard, notifications, objects, procurement and contract intake requests are denied
#### Scenario: AI data boundary
- **WHEN** a dedicated operator asks AI for other business data or receives another role
- **THEN** non-timebook effective permissions remain absent and business query tools return no business data

### Requirement: AI 历史结果权限一致
工日簿查询 MUST 将来源与查询指纹保存在 AI Run，并按工日簿专属权限校验历史读取和后续会话，不得套用通用业务对象授权。其他对象的历史授权规则 MUST 保持不变。

#### Scenario: 查询后撤销权限
- **WHEN** 用户成功查询工日簿后失去工日簿查看或 AI 查询权限
- **THEN** 系统 MUST 拒绝读取该历史结果或沿用该会话继续查询

### Requirement: Independent module access
The platform SHALL provide an independent timebook module with action-specific RBAC without granting existing business roles implicit access or changing other business modules.
#### Scenario: Missing permission
- **WHEN** a user without timebook.view requests records, suggestions, history or export
- **THEN** access is denied without disclosing ledger data
#### Scenario: Read-only member
- **WHEN** a user only has timebook.view
- **THEN** querying is allowed and all mutation/export/audit actions require their separate grants

### Requirement: Preserve ledger rules
The module MUST retain free normalized names, integer half-day and minute storage, one active entry per person/day, version conflicts, transactional audit snapshots and soft-delete restoration.
#### Scenario: Duplicate and stale writes
- **WHEN** a duplicate person/day is submitted or a stale version is edited
- **THEN** a conflict is returned without overwriting data or leaving an extra worker
#### Scenario: Restoration collision
- **WHEN** a deleted record is restored after another active record occupies its person/day
- **THEN** restoration fails without altering the later record

### Requirement: Consistent queries and export
The module SHALL apply inclusive date ranges and normalized literal name substring or exact worker filtering consistently to records, all-result summaries, totals and three-sheet XLSX exports.
#### Scenario: Pagination and overtime-only records
- **WHEN** matching entries exceed one page and include zero-day overtime
- **THEN** totals and exports include every active matching record and count the overtime-only worker
#### Scenario: Text formula protection
- **WHEN** a name, project or note starts with a formula character
- **THEN** XLSX writes it as literal text

### Requirement: Permission-bound AI read access
AI SHALL query the shared module query service through a read-only tool requiring current timebook.view, timebook.ai.query and ai.harness.view grants.
#### Scenario: Revoked access
- **WHEN** a previously authorized user's grant is revoked before tool execution
- **THEN** the tool returns denial without ledger rows
#### Scenario: Accurate aggregate
- **WHEN** AI asks for a period total with a limited detail page
- **THEN** the result distinguishes whole-result aggregates from limited detail and states date range, units and read time

### Requirement: Preserve other modules
The change MUST preserve existing business object data, permissions, navigation entries and AI mutation contracts.
#### Scenario: Module permission installation
- **WHEN** module permissions are installed repeatedly
- **THEN** existing custom role assignments and business records remain unchanged
