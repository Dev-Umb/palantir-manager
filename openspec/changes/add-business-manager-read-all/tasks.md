## 1. 需求与基线

- [x] 检查现有角色、项目归属和读写判断，确认不能直接赋予 admin。
- [x] 编写范围、能力映射和验收场景。
- [x] 审核提案：保留原字段写权限；按负责业务员归属；共享客户主档只读。
- [x] 确认部署与集成基线、武全明唯一账号 ID、现有角色及角色冲突。
- [x] 运行 composer openspec:validate：11 项通过，退出码 0。

## 2. 实施

- [x] 在隔离工作区新增业务管理查看角色及读取范围，保持普通账号原行为。
- [x] 分离本人写入判定，接入全部现存直接和间接写入口及逐行界面权限。
- [x] 保留元数据同步、对象与字段权限、审计和角色撤销行为。

## 3. 验证与交付

- [x] 运行目标、保留相邻行为及越权边界的 L2 测试。
- [x] 运行受影响的 L3 测试，核对完整读取与只读呈现。
- [x] PHP 改动运行 Pint；运行 composer openspec:validate 与 composer quality:gate。
- [x] 分别记录代码、测试和部署证据；核实后为唯一目标账号赋权并记录审计。
- [x] 验证目标账号可查看他人数据、可维护本人允许字段、服务端拒绝修改他人数据。

## 提案阶段检查记录

- 2026-09-08：仅新增本提案四个 OpenSpec 文件，未修改业务代码或账号权限。
- `composer quality:gate` 已执行，停在 PHPUnit：61 个测试中 6 个通过、55 个错误，报错为 SQLite 测试库缺少 `users.deleted_at`；未进入后续前端测试与构建。本次未修改 PHP、迁移或测试，不在提案阶段修复原工作区的代码/测试基线问题。
- OpenSpec 校验通过；工具遥测另报 `edge.openspec.dev` DNS 错误，不影响其校验结果与退出码。


## 本地实施证据（2026-09-08）

- 用户已批准：新增角色、本地开发测试、通过后部署、部署后用武全明账号测试。
- 隔离目录 `/private/tmp/palantir-business-manager-read-all`，分支 `codex/business-manager-read-all`；业务代码未写入原来的脏工作区。
- 线上只读核实：唯一目标账号 ID 17，姓名武全明，仅 business 角色，名下 37 个项目；尚未赋权。
- 新增 `business_manager_view`：7 项纯读取权限；保持原业务写权限，按负责业务员限制项目写入，共享客户及联系人只读；保护通用、内嵌维护、关联、AI 提案确认、流转及工作流阅读副作用。
- 线上文件核查发现基线差异，已经原样保留对账单排序、项目保存时 `_statement_order`、联系人姓名电话展示、附件下载链接、visibleProjectIds 可选 ID 参数及通知已读页面交互。
- 最终现有依赖下质量门禁通过：OpenSpec 24 项；后端 196 项、2339 断言；前端 28 文件、178 项；生产构建通过。Pint 与 diff 检查通过。
- 独立本地 PostgreSQL 临时库执行权限/普通业务员范围/财务日期共 45 项、508 断言通过；SQLite 之外验证真实 JSON/UUID 查询。新增保留行为回归后本地目标与排序共 22 项通过。
- 部署和账号测试脚本已在 `/private/tmp` 准备，未执行；计划使用 ID 17 的临时真实浏览器会话验证页面，并在数据库回滚事务内验证本人保存和他人拒绝，保留真实业务数据。
- 用户随后明确回复“允许，批准部署”，已批准同步线上锁文件版本 dompurify 3.4.14、nanoid 3.3.18、postcss 8.5.26、undici 7.29.0；npm ci --ignore-scripts 成功，审计 0 vulnerabilities，重新执行最终门禁。


## 已批准发布及线上验收（2026-09-08）

- 同步获批的线上锁文件后最终完整门禁及提交门禁通过：OpenSpec 24 项，后端 196 项/2339 断言，前端 28 文件/178 项，生产构建；Pint/diff 检查通过。
- 实现提交 `3f41e1a`，分支 `codex/business-manager-read-all`；未推送/合并远程分支。线上发布 52 个源文件及构建文件，未执行迁移或全量 Seeder。
- 部署前 59 个线上文件哈希均与已审查基线一致；发布包 SHA256 `81798c7556da8f01c377da19f3395bce6f530b1bb4d1db87e90a093fb1ce988e`，发布后全部文件哈希一致。
- 首次发布以 www-data 重建配置，由于线上 .env 为 ubuntu 600，产生 SQLite 队列缓存错误；代码已自动回退，随后立即以 ubuntu 恢复 PostgreSQL 配置并确认服务正常。修正发布命令并将配置缓存纳入备份后再次发布成功。
- 成功发布前的恢复点：`/var/www/palantir/storage/app/deploy-backups/business-manager-3f41e1a-retry/before.tar.gz`（代码、构建、配置缓存），同目录 `account-17-roles-before.json` 保存目标账号原角色及新增角色此前状态。FPM 平滑重载、队列重启完成。
- 唯一账号 ID 17 / 武全明追加 business_manager_view，保留 business；角色仅 7 项读取权限；事务写入并记录系统审计。
- 线上 HTTP 内核使用该账号验证：项目 332/332、客户 291/291、合同 3/3、项目业务汇总 332/332 全量可见；投标列表 0/0 正常。本人 can_update=true、他人 can_update=false；本人保存返回 200 并在事务内持久化；他人更新/删除、只读客户更新返回 403。整个测试事务回滚，核对本人及他人记录原始属性完全一致。
- 实际 Chromium 浏览器使用该账号临时会话：直接访问他人编辑链接显示“当前数据为只读。”，本人编辑表单可编辑；页面 JavaScript 错误 0。浏览器未提交业务修改；临时会话登出返回 200，临时凭证文件删除。本次未更改或测试账号密码。
- 浏览器证据：`/private/tmp/palantir-manager-foreign-readonly.png`、`/private/tmp/palantir-manager-own-editable.png`；接口证据：`/private/tmp/palantir-manager-live-permission-results.json`。
- 发布后再次确认 PostgreSQL 配置、php8.4-fpm/nginx active、公共 /login 与 /up HTTP 200。已完成部署及账号验收，未归档 OpenSpec。
