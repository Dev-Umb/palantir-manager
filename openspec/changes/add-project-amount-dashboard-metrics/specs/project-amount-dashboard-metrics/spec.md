## ADDED Requirements

### Requirement: 公司金额总计来自项目主档

系统 SHALL 基于当前用户可见项目主档中的三个金额字段展示公司总计。

#### Scenario: 分别汇总三个项目金额字段

- **WHEN** 获授权用户打开经营大盘
- **THEN** 系统 MUST 分别合计可见项目的 `occurred_amount`、`paid_amount` 和 `unpaid_amount`
- **AND** 每条项目记录 MUST 独立计入，不得按客户、项目名称或其他字段去重
- **AND** 系统 MUST NOT 从财务台账、合同或其他对象补算金额

#### Scenario: 金额为空或格式无效

- **WHEN** 某项目的某个金额为空、非数字或非有限数字
- **THEN** 该值 MUST NOT 进入该金额字段的总计或有效记录数
- **AND** 同项目其他有效金额字段 MUST 继续独立计入
- **AND** 数值 0 MUST 作为有效记录计入覆盖数量

#### Scenario: 金额为负数

- **WHEN** 项目主档中的金额是有限负数
- **THEN** 该金额 MUST 按数据库原值进入对应字段总计和有效记录数
- **AND** 系统 MUST NOT 擅自改为 0、绝对值或空值

#### Scenario: 没有有效金额

- **WHEN** 某金额字段在可见项目中没有任何有效值
- **THEN** 页面 MUST 显示“—”与覆盖数量
- **AND** MUST NOT 将缺失数据显示为 0

### Requirement: 按现有业务员账号汇总项目金额

系统 SHALL 按项目主档的负责业务员账号展示三项金额合计。

#### Scenario: 业务员拥有项目记录

- **WHEN** 现有账号被至少一条可见项目的 `business_owner_user_id` 引用
- **THEN** 业务员汇总 MUST 显示该账号姓名、项目记录数和三个独立金额总计
- **AND** 相同客户或项目名称的多条记录 MUST 全部计入

#### Scenario: 业务员没有项目记录

- **WHEN** 现有账号没有关联任何可见项目
- **THEN** 该账号 MUST NOT 出现在业务员金额汇总中

#### Scenario: 项目没有有效负责账号

- **WHEN** 项目负责人为空、格式无效或引用不存在的账号
- **THEN** 项目金额 MUST 继续进入公司总计
- **AND** 项目 MUST NOT 被虚构归属给任何业务员
- **AND** 页面 MUST 显示此类项目记录数量

### Requirement: 金额汇总按既有权限收口

系统 MUST 在服务端使用既有项目权限与可见范围过滤后再聚合。

#### Scenario: 管理员查看公司范围

- **WHEN** 管理员打开经营大盘
- **THEN** 公司总计和业务员汇总 MUST 基于公司全量项目记录

#### Scenario: 非管理员查看受限范围

- **WHEN** 非管理员打开经营大盘
- **THEN** 金额汇总 MUST 只包含 `ProjectVisibility` 允许其查看的项目
- **AND** MUST NOT 向前端返回不可见项目推导出的金额或业务员行

#### Scenario: 用户没有项目查看权限

- **WHEN** 用户无 `object.project.view` 权限
- **THEN** 服务端 MUST 省略项目金额汇总面板
- **AND** 现有经营大盘安全空态与其他获授权面板 MUST 保持不变

### Requirement: 金额汇总自动刷新且保持只读

经营大盘 SHALL 定期从数据库刷新金额汇总，不得产生持久化副作用。

#### Scenario: 页面保持打开

- **WHEN** 经营大盘处于前台且距离上次刷新达到 15 秒
- **THEN** 页面 MUST 通过 Inertia 部分请求重新读取驾驶舱数据
- **AND** 刷新 MUST 更新项目金额总计、业务员汇总和统计时间

#### Scenario: 自动刷新不改变业务数据

- **WHEN** 初次请求或自动刷新经营大盘
- **THEN** 系统 MUST NOT 新增、修改或删除项目、客户、账号、通知、审计日志或其他业务记录
- **AND** MUST NOT 更新任何业务记录的时间戳

### Requirement: 现有驾驶舱能力保持不变

新增金额汇总 SHALL 作为现有经营大盘的增量区块，不得替换已有能力。

#### Scenario: 渲染新增金额汇总

- **WHEN** 用户有权查看项目金额汇总
- **THEN** 页面 MUST 继续保留既有 KPI、风险提醒、经营图表、项目推进和最近项目
- **AND** 既有路由、权限范围和下钻入口 MUST 保持不变
