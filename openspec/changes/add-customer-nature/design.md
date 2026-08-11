## Context

业务对象由 `config/xyc.php` 定义，记录存于 `object_records.payload`。project 已通过 `customer_id` 关联 customer，`ObjectRelations` 统一格式化列表和详情响应。

## Scope Contract

- 必须改变：客户可维护两个固定选项；项目列表和详情实时只读展示。
- 必须保持：现有字段、数据、关系、路由、权限和 CRUD 行为。
- 允许隐藏：客户未填写、项目无客户或断链时显示空值。
- 必须可见：客户下拉仅两个选项；项目无编辑入口。
- 禁止推断：不自动分类、不回填历史、不在项目保存副本、不扩展成其他对象规则。

## Design

customer 追加 `customer_nature` select。project 追加同名 `lookup`。格式化项目集合前批量读取关联客户，缓存当前性质，再生成响应中的派生 payload/display；提交的项目同名字段被只读过滤，不会持久化。

## Alternatives Considered

- 项目同步保存：拒绝，违反唯一事实源并引入漂移。
- 前端从 relation options 拼接：拒绝，分页、权限和非 UI 输出会不一致。
- 新增列或字典表：拒绝，固定二选一无需 schema。

## Risks and Mitigations

- N+1：按当前项目集合一次批量读取客户。
- 非法客户值：沿用 select 的 `Rule::in` 校验。
- 历史空值误判：不设置默认值。

## Rollback

回退两个字段定义、派生读取和测试；不删除任何业务数据。

## Evidence Boundaries

- L2 覆盖合法/非法值、CRUD、实时透传、项目不可写、空值/断链和相邻字段。
- L4 在固定生产 URL 部署后执行真实浏览器 CRUD 并清理测试记录。
