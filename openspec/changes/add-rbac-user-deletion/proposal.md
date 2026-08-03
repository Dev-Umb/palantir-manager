## Why

“用户与权限”目前只能调整角色，管理员无法移除已离职、测试或误注册账号。直接物理删除 `users` 会触发现有外键级联，清除 AI Run、通知等历史记录，因此需要提供受保护的账号删除能力，在立即撤销登录与角色的同时保留业务和审计历史。

## What Changes

- 在“用户与权限”的单个用户卡片增加删除操作和二次确认。
- 用户删除采用软删除：账号从正常查询与 RBAC 列表消失、无法继续认证，但历史业务、AI、通知和审计关联保留。
- 删除事务同时解除角色、删除数据库会话并写入审计日志。
- 禁止删除当前登录用户，禁止删除最后一个有效管理员。
- 在角色更新接口同步保护最后一个管理员，避免通过移除角色绕过删除保护。

## Capabilities

### New Capabilities

- `rbac-user-deletion`: 管理员安全删除单个账号，并保留历史关联。

### Modified Capabilities

- `rbac-user-role-management`: 用户角色更新不得移除最后一个有效管理员的管理员角色。

## Impact

- 新增 `users.deleted_at` 迁移并在 `User` 模型启用 `SoftDeletes`。
- `RbacController` 增加删除事务与删除能力描述；现有 RBAC 权限中间件继续作为服务端授权边界。
- `routes/web.php` 增加单用户 `DELETE` 路由。
- `resources/js/Pages/Rbac/Index.jsx` 增加删除确认、处理中状态与禁用原因。
- 不新增批量删除、恢复、角色删除，不修改现有业务对象与角色权限定义。
