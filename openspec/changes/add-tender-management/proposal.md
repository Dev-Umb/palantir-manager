## Why

业务目前从「项目主档」才开始记录，找标、买标、做标、投标、中标这一段前置流程完全在系统外，无法回答「哪些客户/招标方我们投得多、中得多」，也无法在购买标书、报名、投标等关键截止日前主动预警。需要在项目表之前新增招投标数据表，并打通「中标 → 项目 → 业务员」的流转，形成从找标到项目执行的全周期闭环。

## What Changes

- 新增 `tender`（招投标信息）业务对象，复用本体架构与 `customer` 客户主数据，承载从「跟踪中」到「已中标/未中标/已放弃」的完整状态机与三个关键截止日期（报名截止、购买标书截止、投标截止）。
- 新增 `tender`（招投标）角色，对招投标对象授予读写权限，并为前期客户对接授予客户查看、新建、编辑权限（不授予删除权限）；已有角色权限保持不变。
- 新增招标截止预警：新建独立的 `tender_notifications` 表与每日同步任务，按「前 3 天 / 前 1 天 / 当天」三级触发站内提醒，截止日期过后或流程越过节点自动解除。
- 新增中标人工流转：招投标负责人在「已中标」确认时人工选择接手的业务员，系统创建项目主档记录、回写 `converted_project_id`，并通知该业务员与全部管理员。
- 将招投标截止预警与流转通知接入现有通知页，保留既有项目通知展示、已读与未读统计行为。

## Capabilities

### New Capabilities

- `tender-tracking`: 招投标信息的登记、状态流转、附件管理与按客户维度的投标/中标统计基础数据。
- `tender-deadline-alerts`: 三个关键截止日的三级预警生成、升级、解除与接收人路由。
- `tender-conversion`: 中标后人工确认流转项目主档并指派业务员，含通知与审计。

### Modified Capabilities

- `customer-access`: 仅为新增 `tender` 角色增加客户查看、新建、编辑权限；客户对象定义及已有角色权限不变。
- `notification-center`: 在现有通知页追加招投标通知展示、已读与未读统计；既有项目通知生成规则与展示保持不变。

## Impact

- `config/xyc.php` 追加 `tender` 对象定义与 `tender` 角色（纯追加，不改既有条目）。
- 新增迁移：`tender_notifications`；不触碰任何已有表。
- 新增 `SyncTenderNotifications`、`ConvertTenderToProject` Action、`xyc:sync-tender-notifications` 命令与每日调度（在 `routes/console.php` 追加）。
- 前端：通用本体 UI 自动承接招投标 CRUD；补充中标流转确认对话框与预警展示。
- 全项目第三方通知渠道适配层已拆分到独立 change `add-notification-channels`，不在本变更实施范围内。
- 招标网站清单（`tender_source`）与投标率统计视图列为后续 Phase 2，不在本变更内。
