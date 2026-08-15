# Record Display Controls Specification

## ADDED Requirements

### Requirement: 小计金额清晰完整展示

系统 MUST 以千分位和固定两位小数展示小计数字，并为大金额提供足够的列宽和垂直空间。

#### Scenario: 小计达到十亿级

- **GIVEN** 某数字字段的小计达到十亿或以上
- **WHEN** 用户查看筛选结果最后一页
- **THEN** 完整数值 MUST 可见且 MUST 固定保留两位小数
- **AND** 数值 MUST 垂直居中且 MUST NOT 被横向滚动条遮挡

#### Scenario: 普通列保持可缩窄

- **WHEN** 没有大额小计或用户查看非数字列
- **THEN** 既有普通列最小宽度和横向滚动能力 MUST 保持不变

### Requirement: 每页条数支持十档选择

系统 MUST 提供 10 至 100、步长 10 的每页条数选项。

#### Scenario: 选择每页条数

- **WHEN** 用户打开每页条数选择器
- **THEN** MUST 显示 10、20、30、40、50、60、70、80、90、100 条
- **AND** 提交后分页总数和当前页数据 MUST 使用所选条数重新计算

#### Scenario: 查询参数超出范围

- **WHEN** 请求中的 `per_page` 小于 10 或大于 100
- **THEN** 系统 MUST 分别收口为 10 或 100
- **AND** 无效参数 MUST 回退为 50

### Requirement: 经营大盘金额统一使用万元

经营大盘 MUST 仅在展示层把金额从元换算为万元并固定保留两位小数。

#### Scenario: 展示有效金额

- **GIVEN** 驾驶舱接口返回有限金额元值
- **WHEN** 页面展示 KPI、现金转化、招投标预算、公司项目总计或业务员项目总计
- **THEN** 数值 MUST 除以 10,000 并固定保留两位小数
- **AND** 页面 MUST 明确展示“万元”单位

#### Scenario: 保持非金额与数据源不变

- **WHEN** 页面完成换算展示
- **THEN** 数据库存储值、聚合接口元值、比例、数量、吨位和日期 MUST 保持不变
- **AND** 无有效金额 MUST 继续显示“—”
