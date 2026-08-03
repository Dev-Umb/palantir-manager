## 1. 本体定义与角色

- [x] 1.1 在 `config/xyc.php` 追加 `tender` 角色定义与角色权限映射：tender 对象读写、customer 查看/新建/编辑，不授予 customer 删除；不改动既有角色条目。
- [x] 1.2 在 `config/xyc.php` 追加 `tender` 对象定义（精确到分钟的截止时间、状态机、附件、creatable_relation 客户、readonly 回链），执行 `SyncXycMetadata` 同步。
- [x] 1.3 补齐 tender→customer 的 creatable_relation 后端真实创建与审计，精确名称匹配时复用已有客户。
- [x] 1.4 L2 Feature：招投标 CRUD 权限、客户查看/新建/编辑但不可删除、business 只读、其他角色拒绝，以及既有对象定义保持不变的相邻断言。

## 2. 招标截止预警与通知页

- [x] 2.1 新增 `tender_notifications` 迁移与模型（含 deadline/conversion 类型及 `(tender_id, deadline_type, stage, user_id)` 唯一键）。
- [x] 2.2 新增 `SyncTenderNotifications` Action 与 `xyc:sync-tender-notifications` 命令，在 `routes/console.php` 追加 `07:40` / `13:40` 调度并防重叠。
- [x] 2.3 L1 Unit：精确到分钟的 72/24 小时/当天 stage 窗口与状态越过判定；L2 Feature：预警创建/升级/解除/重激活、唯一键幂等、接收人集合。
- [x] 2.4 在现有通知页追加 tender 通知分区、未读计数、单条已读与全部已读，保持既有 project 通知分页与路由行为。
- [x] 2.5 业务备份与重置命令同构纳入 `tender_notifications`，保留既有备份字段。

## 3. 中标人工流转

- [x] 3.1 新增 `ConvertTenderToProject` Action（行锁事务、必选 business 用户、幂等拒绝、审计）与流转接口路由。
- [x] 3.2 通用 tender 创建/编辑接口拒绝直接写入「已中标」，前端通用选择器不提供该选项。
- [x] 3.3 前端详情页「确认中标并流转」按钮与确认对话框（业务员必选，候选为 business 角色用户）。
- [x] 3.4 流转生成 tender 站内通知（接手业务员 + 全部 admin）。
- [x] 3.5 L2 Feature：成功、未选业务员、非 business 指派、直接写已中标拒绝、重复/并发流转、通知与审计、流转项目与手工项目等价；L3 Vitest：确认对话框与必选校验。

## 4. 验证

- [x] 4.1 `composer openspec:validate` 严格校验通过。
- [x] 4.2 `composer test:narrow` 运行全部新增与相邻测试文件。
- [x] 4.3 `npm run test:ui` 与 `npm run build` 通过。
- [x] 4.4 `vendor/bin/pint --dirty --format agent` 格式化。
- [x] 4.5 `composer quality:gate` 全量门禁通过并复核最终 diff 无越界改动。
