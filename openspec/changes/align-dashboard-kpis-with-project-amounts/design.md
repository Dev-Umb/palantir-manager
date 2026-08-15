## Data Flow

Dashboard 已通过 `ProjectVisibility` 读取当前用户可见项目，并生成 `cockpit.panels.project_amounts.company`。本变更在同一次请求、同一项目集合上生成顶部 KPI，复用相同的数字解析与覆盖统计，避免额外查询和口径漂移。

## Metric Mapping

| 顶部展示 | 项目主档来源 | 规则 |
| --- | --- | --- |
| 累计实际发生金额 | `project.payload.occurred_amount` | 与“已发生金额总计”相同 |
| 当前欠款 | `project.payload.unpaid_amount` | 与“未回款金额总计”相同 |
| 项目待跟进 | `project.payload.unpaid_amount` | 有效数字且大于 0 的项目数 |

金额仍按项目金额区块的既有规则处理：有限数字有效，0 和负数参与金额总计及覆盖；只有待跟进计数要求大于 0。每 15 秒 Inertia 部分刷新 `cockpit` 时重新从数据库计算。

## Permissions

两个 KPI 只随可见项目生成。没有 `object.project.view` 权限时不返回；业务员只统计其可见项目，管理员统计公司全量。财务台账权限不再决定这两个 KPI 是否出现。

## Rollback

恢复顶部 KPI 的财务台账聚合即可；无数据库或业务数据回滚步骤。
