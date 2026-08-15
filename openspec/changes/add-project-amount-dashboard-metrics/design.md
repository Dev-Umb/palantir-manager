## Context

项目主档保存在 `object_records.payload`，金额字段为 `occurred_amount`、`paid_amount`、`unpaid_amount`，负责人字段为 `business_owner_user_id`。现有 Dashboard 已通过 `ProjectVisibility` 取得当前用户可见项目，本变更复用该内存集合，避免新增项目读取查询或扩大权限范围。

## Data Contract

新增 `cockpit.panels.project_amounts`：

- `company`: 三个金额项，每项包含 `value` 与 `coverage { valid, total }`。
- `salespeople`: 仅包含至少关联一条可见项目且能解析为现有账号的业务员；每行包含账号 id、姓名、项目数及三个金额项。
- `unassigned_projects_count`: 负责人为空或无法解析为现有账号的可见项目数。
- `as_of`: 本次数据库读取的时间。
- `url`: 复用 `/objects/project`。

公司总计覆盖所有可见项目，包括负责人为空或账号无法解析的项目；业务员行只汇总该账号负责的项目。每条项目记录独立计入，不按客户名或项目名去重。

## Amount Semantics

三个字段分别计算，不要求同一项目同时具备三项金额：

- PHP `is_numeric` 为真且转换为有限浮点数时视为有效。
- 0 和负数都是有效值；0 参与覆盖计数且对总和贡献为 0，负数按数据库原值参与合计。
- `null`、空字符串、文本或非有限数字不参与该字段合计。
- 某字段没有任何有效记录时返回 `null`，界面显示“—”。

## Refresh Strategy

Dashboard 初次访问正常服务端渲染全部数据。前端使用 Inertia v3 `usePoll(15000, { only: ['cockpit'] }, { mode: 'rest' })` 每 15 秒部分刷新驾驶舱；不设置 `keepAlive`，后台标签页使用框架默认节流。每次请求重新读取数据库，不创建缓存、快照或写入任务。

## Alternatives Considered

- 从 `receivable` 财务台账聚合：拒绝，用户明确所有数据以项目主档为准。
- 把空值按 0 聚合：拒绝，会把未维护数据误报为真实零金额。
- 新增汇总表或缓存：拒绝，会增加一致性和写入链路，且不符合实时读取要求。
- WebSocket 推送：拒绝，15 秒只读轮询已满足当前实时性，新增实时基础设施超出范围。

## Risks and Mitigations

- 项目数量增长会增加每次聚合成本：复用 Dashboard 已加载的可见项目集合，新增账号名称解析最多一条查询。
- 负责人引用失效会导致业务员表与公司总计不一致：公司总计仍保留这些项目，并显式显示未分配/无有效账号项目数，不擅自归属。
- 页面后台轮询浪费资源：保留 Inertia 默认后台节流。

## Rollback

移除新增 panel 的服务端组装、React 区块、样式和测试即可；没有数据库或业务数据回滚步骤。
