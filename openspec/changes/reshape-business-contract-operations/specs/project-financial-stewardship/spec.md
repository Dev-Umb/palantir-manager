## ADDED Requirements

### Requirement: 财务直接维护项目财务字段

财务 MUST 可以在所有项目上修改 `contract_amount`、`occurred_amount`、`paid_amount`、`last_payment_date`、`unpaid_amount`、`reconciled_amount`、`invoiced_amount`、`uninvoiced_amount`、`payment_progress`、`payment_status`。业务员 MUST 只读这些字段；催款次数只能由系统修改。

#### Scenario: 财务更新全部显示值

- **WHEN** 财务为十个字段提交有效值
- **THEN** 系统 MUST 原样保留提交值，不做静默重新计算，并记录财务更新审计

#### Scenario: 财务维护末次回款日期

- **WHEN** 财务修改项目 `last_payment_date`
- **THEN** 系统 MUST 保存实际业务日期并重置回款提醒周期，但 MUST NOT 用系统更新时间覆盖该日期

#### Scenario: 业务员提交财务字段

- **WHEN** 业务员篡改项目请求，夹带财务字段或催款次数变化
- **THEN** 受保护字段 MUST 保持不变，并按现有校验约定拒绝或丢弃越权修改

#### Scenario: 历史财务记录变化

- **WHEN** 财务台账或开票历史存在或发生变化
- **THEN** 历史记录继续保留，但 MUST NOT 覆盖项目财务字段

### Requirement: 合同金额支持受保护的可选同步

项目合同金额为空时 MUST 从第一份关联合同初始化；财务手工维护后 MUST 阻止自动覆盖；财务或管理员 MUST 能主动重新同步。

#### Scenario: 第一份合同关联到空金额项目

- **WHEN** 保存第一份合同，且项目 `contract_amount` 为空
- **THEN** 当前合同合计初始化项目金额，并记录来源和同步时间

#### Scenario: 财务手工修改合同金额

- **WHEN** 财务修改项目 `contract_amount`
- **THEN** 来源改为手工维护，后续合同新增、修改或删除 MUST NOT 自动覆盖

#### Scenario: 有权限用户主动重新同步

- **WHEN** 财务或管理员点击“从合同表重新同步”
- **THEN** 项目 `contract_amount` 替换为当前关联合同总额，并更新来源、时间、操作人和审计记录

#### Scenario: 无权限用户请求重新同步

- **WHEN** 业务员或其他无关账号请求合同金额同步
- **THEN** 系统 MUST 返回无权限，保留原项目金额和来源记录
