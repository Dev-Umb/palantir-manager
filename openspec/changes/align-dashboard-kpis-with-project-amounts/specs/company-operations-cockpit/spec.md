## MODIFIED Requirements

### Requirement: 累计实际发生金额采用已确认产值口径

系统 SHALL 将当前用户可见项目主档的 `occurred_amount` 合计展示为“累计实际发生金额”，并以辅助文本标明“已发生金额总计”。

#### Scenario: 项目主档与财务台账金额不同

- **GIVEN** 项目主档与财务台账的已发生金额不同
- **WHEN** 用户查看经营大盘
- **THEN** 系统 MUST 合计可见项目主档中的有效 `occurred_amount`
- **AND** MUST 与项目主档金额区块中的“已发生金额总计”保持相同数值与覆盖范围
- **AND** MUST NOT 使用财务台账、合同额、生产吨位或发货吨位补算或覆盖

#### Scenario: 产值字段缺失

- **WHEN** 可见项目没有任何有效 `occurred_amount`
- **THEN** 系统 MUST 显示“—”并保留项目金额覆盖信息
- **AND** MUST NOT 显示虚假的零金额

#### Scenario: 不生成产值历史趋势

- **WHEN** 系统仅持有当前累计 `occurred_amount`
- **THEN** 驾驶舱 MUST NOT 使用 `updated_at` 或其他时间字段将累计值伪装为月度新增产值、同比或环比

### Requirement: 回款指标采用金额加权口径

系统 SHALL 继续使用财务台账计算加权回款率，同时将当前用户可见项目主档的 `unpaid_amount` 总计展示为“当前欠款”。

#### Scenario: 计算公司回款率

- **WHEN** 用户有权查看多条财务台账
- **THEN** 每条台账基数 MUST 为 `occurred_amount > 0 ? occurred_amount : contract_amount`
- **AND** 公司回款率 MUST 为 `Σmin(paid_amount, base) / Σbase`

#### Scenario: 计算当前欠款

- **WHEN** 用户有权查看一组项目主档
- **THEN** 当前欠款 MUST 为其中有效 `unpaid_amount` 的总计
- **AND** MUST 与项目主档金额区块中的“未回款金额总计”保持相同数值与覆盖范围
- **AND** MUST NOT 从财务台账的已发生金额与已回款金额反推

#### Scenario: 回款率分母为零

- **WHEN** 有效台账的合计基数为零
- **THEN** 回款率 MUST 显示“—”与“分母为 0，暂不可计算”
- **AND** MUST NOT 显示 `0%` 或 `100%`

#### Scenario: 不生成回款历史趋势

- **WHEN** 系统只有累计 `paid_amount` 与 `last_payment_date`
- **THEN** 驾驶舱 MUST NOT 将当前累计值伪装为历史月度回款趋势

## ADDED Requirements

### Requirement: 待跟进项目数使用正数未回款金额

系统 SHALL 将可见项目中 `unpaid_amount` 为有效有限数字且大于 0 的项目计为待跟进项目，并随驾驶舱轮询实时重算。

#### Scenario: 未回款金额包含空值与非正数

- **GIVEN** 可见项目同时存在正数、0、负数、空值和非数字未回款金额
- **WHEN** 系统生成“当前欠款”提示
- **THEN** MUST 仅统计正数未回款金额的项目
- **AND** 空值、非数字、0 与负数 MUST NOT 增加待跟进项目数

#### Scenario: 用户只能查看自己的项目

- **GIVEN** 业务员只能查看自己负责的项目
- **WHEN** 用户查看经营大盘
- **THEN** 两个项目金额 KPI 与待跟进项目数 MUST 只使用该用户可见项目
- **AND** MUST NOT 包含其他业务员的项目

#### Scenario: 用户没有项目查看权限

- **GIVEN** 用户没有项目查看权限
- **WHEN** 用户查看经营大盘
- **THEN** 系统 MUST NOT 返回“累计实际发生金额”或“当前欠款”项目 KPI
