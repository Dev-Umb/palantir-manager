## ADDED Requirements

### Requirement: 客户唯一维护客户性质

系统 SHALL 在客户对象提供可选客户性质下拉，且客户记录是唯一持久化来源。

#### Scenario: 保存合法值

- **WHEN** 用户保存“国央企”或“私企”
- **THEN** 系统 MUST 保存并在客户列表与详情显示

#### Scenario: 拒绝非法值

- **WHEN** 请求提交两个选项之外的非空值
- **THEN** 系统 MUST 拒绝并保持原记录不变

#### Scenario: 历史空值

- **WHEN** 客户未维护客户性质
- **THEN** 系统 MUST 保持空值且 MUST NOT 推断或回填

### Requirement: 项目只读透传客户性质

系统 MUST 在项目列表与详情显示关联客户当前性质，且 MUST NOT 保存项目副本。

#### Scenario: 展示当前值

- **WHEN** 项目关联已维护性质的客户
- **THEN** 项目 MUST 显示客户当前值且 MUST NOT 提供编辑能力

#### Scenario: 客户修改后更新

- **WHEN** 客户性质被修改
- **THEN** 关联项目下次读取 MUST 显示新值且 MUST NOT 需要同步写入

#### Scenario: 项目尝试独立写入

- **WHEN** 项目请求携带 `customer_nature`
- **THEN** 系统 MUST NOT 持久化该值并 MUST 继续以客户当前值响应

#### Scenario: 没有可用值

- **WHEN** 项目无客户、客户断链或客户性质为空
- **THEN** 项目 MUST 显示空值且 MUST NOT 自动分类

### Requirement: 相邻行为保持

系统 SHALL 保持既有客户与项目能力。

#### Scenario: 操作其他字段

- **WHEN** 用户使用客户或项目既有 CRUD
- **THEN** 原字段、关系、权限、路由和相邻数据 MUST 保持可用
