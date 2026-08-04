## ADDED Requirements

### Requirement: 驾驶舱只读聚合现有数据

系统 SHALL 只读聚合现有业务数据，不得新增或修改任何持久化业务事实。

#### Scenario: 打开驾驶舱不改变业务数据

- **WHEN** 获授权用户请求现有经营大盘
- **THEN** 系统 MUST 返回其可见范围内的聚合结果
- **AND** `business_objects`、`object_records`、角色权限、通知、审计日志及其业务时间戳 MUST 保持不变

#### Scenario: 不引入持久化聚合

- **WHEN** 驾驶舱实现完成
- **THEN** 变更 MUST NOT 包含 migration、数据表或字段变更、业务对象定义变更、汇总表、物化视图、统计快照、持久化筛选条件或后台写入任务

### Requirement: 权限感知的服务端聚合

系统 MUST 在服务端按来源对象权限和既有数据范围过滤后再聚合，不得通过前端隐藏保护敏感数据。

#### Scenario: 管理员查看公司全量

- **WHEN** `admin` 打开驾驶舱
- **THEN** 每个其有权查看的面板 MUST 基于公司全量现有记录计算
- **AND** 页面 MUST 标明“公司全量”

#### Scenario: 非管理员查看受限数据

- **WHEN** 非管理员拥有 `dashboard.view` 及部分对象查看权限
- **THEN** 系统 MUST 先应用对应对象权限与既有项目可见范围，再计算聚合
- **AND** 页面 MUST 标明“我的可见范围”
- **AND** 未授权面板及其原始数值 MUST NOT 出现在服务端响应中

#### Scenario: 用户仅有大盘权限

- **WHEN** 用户只有 `dashboard.view` 而无任何来源对象查看权限
- **THEN** 系统 MUST 返回安全空状态
- **AND** MUST NOT 返回对象数量、金额、状态分布或其他可推导的业务数据

### Requirement: 累计实际发生金额采用已确认产值口径

系统 SHALL 将可见财务台账的 `occurred_amount` 合计展示为“累计实际发生金额”，并以辅助文本标明这是本期确认的产值口径。

#### Scenario: 计算累计实际发生金额

- **WHEN** 用户有权查看一组财务台账
- **THEN** 系统 MUST 合计其中有效的 `occurred_amount`
- **AND** MUST 显示有效记录数与总记录数
- **AND** MUST NOT 将合同额、生产吨位或发货吨位计入该金额

#### Scenario: 产值字段缺失

- **WHEN** 没有任何有效 `occurred_amount`
- **THEN** 系统 MUST 显示“—”和“暂无可计算数据”
- **AND** MUST NOT 显示虚假的零金额

#### Scenario: 不生成产值历史趋势

- **WHEN** 系统仅持有当前累计 `occurred_amount`
- **THEN** 驾驶舱 MUST NOT 使用 `updated_at` 或 `occurred_amount_updated_at` 将累计值伪装为月度新增产值、同比或环比

### Requirement: 回款指标采用金额加权口径

系统 SHALL 使用现有财务同步口径计算回款率和欠款，不得平均各项目百分比。

#### Scenario: 计算公司回款率

- **WHEN** 用户有权查看多条财务台账
- **THEN** 每项目基数 MUST 为 `occurred_amount > 0 ? occurred_amount : contract_amount`
- **AND** 公司回款率 MUST 为 `Σmin(paid_amount, base) / Σbase`
- **AND** 当前欠款 MUST 为 `Σmax(base - paid_amount, 0)`

#### Scenario: 回款率分母为零

- **WHEN** 有效台账的合计基数为零
- **THEN** 回款率 MUST 显示“—”与“分母为 0，暂不可计算”
- **AND** MUST NOT 显示 `0%` 或 `100%`

#### Scenario: 不生成回款历史趋势

- **WHEN** 系统只有累计 `paid_amount` 与 `last_payment_date`
- **THEN** 驾驶舱 MUST NOT 将累计金额归属到最后回款日期并生成月度回款或现金流趋势

### Requirement: 投标中标率沿用既有 tender 口径

系统 SHALL 仅在 `tender` 对象存在且用户有查看权限时展示当前招投标管线和投标中标率。

#### Scenario: 计算投标中标率

- **WHEN** 用户有权查看招投标记录
- **THEN** 分母 MUST 仅包含状态为 `已递交 / 已中标 / 未中标` 的记录
- **AND** 分子 MUST 仅包含状态为 `已中标` 的记录
- **AND** 其他状态 MUST NOT 进入分母

#### Scenario: 招投标对象不可用

- **WHEN** `tender` 对象不存在或用户无 `object.tender.view`
- **THEN** 服务端 MUST 省略招投标面板及其聚合数据
- **AND** 其他驾驶舱面板 MUST 保持可用

### Requirement: 图表只表达现有事实

系统 SHALL 使用固定来源和清晰单位展示合同到现金、项目状态、生产与发货快照，不得从当前状态倒推不存在的历史事实。

#### Scenario: 展示合同到现金转化

- **WHEN** 用户有权查看相应合同、财务台账和开票记录
- **THEN** 系统 MUST 分别展示合同金额、累计实际发生金额、已对账额、已开票额和已回款额
- **AND** 每个数值 MUST 使用设计中指定的直接事实源与覆盖信息
- **AND** 合同金额 MUST 汇总可见 `contract.amount`，不得使用当前不存在的“已收到”合同状态过滤

#### Scenario: 展示项目状态分布

- **WHEN** 用户有权查看项目
- **THEN** 系统 MUST 基于当前 `overall_status` 以环形图展示分布
- **AND** 活跃项目 MUST 仅包含 `投标中 / 已中标 / 已拿到加工函 / 合同签署`
- **AND** `已完成` 与缺失或未知状态 MUST 在非活跃状态摘要中分别展示且 MUST NOT 进入环形图
- **AND** 扇区项目数之和、占比分母与圆心活跃项目总数 MUST 一致
- **AND** 圆心总数、状态名称、项目数、占比及等价文本摘要 MUST 可读
- **AND** 系统 MUST NOT 读取或创建当前项目对象不存在的 `stage` 字段

#### Scenario: 展示生产与发货

- **WHEN** 用户有权查看生产任务或发货记录
- **THEN** 系统 MUST 分别展示生产任务状态、生产量和发货量
- **AND** 金额与吨位 MUST 使用不同标签和单位
- **AND** MUST NOT 将生产量或发货量标记为产值

#### Scenario: 展示月度发货吨位折线图

- **WHEN** 用户有权查看含有效 `qty_ton` 的发货记录
- **THEN** 系统 MUST 仅将可解析为有限数值且 `>= 0` 的 `shipment.qty_ton` 视为有效吨位
- **AND** 数值 0 MUST 计入有效吨位记录覆盖且对汇总值贡献为 0
- **AND** 缺失、非数值、非有限值或负值 MUST NOT 进入吨位汇总，并 MUST 显示缺失或数据异常数量
- **AND** 负值 MUST NOT 被系统推断为退货或冲销
- **AND** 系统 MUST 按有效 `ship_date` 所在月份汇总有效吨位并以折线图展示
- **AND** 缺少或无法解析 `ship_date` 的有效吨位 MUST 计入累计发货量但 MUST NOT 进入折线趋势
- **AND** 趋势覆盖分母 MUST 为有效吨位记录数，分子 MUST 为同时具有有效日期的记录数
- **AND** 系统 MUST 显示趋势数据覆盖或折线图空态
- **AND** 折线图 MUST 明确标为“月度发货吨位”，不得推断为月度产值、生产量或回款趋势

### Requirement: 图表可访问且下钻不扩大权限

系统 SHALL 为图表提供等价文本表达并复用既有对象入口下钻。

#### Scenario: 图表可读

- **WHEN** 图表被渲染
- **THEN** 图例、数值、单位、状态和数据覆盖 MUST 有文本表达
- **AND** 类别 MUST NOT 只靠颜色区分

#### Scenario: 获授权用户下钻

- **WHEN** 用户从图表选择某状态或记录集合
- **THEN** 系统 MUST 导向既有对象页面或既有过滤入口
- **AND** 目标路由 MUST 再次执行既有服务端权限检查

#### Scenario: 聚合来源没有直接对象入口

- **WHEN** 某个图表聚合自当前工作区不允许直接访问的来源对象
- **THEN** 系统 MUST 省略该图表的下钻操作，或导向能够承载同一事实的既有可访问入口
- **AND** MUST NOT 生成会返回 403 的无效下钻链接

### Requirement: 现有大盘能力得到保留

驾驶舱 SHALL 重组现有大盘能力，但不得删除获授权用户已有的项目状态、风险提醒、最近项目及合同下钻入口。

#### Scenario: 用户升级前后能力对照

- **WHEN** 同一用户在变更前可见项目状态、风险记录、最近项目或合同下钻入口
- **THEN** 变更后该能力 MUST 在新驾驶舱中保持可见或提供明确等价入口
- **AND** 其权限范围、交互能力和下钻目标 MUST 保持不变

#### Scenario: 响应式布局

- **WHEN** 页面宽度不超过现有 `980px` 或 `760px` 断点
- **THEN** 新增 KPI、图表和风险内容 MUST 按现有布局规则重排
- **AND** MUST NOT 遮挡、替换或破坏现有移动端底部导航

### Requirement: 项目推进支持切换可见项目

驾驶舱 SHALL 在项目推进区域允许用户切换其当前可见范围内的项目，并展示所选项目的当前阶段。

#### Scenario: 默认展示最近项目

- **WHEN** 用户打开存在可见项目的驾驶舱
- **THEN** 项目推进区域 MUST 默认选择最近项目
- **AND** MUST 显示该项目基于 `overall_status` 的五阶段状态
- **AND** MUST NOT 显示“项目当前走到哪一步”标题

#### Scenario: 切换项目

- **WHEN** 用户在项目选择器中选择另一个可见项目
- **THEN** 五阶段状态和项目详情入口 MUST 立即切换到所选项目
- **AND** 切换 MUST NOT 写入项目或任何其他持久化数据

#### Scenario: 不泄露不可见项目

- **WHEN** 非管理员查看项目选择器
- **THEN** 选择器 MUST 仅包含经过既有项目可见范围过滤后的项目
