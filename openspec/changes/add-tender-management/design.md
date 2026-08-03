## Context

系统为配置驱动本体架构：`config/xyc.php` 声明对象 → `SyncXycMetadata` 同步 `business_objects` → 记录存 `object_records`（JSON payload），通用 CRUD/权限/附件已就绪。客户（`customer`）与项目（`project`）已共用主数据。既有提醒只有 `project_notifications`（合同/回款两类，每日 01:00 同步，项目专属表）。本变更全部走"只追加、不修改"路线。

## Scope contract

- 必须改变：新增 `tender` 对象、`tender` 角色、招标截止预警、中标人工流转；为 `tender` 角色增加客户查看/新建/编辑权限；现有通知页追加 tender 通知；中标记录在招投标表直接显示并允许改派接手业务员。
- 必须保持：全部已有对象定义（含 `project`/`customer` 字段）、已有数据表结构、`project_notifications` 及其同步逻辑、已有角色权限不变；客户删除权限不授予 `tender`。
- 允许隐藏：`tender` 对象仅对 `tender`/`business`/`admin` 角色可见，其他角色无导航、无接口返回。
- 必须可见：三级截止预警、中标流转的业务员指派、招投标表中的接手业务员字段、指派对业务员与管理员的触达、按客户聚合投标率所需的原始字段。
- 禁止推断：招标网站清单与自动抓取（Phase 2）、报名审批流、短信/飞书的具体供应商选型与密钥、项目主档新增字段——均不在本期，不替用户决定。

## Goals and non-goals

Goals:

- 招投标从找标到中标的全流程在系统内可追溯，且与项目、客户主数据打通。
- 三个截止日按 3/1/当天 三级预警，责任到人（创建人 + 招投标角色 + 管理员）。
- 招投标站内通知与现有项目通知在同一通知入口可见、可标记已读。
- 中标流转显式人工确认，指派业务员留痕可审计。

Non-goals:

- 不改造 `project_notifications` 表结构与既有项目通知规则。
- 不做招标网站自动抓取/解析。
- 不在 `project` 对象上新增字段（从 tender 单向回链）。
- 不做投标率统计图表页（Phase 2，本期只保证数据可聚合）。
- 不做飞书、短信、邮件等第三方外发；该能力由独立 change `add-notification-channels` 承载。

## Decisions

### 招投标对象（tender-tracking）

`config/xyc.php` 追加一个对象定义，key `tender`，label `招投标信息`，group `招投标`，code_prefix `ZB`，title_field `name`；`roles: ['tender', 'business']`，`write_roles: ['tender']`。`tender` 角色额外获得 `customer` 的 view/create/update 权限，不获得 delete 权限。核心字段：

- `name` 标的名称（必填）、`customer_id`（creatable_relation → customer，复用客户主数据；自由输入时在同一业务事务内创建客户）、`tender_agency` 招标单位/代理机构、`source_site` 信息来源网站（文本，Phase 2 再对象化）、`announce_date` 公告日期。
- 三个截止时间：`register_deadline` 报名截止、`purchase_deadline` 购买标书截止、`submit_deadline` 投标截止，均精确到分钟并按 `TENDER_TIMEZONE`（默认 `Asia/Shanghai`）解释，不修改全局应用时区；`bid_open_at` 开标时间、`budget_amount` 预算金额、`doc_fee` 标书费用、`purchase_status` 标书购买状态（未购买/已购买）。
- 附件：`tender_file` 招标文件、`bid_file` 投标文件扫描件（复用既有 file 字段与 AttachmentController）。
- `status` 状态机：跟踪中 → 已报名 → 已购标书 → 制作中 → 已递交 → 已中标 / 未中标 / 已放弃。除「已中标」外不做顺序硬校验；通用创建/编辑接口 MUST 拒绝写入「已中标」，该状态只能由 tender-conversion 在后端事务内写入。
- `converted_project_id`（relation → project，readonly）流转后回链；`assignee_user_id` 接手业务员（account，仅中标且已流转后可编辑）；`manager` 为 tender 自身的投标负责人文本，不复用项目业务员字段。

项目主档不加字段，避免修改已有对象；项目详情中的"来源招标"后续如需可见，走 Phase 2 的反向查询展示，不改 project 定义。

### 截止预警（tender-deadline-alerts）

- 新建 `tender_notifications` 表：`tender_id`（object_records uuid）、`type`（deadline/conversion）、`deadline_type`（register/purchase/submit/conversion）、`stage`（d3/d1/d0/converted）、`project_id`（可空）、`user_id`、`status`（active/resolved）、`triggered_at`、`read_at`、`resolved_at`、`occurrences`。与 `project_notifications` 独立，不复用不改造旧表；唯一键为 `(tender_id, deadline_type, stage, user_id)`。
- 新 Action `SyncTenderNotifications`：扫描 `status` 未终态的 tender，对每个未越过的截止时间按 Tender 业务时区计算「未来 72 小时内 → d3、未来 24 小时内 → d1、截止当日且尚未过期 → d0」；截止时刻已过或状态已越过对应节点则 resolve。接收人：tender 创建人 + 全体 `tender` 角色用户 + 全体 `admin`。
- 新命令 `xyc:sync-tender-notifications`，在 `routes/console.php` 追加调度 `dailyAt('07:40')` 与 `dailyAt('13:40')`（错开整点与既有 01:00 任务）。
- 现有通知页保留项目通知分页，并追加独立的招投标通知分区；全部已读同时更新两张通知表，单条已读使用独立 tender 路由，避免改变既有项目通知路由契约。

### 中标人工流转（tender-conversion）

- `ConvertTenderToProject` Action，由本体详情页「确认中标并流转」按钮触发（仅 `tender`/`admin` 可见可用），强制人工两步：确认弹窗 + 必选接手业务员（`assignee_user_id`，候选为 `business` 角色用户）。
- 事务内锁定 tender 记录，重新检查 `converted_project_id`，然后 tender `status` → 已中标；创建符合当前项目主档契约的 `project` 记录（`name` 默认取标的名称、`customer_id` 继承、`business_owner_user_id` = 接手业务员、`overall_status` = 已中标、`contract_status` = 未签署，并初始化知会人员与催款次数）；回写 `converted_project_id` 与 `assignee_user_id`；生成 `conversion` 类型站内通知给接手业务员与全部管理员；写审计日志。
- 已中标且存在 `converted_project_id` 的 tender，可由 `tender`/`admin` 在表格或编辑表单直接修改 `assignee_user_id`。改派必须选择 `business` 角色账号，并在同一事务内同步关联项目的 `business_owner_user_id`、通知新接手人并写审计日志；未中标记录、business 只读账号及非 business 候选必须被拒绝。项目其他字段、原创建人和历史通知保持不变。
- 幂等：`converted_project_id` 非空时拒绝重复流转并返回既有项目；并发请求必须由行锁保证至多创建一个项目。

## Alternatives considered

- 复用 `project_notifications` 表加类型：被拒绝——用户明确要求不改已有数据表，且招标预警的 stage 语义与项目通知不同构。
- 在 `project` 对象加 `source_tender_id`：被拒绝——修改已有对象定义超出"不改已有数据/定义"的约定，采用 tender 单向回链。
- 在本 change 同时建设全项目外发适配层：被拒绝——跨越既有项目通知行为且扩大交付面，拆分到 `add-notification-channels`。

## Risks

- 除「已中标」外状态机不做顺序硬校验可能产生脏状态：以列表筛选与 Phase 2 统计口径约定缓解。
- 客户自由输入可能产生重名：精确匹配已有客户名称时复用；未匹配时明确创建新客户并保留审计。
- 两次调度都落在同一预警窗口：由 `(tender, deadline_type, stage, user)` 唯一键与事务锁保证幂等。

## Rollback

`php artisan migrate:rollback` 回滚新表，移除 config 追加条目与调度行，删除新增类，并回退通知页的 tender 分区即可回到现状；既有 `project_notifications` 不需要数据回滚。

## Evidence boundaries

- L1 Unit：截止时间 stage 计算、状态越过判定。
- L2 Feature：tender CRUD 权限、客户查看/新建/编辑但不可删除、预警同步创建/升级/解除与唯一键幂等、通知页展示/已读、流转事务与并发幂等、通用 CRUD 禁止写入已中标、中标后改派同步项目负责人及未中标/越权/角色边界。
- L3 React：流转确认对话框与必选业务员校验、接手业务员字段仅在中标记录上可编辑。
- 保留相邻行为断言：既有 project 通知同步与 customer/project CRUD 测试原样通过。
