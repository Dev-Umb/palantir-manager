## Decisions

Flutter 3 stable，Android 优先，Material 3 组件按已确认钢蓝设计定制。Laravel 为唯一业务数据权威；Flutter 解析现有 Inertia 页面响应 props，并处理版本协商、同源跳转、Cookie 与 CSRF；写入直接使用既有 JSON 响应接口。2026-09-12 用户要求重启免重复登录：复用现有登录 remember 参数，通过已有 Android MethodChannel、Android Keystore AES-GCM 与 noBackupFilesDir 保存 Cookie（绑定精确 origin），不保存原始密码/用户业务数据/权限快照；CSRF 请求保留、401/419 清理持久会话。文件通过 Android 系统文件选择，私有附件下载按既有接口鉴权。

## Scope contract

必须改变：移动 UI、客户端协议适配、本地运行链路。必须保持：字段与记录权限、未回款主档、多合同历史、Web 行为。允许隐藏：低频表格留 Web 明确入口。必须可见：权限/错误/空值/归档差异。禁止推断：查询权限等于写权限、离线伪成功、预算等于成交价、AI 回复等于保存。

## Capability mapping

设计依据 design-android-business-app 与 docs/android-app-design-20260908/revision-20260910.md 的字段完整保留表。所有权限由现有后端执行，Flutter 不新增授权规则。

| 能力 | 既有真实入口 / 数据 |
| --- | --- |
| 登录、会话、退出 | GET/POST /login、GET /settings 的 auth、POST /logout；XSRF-TOKEN Cookie |
| 工作台 | GET / 的 Inertia props |
| 项目、客户列表/详情/字段权限 | GET /objects/project、/objects/customer，包括 record/q/page 原查询参数 |
| 项目、合同、联系人保存 | 现有 /objects/records 写入与 /project-customer-profile/preview，原 payload 与事务 |
| 关联选择 | 服务端元数据提供的原 relation_options_url |
| AI 入口、历史、上传资格 | GET /ai 的 conversations、canUploadContracts；原 /ai/messages 与 /ai/contracts 路由 |
| 附件、电话 | 原鉴权下载 URL、Android 文件选择器/PDF/拨号 |
| 消息、账号设置 | GET /notifications，原通知操作及 PUT /settings/email、/settings/password |
| 招采参考列表/详情/证据 | GET /procurement-hub?q=...、/procurement-hub/notices/{id}；证据从 notice.evidence 读取，入口使用服务端 nav 可见性 |

不增加招采管理操作；读取沿用网页现有自动准备/已读行为。验证不额外触发生产研究任务。未知字段继续按原元数据显示。

## Validation

接口测试覆盖匿名、正常、不可见记录与字段、相邻 Web 行为；Flutter 测试覆盖四导航、AI 输入、空/负金额、错误保留表单。模拟器跑实际本地后端流程。没有配置真实模型时不声称识别或回答验证通过。

主测试设备按用户要求采用 1080 × 2340、420 dpi（约 6.1 英寸），模拟器与布局回归保持此配置。

## Mobile edit review (2026-09-11)

必须改变：知会人员等关联选择改为页内统一输入框；客户联系人新增、编辑和随项目保存；跨模块入口减少累计跳转。必须保持：多选、远程搜索、已有选择、共享客户覆盖确认、只读权限、附件与未保存草稿。允许隐藏：跨模块只读详情的返回堆叠，以直接业务入口替代。必须可见：联系人行、保存结果和错误、客户资料冲突。禁止推断：关联移除等于删除联系人、客户读取权限等于更新权限、客户端写入成功等于生产已发布。

映射：独立人员/客户选择页→同页展开下拉（搜索、多选、已有值保留）；客户联系人关联字段→同页选择及姓名/手机号编辑行，payload.customer_profile 随项目原事务保存；线上 customer-profile preview→同页冲突前后值及确认按钮。

导航按底部主菜单起点计算：项目详情(1)→编辑项目(2)→附件查看(3)；工作台→上传合同(1)→核对归档(2)，归档项目同页选择；客户/通知/AI 引用进入项目时直接开启项目流程，避免积累只读过渡页；招采列表(1)→公告详情(2)，证据关联公告替换当前详情，返回列表。编辑页返回提示与原有草稿保留。

正式构建使用独立 Release 签名与生产 HTTPS 地址；本地 debug 仍使用模拟器地址。取消此前未上线的四文件适配器发布计划。生产仅更新 Android APK，现有后端不改动。

## List filters (2026-09-11)

必须改变：项目/客户列表增加同样式的筛选入口、条件摘要、清空及完整分页。必须保持：已有关键词查询、记录详情、权限、服务端默认排序和编辑流程。允许隐藏：筛选编辑收进单层底部面板；多条件摘要可横向滚动。必须可见：当前条件数量、组合关系、查询中/失败/空结果。禁止推断：只过滤已加载页等于全部数据筛选、字段可筛选等于可编辑。

能力映射：项目/客户列表 → 既有 GET /objects/{object}；组合条件 → filters[i][field/operator/value] 与 filter_logic=and/or；搜索 → q；分页 → page/per_page。所有条件由当前账号 currentObject.fields 和 relationOptions 生成；不新增后端接口、不改生产 PHP。面板支持最多 10 条（沿用现有服务端限制）、等于/包含/数值日期范围/为空等按字段类型提供的规则，关联值使用已有页内选择组件。应用从第一页重查，取消不变，清空保留关键词；重试及翻页保留条件，拒绝旧响应覆盖新结果。

## Client response optimization (2026-09-11)

必须改变：详情/编辑请求只取所需 props，未访问的底部页延后首次加载。必须保持：原接口、完整详情/合同/附件/联系人、字段权限、筛选状态、刷新及保存后重载。允许隐藏：不显示原本就不用于当前详情的列表和其他对象元数据。必须可见：新数据到达前加载状态、失败和权限变化；不使用旧记录替代刷新。禁止推断：减少响应字段等于更改后台业务规则或允许修改生产配置。

映射：项目/客户详情 → 既有 /objects/{object}?record=...&per_page=1，X-Inertia-Partial-Component=Ontology/Index，props 保留 auth/nav/errors/currentObject/selectedRecord/can；项目新增/编辑 → 同入口额外保留 relationOptions，selectedRecord 内合同和附件不裁剪。版本重试保留字段选择，普通跳转清除旧组件选择；缺失所需 props 报错。底部 IndexedStack → 首次进入才挂载，已进入的页面继续保持状态和刷新入口。不部署后端、不增加依赖或记录缓存。

实机分段计时另发现默认连接闲置 15 秒后重建连接约需 1 秒。客户端空闲连接保留调整为 60 秒，继续由 HttpClient 管理服务端断连；只复用传输连接，所有详情和权限仍每次从服务端获取，不自动重试业务写入。临时诊断计时语句在发布前移除。


## Persistent login revision (2026-09-12)

The user requested saved login to avoid signing in on every launch. The login form exposes a default-enabled keep-signed-in option. Disabled means memory-only cookies and remember=false; enabled persists only verified session cookies. The current AuthController already accepts remember, so no backend, schema or dependency changes are needed.

Startup reads the encrypted session and requests the existing /settings page to obtain current user and permissions before rendering business content. Offline/transient failures preserve the encrypted session and offer retry or explicit return to login; unauthorized responses, corrupt data or a different exact origin never grant access. Cookie expiry/deletion/rotation apply to regular, multipart and download responses. Ordered persistence and a session generation guard prevent stale in-flight responses from restoring a cleared or different account session. Logout clears local memory and encrypted storage even when the server is unavailable.

Use the existing native bridge with platform cryptography instead of adding a package: AES-256-GCM with a per-install non-exportable Android Keystore key and fresh encryption IV, AtomicFile in noBackupFilesDir, no plaintext files/logs, no cloud/device migration of saved authentication. Password autofill remains supported by Android; the app never serializes the entered password. Storage errors are visible and never reported as successful persistence.

Capability mapping: login fields/show-password/submission/autofill remain in LoginPage; keep-signed-in is added there; restoring/retry/re-login live in the root startup screen; logout remains under My Account; auth/CSRF/version/same-origin protections remain in PalantirApi. No existing business entry is removed. Upgrade from 1.0.4 has no saved session and requires one successful login. Unchecking the option or logout returns to memory-only/guest state; rollback to the prior APK does not consume saved sessions.

Validation: mocked storage and HTTP tests cover restart restoration, long-lived remember cookie with expired short session, disabled persistence, wrong-password, rotation, deletion, origin mismatch, corrupt storage, unauthorized, transient network failures, logout and stale response races; widget tests cover restore/retry/login states. Native emulator checks cover encrypted roundtrip, force-stop/relaunch and logout/relaunch. Production business writes and password changes are excluded.
