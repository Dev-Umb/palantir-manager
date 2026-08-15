# Project Customer Contact Saving Specification

## ADDED Requirements

### Requirement: 保存客户同时保存当前联系人

项目内客户维护 MUST 在一次保存中原子持久化客户资料与当前非空联系人草稿。

#### Scenario: 新客户与新联系人组合创建

- **GIVEN** 用户填写了新客户资料和联系人姓名
- **WHEN** 用户点击“保存客户”
- **THEN** 系统 MUST 在同一事务中创建客户和联系人
- **AND** 联系人 MUST 关联该客户
- **AND** 两条记录 MUST 被项目表单选中

#### Scenario: 组合保存失败

- **WHEN** 客户或联系人任一数据验证或写入失败
- **THEN** 客户与联系人组合写入 MUST 全部回滚
- **AND** 前端 MUST 保留两者草稿并显示失败原因

#### Scenario: 联系人姓名为空

- **GIVEN** 当前联系人姓名去除首尾空白后为空
- **WHEN** 用户保存客户
- **THEN** 系统 MUST 只保存客户资料
- **AND** MUST NOT 创建空联系人

### Requirement: 联系人脱焦自动保存

已有客户的当前联系人姓名或电话字段脱焦后，系统 MUST 自动保存客户资料和当前联系人。

#### Scenario: 编辑已有联系人后脱焦

- **GIVEN** 用户正在维护已有客户的已有联系人
- **WHEN** 用户修改姓名或电话并使该输入框脱焦
- **THEN** 系统 MUST 自动更新该联系人
- **AND** MUST 显示保存中及保存成功状态

#### Scenario: 新联系人脱焦

- **GIVEN** 已有客户下的当前联系人没有记录 ID 且姓名非空
- **WHEN** 联系人姓名或电话字段脱焦
- **THEN** 系统 MUST 自动创建该联系人
- **AND** MUST 把新联系人加入当前项目选择

#### Scenario: 保存期间继续修改

- **GIVEN** 一次自动保存请求仍在进行
- **WHEN** 用户继续修改联系人并再次脱焦
- **THEN** 系统 MUST 在当前请求完成后保存最新草稿
- **AND** MUST NOT 并行提交或静默丢弃最新修改

### Requirement: 保留相邻保存能力

组合保存和自动保存 MUST 保留既有权限、独立联系人接口与项目保存行为。

#### Scenario: 不带联系人保存客户

- **WHEN** 既有调用只提交客户资料而不提交 `contact`
- **THEN** 系统 MUST 按既有行为保存客户
- **AND** MUST NOT 修改任何联系人

#### Scenario: 项目保存互斥

- **WHEN** 客户或联系人组合保存尚未完成
- **THEN** 项目主表保存 MUST 保持禁用
- **AND** 组合保存完成或失败后 MUST 恢复可用
