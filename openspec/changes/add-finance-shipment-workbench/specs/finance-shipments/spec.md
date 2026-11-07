## ADDED Requirements

### Requirement: Fast project search
业务员的项目列表 MUST 支持项目名称及编号的即时部分匹配，忽略大小写和首尾空格。搜索 MUST 限于当前业务员已有可见项目，不修改数据或汇总口径。
#### Scenario: Search and clear
- **WHEN** 财务输入名称片段或项目编号，随后清空
- **THEN** 列表 MUST 即时显示匹配项目及数量，清空后恢复该业务员全部项目；无匹配 MUST 显示空态和清空入口

### Requirement: Finance hierarchy and access
系统 MUST 提供业务员、项目概况、发货明细三级入口，仅财务/管理员且具备既有项目权限的用户可以访问；项目范围 MUST 复用既有规则。
#### Scenario: Unauthorized access
- **WHEN** 普通业务员或无权限用户访问或保存台账
- **THEN** 系统 MUST 拒绝且不暴露或修改数据

### Requirement: Spreadsheet editing
系统 MUST 支持直接编辑单元格、自动保存、末尾空白行和右键在下方插入明细，不要求点击增加明细按钮。保存 MUST 有校验、失败提示、并发版本保护和服务端确认。
#### Scenario: Edit while saving
- **WHEN** 财务在保存请求进行中继续输入
- **THEN** 系统 MUST 保留新输入并串行保存，不以旧响应覆盖新值
#### Scenario: Stale revision
- **WHEN** 另一页面已更新同一台账
- **THEN** 系统 MUST 返回冲突、保留输入，不静默覆盖

### Requirement: Shipment aggregates
每单 MUST 自动小计有效发货吨数和已知金额。实际发货吨数与最后实际发货日期 MUST 仅在财务台账内统计。保存、编辑、清空和导入 MUST 不更新项目记录，包括 signed_weight、last_shipment_date、时间戳及其他业务字段。件台米 MUST 不计吨。
#### Scenario: Mixed units and drafts
- **WHEN** 台账包含吨、件和未完成行
- **THEN** 系统 MUST 只累计有效吨数，保留非吨明细和草稿；完整业务记录及时间戳保持不变

### Requirement: Audited import preview
系统 MUST 保存来源附件、来源行及预检分类，排除开票/收款/小计，含糊项待订正；确认导入 MUST 幂等且不能由客户端篡改预检结果。
#### Scenario: Repeated historical file
- **WHEN** 同一项目再次确认同一文件
- **THEN** 系统 MUST 不重复添加发货明细或吨数
#### Scenario: Ambiguous record
- **WHEN** 日期、单位或项目关联不足以确认
- **THEN** 系统 MUST 展示待订正原因，不猜测计入项目汇总

### Requirement: Existing project association
线上导入 MUST 仅关联已有项目，不创建或修改业务资料。近似项目名或客户名 MUST 仅作为匹配候选，证据不足时待确认。
#### Scenario: Similar names
- **WHEN** 来源名称与多个已有项目或客户相似
- **THEN** 系统 MUST 保留来源并等待关联确认，不自动创建、合并或修改已有业务记录
