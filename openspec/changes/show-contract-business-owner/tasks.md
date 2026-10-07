## 1. Design

- [x] 1.1 核对来源字段、合同关联和现有读取/导出行为。
- [x] 1.2 按用户明确要求评审最小字段扩展，确定历史合同实时读取和空值边界。
- [x] 1.3 实施前严格校验。

## 2. Implementation

- [x] 2.1 增加只读合同业务员字段与统一实时读取/查询表达式。
- [x] 2.2 增加目标、相邻和授权/缺失关系/批量读取边界测试。
- [x] 2.3 保留账号设置及既有业务行为。

## 3. Validation

- [x] 3.1 相关 L2 测试、Pint、严格 OpenSpec 校验。
- [x] 3.2 完整质量门禁和页面检查。
- [x] 3.3 记录本地交付及未部署边界，不归档。

## Evidence (2026-09-08)

- 实现位于 `/private/tmp/palantir-user-account-settings`，沿用本任务分支 `codex/user-account-settings`；此前账号设置及管理员密码重置保留，主工作区仅同步本 change 文档。
- 本次生产变更仅 `config/xyc.php`、`app/Support/ObjectRelations.php`、`app/Http/Controllers/OntologyController.php`，共 64 行新增；未修改前端组件、数据库物理 schema、合同写流程、权限或历史业务 payload。
- 合同列表、详情和导出新增“负责业务员”，在项目名称之后。通过关联项目的 business_owner_user_id 读取当前未删除账号姓名；换人、改名下一次读取即时反映，缺失时留空。
- 新增 `ContractBusinessOwnerTest` 11 项、200 断言在 SQLite 与独立临时 PostgreSQL 17 实例均通过；与既有 `ProjectContractMaintenanceTest` 合跑 17 项、291 断言通过。
- 完整 `composer quality:gate` 通过：OpenSpec 25 项；后端 213 项、2543 断言；前端 30 文件、182 项；生产构建通过。Pint 与 diff whitespace 检查通过。
- 本地真实浏览器验证合同列实际可见且出现不同负责业务员姓名，CSV 包含同名字段和值，无页面脚本错误；截图 `/private/tmp/palantir-contract-business-owner.png` 已检查。
- 本地实施阶段 PostgreSQL 与浏览器都使用本任务的隔离演示数据，临时服务测试后停止，当时没有线上数据读写或发布。后续经用户授权发布，见下方记录；未合并其他分支或归档 change。
- 上线需同步新增合同字段元数据；不需要批量保存旧合同。发布时应仅合入该字段到当前合同元数据，避免全量元数据同步触发既有业务重算或权限重置。

## Production evidence (2026-09-08)

- 随账号设置功能提交 `d7d1308`，按用户部署指令于 2026-09-08 19:49:53（UTC+8）上线。基于线上现有代码精确叠加补丁；合同字段在现有项目字段后增量插入，保留全部既有字段次序和业务 payload，未重跑元数据同步或 seed。
- 服务器备份与指纹证据：`/var/www/palantir/storage/app/deploy-backups/account-contract-d7d1308`。211 个无关源文件指纹未变化，原隐藏项目排序、飞书集成和表格修复保留。
- 线上回归 `REG-ACCOUNT-CONTRACT-20260908115551` 共 14 组通过，包含合同负责业务员可见、只读、筛选、排序和 CSV；业务员仅见自己项目合同；财务仍不能访问合同；临时项目改派和业务员改名后合同下一次读取立即更新。
- 回归专用项目/合同/账号全部清理。清理后遍历线上全部 3 份现有合同，3 份业务员显示都与关联项目当前账号一致，无缺失来源。原 1,080 条业务记录、真实账号和权限摘要与发布前相同；无页面脚本错误及回归请求 5xx。
- 结果 `/private/tmp/palantir-online-regression-result.json`；真实线上测试页面截图 `/private/tmp/palantir-online-contract-business-owner.png` 已检查。账号设置 change 的发布记录列有共享备份、门禁和构建详细证据。
