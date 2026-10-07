## ADDED Requirements

### Requirement: 项目默认按对账单隐藏顺序排列

系统 MUST 在项目主表没有有效显式排序时，按项目隐藏 `_statement_order` 的数值升序排列，已编序项目 MUST 先于未编序项目。

#### Scenario: 默认打开项目主表

- **WHEN** 用户打开项目主表且没有提交排序字段
- **THEN** 结果 MUST 按隐藏对账单顺序的数值升序返回，空顺序记录置于末尾

#### Scenario: 筛选后保持默认顺序

- **WHEN** 用户应用搜索或字段筛选且排序仍为默认
- **THEN** 系统 MUST 先收窄记录集，再对结果按同一隐藏顺序排列

### Requirement: 隐藏顺序不成为业务字段

`_statement_order` MUST NOT 出现在列表列、详情、编辑表单、筛选字段或显式排序字段中。本变更 MUST NOT 修改前端字段、控件或文案。

#### Scenario: 用户查看排序与筛选控件

- **WHEN** 用户打开项目主表的排序或筛选控件
- **THEN** 现有前端文案和字段选项 MUST 保持不变，且 MUST NOT 看到 `_statement_order` 可选字段

### Requirement: 显式排序和其他对象保持原行为

系统 MUST 在用户选择有效排序字段时使用该字段和方向，并 MUST 保持非项目对象的默认排序。

#### Scenario: 用户显式选择项目名称排序

- **WHEN** 用户选择项目名称升序或降序
- **THEN** 结果 MUST 按项目名称和所选方向排列，而不再使用隐藏顺序作为主排序

#### Scenario: 打开非项目业务表

- **WHEN** 用户打开其他业务表且没有提交显式排序
- **THEN** 该业务表 MUST 继续按既有默认规则排列
