## 1. 提案与评审

- [x] 1.1 核对现有业务分支的三个日期、财务可写集合、业务员排除规则与提醒触发规则。
- [x] 1.2 明确范围、字段映射、权限保留与实施基线风险。
- [x] 1.3 运行 `composer openspec:validate` 严格校验（提案阶段 9 项通过；实施基线 23 项通过）。
- [x] 1.4 用户于 2026-09-08 明确批准：可以，按这个实施。

## 2. 实施（批准后）

- [x] 2.1 重新核对集成基线，在隔离 worktree 中实施，保留当前未提交改动；修改代码前检索版本对应文档。
- [x] 2.2 仅扩展财务对首末发货日期的可写集合，不改变业务员排除集合、现有末次回款日期权限和提醒规则。
- [x] 2.3 L2：覆盖三日期保存、非法日期、首末倒置、同日、单端修改、清空与历史空值。
- [x] 2.4 L2：覆盖业务员既有发货日期编辑权、末次回款日期防篡改、管理员、多角色、无权限账号、财务夹带其他字段以及审计与失败无副作用。
- [x] 2.5 L2：验证只改发货日期不重置提醒、末次回款日期变更沿用提醒规则、其他金额与项目字段保持不变。
- [x] 2.6 检查三个日期的页面控件与保存反馈；如修改前端，补最窄 L3 权限、保存与错误反馈测试。

## 3. 验证与交付（批准后）

- [x] 3.1 运行相关 `composer test:narrow -- <test-file-or-filter>` 和必要的 `npm run test:ui -- <test-file>`。
- [x] 3.2 PHP 变更运行 `vendor/bin/pint --dirty --format agent`。
- [x] 3.3 运行 `composer openspec:validate` 与 `composer quality:gate`。
- [x] 3.4 分别报告代码、本地测试、提交与部署状态；不自动部署、修改线上数据或归档。

## 实施证据（2026-09-08）

- 基线：`codex/fix-filtered-sorting` / `0d3a8b7`；实现位于 `/private/tmp/palantir-finance-project-dates`，分支 `codex/finance-project-dates`，实施完成时尚未提交；发布提交及证据见下文。
- 生产代码仅涉及 `BusinessWorkspace.php` 的财务日期权限以及 `OntologyController.php` 的等值数值变更判断，防止只改日期误重置回款提醒或合同金额来源；详见 design 的边界修正。
- `FinanceProjectDatesTest.php` 新增 25 项参数化测试；与 `BusinessContractWorkflowTest.php` 合计 42 项、521 断言通过。旧提醒测试改用历史状态 fixture，并通过实际可编辑的末次回款日期验证重新起算，不新增状态编辑能力。
- 页面元数据、保存 JSON、重新读取与非法输入反馈已由 L2 验证；复核既有 SchemaForm/FieldControl 日期控件，前端源码未修改；未进行浏览器或线上操作。
- `vendor/bin/pint --dirty --format agent` 通过；`composer quality:gate` 通过：OpenSpec 23 项、后端 179 项/2144 断言、前端 28 文件/174 项、生产构建全部通过；`git diff --check` 通过。
- 实施阶段保留原工作区业务代码及未提交改动，仅同步本 change 的批准、设计和执行状态；该阶段未提交、合并、部署、写入线上数据或归档。

## 线上发布证据（2026-09-08）

- 用户明确授权“部署线上”。发布代码提交：`564d0e5`；提交前 `composer quality:gate:staged` 通过。
- 目标 `/var/www/palantir` 的两项目标文件及相关项目元数据/归一化代码与实施基线指纹一致；仅发布 `app/Support/BusinessWorkspace.php`、`app/Http/Controllers/OntologyController.php`。未替换线上依赖、前端资源、配置或数据库，也未运行迁移/seed。
- 2026-09-08 03:13:47 UTC 创建原文件备份：`storage/app/deploy-backups/finance-dates-564d0e5-20260908T031347Z/before.tar.gz`；替换后平滑 reload `php8.4-fpm`。
- 发布后 SHA-256：BusinessWorkspace `b4d0e92616b21ce322302d5a81f89fc9a37453e68fd4155735d18248706a6f6d`；OntologyController `6946e97d6b860b7a60d169c6eda3e28e24525a2948c2538cf2603592360ca455`，与提交文件一致。
- 线上只读事务内使用现有单角色账号调用应用 HTTP Kernel 的 `GET /objects/project`：财务、业务员、管理员均 200。财务三个日期均可编辑；业务员首末发货可编辑、末次回款只读；管理员三个日期可编辑；财务项目名称与所有角色催款次数权限保持原状。
- 外网 `/login` 200（约 0.40 秒）、`/up` 200（约 0.28 秒）；PHP-FPM 与 Nginx 均 active，PHP-FPM NRestarts 为 0。
- 发布前只读探针初始化过程中产生两条 CLI 错误，修正请求初始化及 Inertia 版本头后探针正常；不得将这些发布前探针错误归为发布后故障。
- 未执行真实业务记录的线上保存或浏览器交互回归；写入、日期校验及提醒边界由本地 179 项后端测试覆盖。未合并、推送到远端 Git 或归档本 change。
