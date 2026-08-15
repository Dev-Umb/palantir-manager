# Project Number Editing Specification

## ADDED Requirements

### Requirement: 项目金额允许负数

系统 MUST 允许项目主档金额字段保存有限负数，同时 MUST 保留非金额数字的既有范围约束。

#### Scenario: 保存负金额

- **WHEN** 获授权用户把项目合同金额、已发生、已回款、未回款、对账、开票或未开票金额填写为负数
- **THEN** 系统 MUST 接受并保存该负数
- **AND** 小计与经营大盘 MUST 继续按保存值参与汇总

#### Scenario: 非金额数字范围

- **WHEN** 用户把合同重量或累计签收重量填写为负数，或把回款进度填写到 0 至 100 之外
- **THEN** 系统 MUST 按既有规则拒绝保存

#### Scenario: 其他对象金额

- **WHEN** 用户编辑项目主档之外对象的金额字段
- **THEN** 该对象既有最小值、最大值和验证规则 MUST 保持不变

### Requirement: 项目数字统一两位小数

系统 MUST 对项目主档所有 `type=number` 字段的新保存值四舍五入到两位小数，并在项目列表和详情固定展示两位小数。

#### Scenario: 新建或编辑多位小数

- **WHEN** 项目数字输入为正数或负数且超过两位小数
- **THEN** 服务端 MUST 使用四舍五入后的两位小数值持久化
- **AND** 返回记录与后续页面 MUST 展示固定两位小数

#### Scenario: 空数字

- **WHEN** 非必填项目数字为空
- **THEN** 系统 MUST 保持为空
- **AND** MUST NOT 转换为 `0.00`

#### Scenario: 历史项目

- **WHEN** 部署本变更但历史项目未被新建或编辑
- **THEN** 系统 MUST NOT 批量更新其 payload 或时间戳

### Requirement: 编辑保存保留筛选上下文

系统 MUST 在项目筛选结果中保存编辑弹窗后返回原始查询上下文。

#### Scenario: 筛选结果中保存记录

- **GIVEN** 当前 URL 包含搜索、组合筛选、排序、分页和记录编辑参数
- **WHEN** 用户保存项目编辑弹窗
- **THEN** 更新成功后的 URL MUST 保留搜索、筛选、排序和分页参数
- **AND** 筛选控件与列表 MUST 继续展示同一筛选结果

#### Scenario: 列表内联保存

- **WHEN** 用户在筛选结果中直接编辑单元格并自动保存
- **THEN** 系统 MUST 返回 JSON 且 MUST NOT 导航离开当前 URL
