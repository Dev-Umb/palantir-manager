## ADDED Requirements

### Requirement: 项目内平铺客户资料

系统 SHALL 在业务员可编辑的项目新建与编辑表单中直接展示客户名称、客户地址、客户等级、客户性质和联系人列表。

#### Scenario: 直接填写新客户

- **WHEN** 业务员填写不存在的客户名称与地址组合
- **THEN** 系统 MUST 在项目保存时新建客户并关联项目

#### Scenario: 选择已有客户

- **WHEN** 业务员从下拉选择已有客户
- **THEN** 系统 MUST 回填该客户的地址、等级、性质和可选联系人

#### Scenario: 不展示客户备注

- **WHEN** 业务员维护项目
- **THEN** 系统 MUST NOT 展示或修改客户备注

### Requirement: 客户组合唯一与覆盖确认

系统 MUST 以规范化后的客户名称与地址组合识别客户，并在覆盖共享主档前取得明确确认。

#### Scenario: 组合匹配

- **WHEN** 提交名称与地址组合已存在
- **THEN** 系统 MUST 复用该客户且 MUST NOT 新建重复客户

#### Scenario: 不同地址

- **WHEN** 名称相同但地址不同且没有显式选择已有客户
- **THEN** 系统 MUST 将其视为不同客户

#### Scenario: 主档冲突

- **WHEN** 匹配客户的地址、等级或性质与提交值不同
- **THEN** 系统 MUST 展示每个冲突字段的当前值与新值，并 MUST NOT 在未确认时保存

#### Scenario: 拒绝覆盖

- **WHEN** 用户在冲突弹窗选择取消
- **THEN** 系统 MUST 保留当前项目表单且 MUST NOT 修改项目或客户

#### Scenario: 确认覆盖

- **WHEN** 用户确认覆盖
- **THEN** 系统 MUST 更新共享客户主档并保存项目

### Requirement: 联系人组合唯一

系统 SHALL 在所属客户内以规范化后的联系人姓名与手机号组合识别联系人。

#### Scenario: 复用联系人

- **WHEN** 同一客户已有相同姓名与手机号组合
- **THEN** 系统 MUST 复用该联系人且 MUST NOT 新建重复记录

#### Scenario: 不同组合

- **WHEN** 姓名或手机号不同
- **THEN** 系统 MUST 保留为不同联系人记录

#### Scenario: 移除项目联系人

- **WHEN** 业务员从项目联系人列表移除已有联系人
- **THEN** 系统 MUST 仅解除当前项目引用且 MUST NOT 删除联系人主档

### Requirement: 一次原子保存

系统 MUST 通过一次项目提交在同一数据库事务内保存项目、客户和联系人。

#### Scenario: 全部成功

- **WHEN** 项目、客户和联系人输入均合法
- **THEN** 系统 MUST 一次保存全部记录与关系

#### Scenario: 任一失败

- **WHEN** 任一客户、联系人、关系或项目验证失败
- **THEN** 系统 MUST 回滚整笔提交且 MUST NOT 留下部分成功记录

#### Scenario: 保存期间并发请求

- **WHEN** 两个请求同时提交相同客户或联系人组合
- **THEN** 系统 MUST 在引用图锁内串行解析并保持组合唯一

### Requirement: 相邻权限与入口保持

系统 SHALL 只替换业务项目内的客户维护入口。

#### Scenario: 业务员编辑

- **WHEN** 业务员编辑自己可更新的项目
- **THEN** 系统 MUST 允许使用平铺客户资料并继续执行客户可见范围

#### Scenario: 财务编辑

- **WHEN** 财务编辑项目金额字段
- **THEN** 系统 MUST NOT 授予客户或联系人写权限，且原财务字段仍可保存

#### Scenario: 旧维护入口

- **WHEN** 用户打开项目新建或编辑表单
- **THEN** 系统 MUST NOT 再显示手动新增客户、维护客户或联系人脱焦保存入口

#### Scenario: 其他模块

- **WHEN** 招投标或其他对象保存客户关系
- **THEN** 系统 MUST 保持现有行为且 MUST NOT 自动应用本项目平铺规则
