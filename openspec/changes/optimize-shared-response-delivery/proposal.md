## Why

2026-09-11 用户要求优化双端共用后端，并允许在必要时使用 Redis。生产控制器只读测量：首页约 260 ms（SQL 21 ms），项目列表约 111 ms（SQL 17 ms），客户列表约 70 ms（SQL 14 ms）。此前约 2 秒响应包括大量未压缩 JSON 下载，不能视为纯数据库耗时。

## Scope Contract

- 必须改变：Palantir 站点现有 gzip 类型补充 application/json，降低相同业务响应的传输体积。
- 必须保持：所有端点、解压后的 JSON 字段和值、权限和数据新鲜度、状态码、CSRF/session、原有 CSS/JS 压缩与静态资源缓存策略。
- 允许隐藏：仅传输编码，不删除任何业务字段。
- 必须可见：原有业务结果和错误响应。
- 禁止推断：不将 TTFB 当作 SQL 耗时；不因允许 Redis 就缓存权限或业务快照；不修改其他服务的配置。

## What Changes

- 修正已有 Palantir Nginx snippet 的 gzip_types，保存可审阅的配置副本。
- 按现有 Accept-Encoding 协商；客户端不支持 gzip 时继续返回 identity。
- 运行只读生产响应对照与 Android 复测；备份、校验配置和 reload 失败时回退。
- 本轮不加 Redis、不改 Laravel/PHP、数据库、API 或 APK。后续查询优化须有独立实测依据。

## Capabilities

### New Capabilities

- `shared-response-delivery`: Preserve existing response semantics while compressing negotiated JSON transport.

### Modified Capabilities

None. All existing business and authorization contracts remain unchanged.

## Impact

仅 Palantir 专用 Nginx snippet 和本地运维验证制品。本次用户后端优化请求授权该最小性能修正；此前移动端“不修改生产后端”的范围只在此必要共享传输修正上扩展。
