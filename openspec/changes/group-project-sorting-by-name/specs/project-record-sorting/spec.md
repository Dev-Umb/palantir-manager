# Project Record Sorting Specification

## ADDED Requirements

### Requirement: 项目记录按名称分组排序

系统 MUST 在项目主档中以完整项目名称作为分组排序键，使同名记录连续，同时保留用户选择字段在同名组内的排序方向。

#### Scenario: 默认项目排序

- **WHEN** 用户未选择排序字段进入项目主档
- **THEN** 系统 MUST 按项目名称升序返回记录
- **AND** 同名记录 MUST 使用稳定的记录 ID 顺序

#### Scenario: 手动选择其他字段

- **GIVEN** 多条项目记录存在相同项目名称
- **WHEN** 用户选择项目名称以外的字段及方向
- **THEN** 系统 MUST 先按项目名称升序排列
- **AND** MUST 在每个同名项目组内按所选字段及方向排列
- **AND** 同名项目 MUST 保持连续

#### Scenario: 手动选择项目名称

- **WHEN** 用户选择项目名称升序或降序
- **THEN** 系统 MUST 按用户选择的方向排列项目名称
- **AND** 同名记录 MUST 保持连续

#### Scenario: 其他业务对象

- **WHEN** 用户查看或排序项目主档之外的业务对象
- **THEN** 系统 MUST 保持该对象既有默认和手动排序规则

### Requirement: 项目默认排序文案

系统 MUST 在项目主档排序控件中明确显示默认按项目名称排序。

#### Scenario: 查看排序控件

- **WHEN** 用户打开项目主档
- **THEN** 空排序选项 MUST 显示“默认（项目名称）”
- **WHEN** 用户打开其他业务对象
- **THEN** 空排序选项 MUST 继续显示“默认（最近更新）”
