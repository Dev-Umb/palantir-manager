## ADDED Requirements

### Requirement: 当前业务名称同步
系统 MUST 仅在当前 BusinessWorkspace 保留对象内同步项目、客户及联系人关联名称。

#### Scenario: 项目改名
- **WHEN** 项目主档名称更新
- **THEN** 合同项目名称、招投标流转项目名称 MUST 更新，合同派生项目编号 MUST 一致
- **THEN** 项目 ID、金额、附件、合同状态、人员归属及其他项目 MUST 保持不变

#### Scenario: 客户和联系人改名
- **WHEN** 客户或联系人主档名称更新
- **THEN** 当前项目、合同、招投标及联系人中的对应关联名称 MUST 更新
- **THEN** 客户绑定、联系人多选 ID、电话显示及既有实时 lookup MUST 保持

### Requirement: 废弃模块和历史归档保留
系统 MUST 保留 BusinessWorkspace 未保留的旧模块及独立历史归档，不对其执行名称同步或旧值修复。

#### Scenario: 名称更新触及旧记录
- **WHEN** 当前项目、客户或联系人改名
- **THEN** 废弃模块记录、报价归档、审计历史和证据原文 MUST 保持原值

### Requirement: 旧值修复与原子性
系统 MUST 提供当前业务关联旧值的只读预览、显式幂等修复及事务回滚。

#### Scenario: 预览和执行
- **WHEN** 默认预览或显式执行修复
- **THEN** 默认 MUST 不写入，执行 MUST 只修复保留对象，重复执行 MUST 无新变更

#### Scenario: 无效关联及失败
- **WHEN** 目标无效或同步写入失败
- **THEN** 无效关联 MUST 保留线索并报告；写入失败 MUST 回滚源记录和关联修改
