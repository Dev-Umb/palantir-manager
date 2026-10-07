## Context

项目记录使用 `object_records.payload` 承载可扩展数据。通用列表先应用权限、搜索和筛选，再应用排序。现有项目默认排序是项目名称，明确排序由元数据字段白名单控制。

## Decisions

### 1. 顺序值保存在项目 payload

使用 `_statement_order` 数值键，不添加到 `BusinessObject.fields`。因此通用表格、详情、表单、筛选器和显式排序选项都不会暴露它。

普通项目编辑只提交字段元数据中的业务字段，后端在保存时从原 payload 保留 `_statement_order`，避免日常编辑使项目退出对账单顺序。

### 2. 默认排序仅在项目对象启用

当 `sort` 缺失、为空或无效时，项目查询重置先前的最近更新排序，然后使用：

1. 非空 `_statement_order` 在前；
2. `_statement_order` 数值升序；
3. 同序或空序项目按 `updated_at` 降序、`id` 降序稳定排列。

PostgreSQL 使用 `payload->>'_statement_order'`，SQLite 使用 `json_extract`，均显式转为数值，避免字符串顺序中 `10` 排在 `2` 之前。

### 3. 筛选与排序保持正交

继续先应用搜索/筛选 `where` 条件，再应用默认排序。不为筛选创建新分支，因此筛选后自然保留对账单顺序。

## Risks and Mitigations

- JSON 数值排序无独立索引：当前项目量为百级，不为此增加持久列和迁移；若未来数量级扩大再以查询计划证据决定索引。
- 部分项目无顺序：明确放在末尾并使用稳定后备键，不猜测与对账单的对应关系。
- 更新项目可能丢失隐藏键：项目现有更新流程会合并/保留未由用户编辑的 payload 键；使用回归测试锁定。

## Rollback

代码回滚后项目恢复旧默认排序。`_statement_order` 可保留于 payload 中且不会被展示；若需移除，使用执行前备份定向恢复，不全表猜测重写。
