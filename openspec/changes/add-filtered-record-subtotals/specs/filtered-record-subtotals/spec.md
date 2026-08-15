# Filtered Record Subtotals Specification

## ADDED Requirements

### Requirement: 最后一页展示筛选结果小计

系统 MUST 在业务资料筛选结果最后一页的所有真实记录之后展示一条只读“小计”行，且 MUST 保留真实最后一条记录。

#### Scenario: 跨分页汇总筛选结果

- **GIVEN** 当前搜索和筛选条件命中多页记录
- **WHEN** 用户打开筛选结果最后一页
- **THEN** 系统 MUST 对全部命中记录求和，而不是只对当前页记录求和
- **AND** 小计行 MUST 位于真实记录之后

#### Scenario: 非最后一页不展示小计

- **GIVEN** 当前筛选结果存在多页
- **WHEN** 用户打开非最后一页
- **THEN** 系统 MUST 不展示小计行

#### Scenario: 空结果或无数字字段

- **GIVEN** 当前筛选没有命中记录，或当前可见字段没有 `number` 类型字段
- **WHEN** 系统渲染业务资料表
- **THEN** 系统 MUST 不展示小计行

### Requirement: 小计复用权限与筛选范围

系统 MUST 使用当前用户已经授权、搜索并筛选后的记录范围计算小计，且 MUST NOT 读取或泄露用户不可见记录的金额。

#### Scenario: 业务员只汇总可见项目

- **GIVEN** 业务员只能查看本人负责或被知会的项目
- **WHEN** 业务员打开项目列表最后一页
- **THEN** 小计 MUST 只包含其可见项目
- **AND** 公司其他项目 MUST NOT 计入

#### Scenario: 修改筛选条件

- **WHEN** 用户应用另一组搜索或高级筛选条件
- **THEN** 小计 MUST 随新的命中范围重新计算

### Requirement: 所有数字字段按存储值求和

系统 MUST 对当前可见字段中 `type=number` 的普通字段和明细字段逐值求和。

#### Scenario: 普通数字字段

- **GIVEN** 多条匹配记录在普通数字字段中包含有效数值、零值、负数、空值和非数字文本
- **WHEN** 系统计算小计
- **THEN** 系统 MUST 对有效有限数值、零值和负数按原值求和
- **AND** 空值、非数字文本和非有限数值 MUST 被忽略

#### Scenario: 明细数字字段

- **GIVEN** 匹配记录包含多个 `payload.items` 明细，且明细字段类型为 `number`
- **WHEN** 系统计算小计
- **THEN** 系统 MUST 对所有匹配记录的每个明细数值求和
- **AND** MUST NOT 因表格中的跨行展示而重复计算普通字段

### Requirement: 小计不具备记录操作能力

小计行 MUST 是仅用于展示的虚拟行，MUST NOT 改变分页总数、真实记录集合或导出结果。

#### Scenario: 尝试操作小计行

- **WHEN** 用户查看或双击小计行
- **THEN** 小计行 MUST 不进入编辑状态
- **AND** MUST 不显示查看、编辑或删除操作
