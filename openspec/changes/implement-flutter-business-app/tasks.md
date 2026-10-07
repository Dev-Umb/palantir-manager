## Implementation

- [x] 1. Confirm business V2 scope and inspect recent local baselines.
- [x] 2. Create Flutter Android project and local Laravel session/read adapters.
- [x] 3. Implement business screens and existing write/attachment/AI paths.
- [x] 4. Run focused backend and Flutter tests.
- [x] 5. Build and exercise the app in Android emulator against local backend.
- [x] 6. Run quality gate and record remaining limitations.

验证：Flutter 13 项测试、静态分析通过；独立后端质量门禁 322 项 PHPUnit、194 项前端测试及生产构建通过；主目录门禁 123 项 PHPUnit、139 项前端测试及构建通过。

安卓实际测试采用 1080 × 2340 / 420 dpi（约 6.1 英寸）：本地登录、项目查询、附件追加与保存、鉴权 PDF 两页阅读和返回已验证。AI 查询实际返回未配置提示并保留输入；未验证真实模型回答或识别结果。未部署生产。

## Production build and inline edit revision

- [x] 7. Bind release builds to the production HTTPS origin and dedicated signing key; reject missing signing configuration.
- [x] 8. Replace relation selector pages with matching inline dropdown fields and add project customer/contact editing through existing transactional endpoints.
- [x] 9. Review cross-module navigation and cap successive page transitions at three from the main menu.
- [x] 10. Verify 22 Flutter tests, static analysis, 27 focused backend tests and the repository quality gate. Exercise new contact creation and existing contact update in the 1080 × 2340 local emulator.
- [x] 11. Build and verify the signed 1.0.0+2 production APK and prepare the exact four-file backend patch with checksums and rollback.
- [x] 12. Supersede the unshipped backend adapter deployment with the user-requested client-only reuse of existing interfaces.
- [x] 13. Adapt Flutter to existing Web/Inertia/JSON routes, verify transport regressions, rebuild/install the production APK and validate production login/read-only business flows.

当前线上移动接口仍未发布：初次发布因匿名路由缓存随机名称造成比较失败，已自动恢复原文件和缓存；确认差异来源后，后续发布被自动审批暂停。正式 APK 已构建，生产账号 App 登录不应报告通过。


## Existing interface reuse verification (2026-09-11)

用户已明确不为移动端修改生产后端，双端共用现有接口。此前的移动适配器发布计划取消，交付目录中的 backend.patch 和 production-backend.patch 已移除；Flutter 不再调用移动专用路由。本地隔离环境的历史测试文件保留，不部署。

Flutter 29 项测试及静态分析通过，覆盖现有登录跳转、CSRF Cookie 轮换、Inertia 版本协商、会话过期、错误凭据、外域拒绝、循环跳转、记录不可访问、设置验证错误及原表单/导航回归。主目录质量门禁通过：123 项 PHPUnit / 1203 assertions、139 项前端测试、生产构建和 18 项 OpenSpec。

正式包 1.0.1+3 使用原独立 Release 签名，安装到 1080 × 2340 / 420 dpi 模拟器。用户提供的生产账号已实际登录成功，工作台、项目列表/详情、客户详情、AI 页面与输入框读取通过。未修改生产后端或业务记录。招采沿用原网页接口，未为了验证主动触发生产研究任务；实际 AI 回答/识别、生产写入未在本轮重测。

## Project and customer filters

- [x] 14. Add shared filter controls using the production objects query contract and authorized field metadata.
- [x] 15. Verify combined search/filter/pagination, cancellation/reset, invalid ranges, stale responses and retained business behavior.
- [x] 16. Build/install the signed production update and exercise both lists on the 1080 × 2340 emulator without business writes or backend deployment.


筛选验证：39 项 Flutter 测试、静态分析、主目录质量门禁（123 项 PHPUnit / 1203 assertions、139 项前端测试、生产构建、18 项 OpenSpec）通过。新增测试覆盖服务端查询参数、可见只读字段/不支持字段、零值与负值/范围校验、项目和客户搜索+筛选+分页、取消/清空/刷新、失败重试、异步旧响应及关联字段页内选择。

1.0.2+4 正式 APK 已使用原生产签名打包并安装，1080 × 2340 / 420 dpi 实测：项目总体状态“已中标”返回 4 条，客户等级 A 返回 6 条，清空客户条件恢复 291 条，跨标签项目条件保留。所有请求沿用原 objects 接口，未修改生产后端或业务记录。截图位于 mobile/build/qa 的 project-filter-* 与 customer-filter-* 文件。

## Client response optimization

- [x] 17. Trim detail/editor responses through existing Inertia partial requests and lazily mount unopened tabs.
- [x] 18. Verify complete detail/edit props, authentication/version boundaries, retained filters and tab state; run release checks.
- [x] 19. Build/install a signed production update and measure actual detail opening on the 1080 emulator without changing production services.


性能优化验证：45 项 Flutter 测试及静态分析通过；主目录质量门禁通过（123 项 PHPUnit / 1203 assertions、139 项前端测试、18 项 OpenSpec 和构建）。原有联系人/合同字段、附件、筛选分页及登录失效回归保持通过。生产只读验证客户详情、项目新建表单、联系人及合同页签正常。

同一项目的详情响应从约 163 KB 降至 9.4 KB，后端路由及权限未改。临时 Android 分段计时取得详情请求 96 ms、625 ms 两次样本，后者录屏与请求对齐，详情可见约 0.61 秒；较早优化版录屏也出现约 2.65 秒，显示网络波动仍影响耗时，不能把接口最快值当成稳定 SLA。优化前录屏约 3.75 秒。检测到空闲后建连约 1.1 秒后，将客户端空闲连接保留从 15 秒调为 60 秒；临时计时代码已移除，正式交付不记录请求内容。

最终 1.0.3+5 正式 APK（已移除诊断日志）已覆盖安装并使用授权账号登录，模拟器保持业务首页。生产服务、配置和业务记录未修改。


## Production repack and regression (1.0.4+6)

- [x] 20. Add compressed/uncompressed response parity and compressed error/session regression tests; retain the shared production interface contract.
- [x] 21. Build the signed 1.0.4+6 production APK, verify the existing signing certificate, and cover-install it on the 1080 × 2340 / 420 dpi emulator.
- [x] 22. Repeat automated and production read-only UI regression, discard test drafts, and record release evidence.

本轮 48 项 Flutter 测试、静态分析、隔离后端 24 项专项测试 / 270 assertions 通过。主目录质量门禁通过：123 项 PHPUnit / 1203 assertions、139 项前端测试、19 项 OpenSpec 和生产构建；原生 Nginx 测试因当前本地环境无 Nginx 跳过 1 项。本轮未修改生产服务，压缩配置由独立 optimize-shared-response-delivery 变更在上一轮完成。

生产模拟器实测通过：登录/首页、项目及客户筛选/清空、详情、项目编辑三个页签、知会人员同形态页内下拉框、客户联系人同页新增草稿、离开提醒/继续编辑/放弃草稿、返回列表保留筛选、AI 输入/返回保留草稿、账号版本和安全页。未保存生产业务记录、未上传或触发 AI/招采研究；写入与权限边界由本地自动化覆盖。测试草稿已清理，模拟器保持生产业务首页。

录屏单次样本：项目详情显示约 0.30 秒；登录至首页约 2.42 秒，不作为 P95 或稳定 SLA。新包 mobile/build/releases/palantir-1.0.4-production.apk；详细结果、签名、测试日志、截图和录屏位于 mobile/build/qa/release-1.0.4/。


## Secure persistent login (2026-09-12)

- [x] 23. Review and strictly validate saved-login requirements under the user's implementation request.
- [x] 24. Implement encrypted native storage, cookie lifecycle and startup restore with retry/explicit re-login; preserve existing interfaces.
- [x] 25. Verify persistence, failure, origin, expiry, logout/race boundaries and retained business behavior with automated tests.
- [x] 26. Build/install the production update and verify force-stop/relaunch and logout on the 1080 emulator; run quality gate and record evidence.


保持登录交付验证：1.0.5+7 已使用原 Release 证书打包、覆盖安装，生产域名不变。69 项 Flutter 测试、静态分析、7 项原生 Android Keystore 检查通过；原生测试覆盖跨实例加密读取、随机 IV、密钥不可导出、密文篡改/密钥丢失拒绝和密钥/文件清除，使用独立测试目录与密钥，不触及真实账号会话。

1080 × 2340 / 420 dpi 模拟器生产实测：勾选保持登录后登录成功，强制结束进程再打开自动进入首页（两次）；退出登录后强制重启仍为登录页；取消保持登录后可正常登录，但重启回到登录页。模拟器最终保持生产账号已登录首页。真实 Cookie 测试发现无末尾斜杠的绝对根地址跳转被错误当作空路径，已归一化为 / 并添加回归；不修改服务端。生产保存/附件上传/AI 生成未执行。

主目录质量门禁通过：123 项 PHPUnit / 1203 assertions，139 项前端测试、生产前端构建、19 项 OpenSpec；原生 Nginx 测试因本地未配置跳过 1 项。本轮未修改/部署后端、添加依赖或改变业务权限。APK、SHA-256、签名、截图与测试日志见 mobile/build/releases/palantir-1.0.5-production.apk 和 mobile/build/qa/release-1.0.5/。升级后需首次重新登录一次；以后按保持登录选项恢复，服务端失效仍要求重新验证。
