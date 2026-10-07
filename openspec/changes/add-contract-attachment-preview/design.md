## Context and Evidence

2026-09-08 只读核对：

- 当前工作目录为 `codex/optimization-plan` / `8211b10` 且有大量既有未提交改动。其附件控制器只有单文件实现，不能直接作为新需求实施基线。
- 本地 `codex/auto-contract-status` / `64114b4` 和 `codex/user-account-settings` / `8aa0c56` 已有 `processing_letter_attachments`、`contract_attachments`、`statement_attachments` 数组与可选附件索引。
- `8aa0c56` 的 `AttachmentController` 检查对象 view 权限、`ProjectVisibility`、file/files 字段、私有路径、文件存在和真实 MIME，最后强制下载；路由为 `/attachments/{record}/{field}/{index?}`。
- 同分支 `ProjectContractEditor.jsx` 的历史区、只读详情，以及 `FieldControl.jsx` 使用新窗口附件链接；`ObjectRelations.php` 将附件值转换为下载 URL。不能将这些 URL 改成对象而破坏旧消费者。
- `docs/sales-user-guide-20260908/manual.md` 说明 PDF/JPG/JPEG/PNG、每份 20 MB、历史追加和合同状态条件。它是需求背景，不是本轮线上验证证据。
- `design-android-business-app` 是设计阶段；该仓库未发现 Kotlin/Gradle 客户端实现。客户端技术和鉴权须与该变更协调。

这些是本地代码与文档证据，未核实当前生产部署。实施前须确定实际部署版本与集成分支，不能直接切换/覆盖当前脏工作区，也不能将较新本地分支自动等同线上。

## User Flow

1. 用户保存项目合同附件。只有服务端确认成功且重新返回附件列表后，文件进入“已上传”列表。
2. 用户从项目详情、项目编辑历史附件区或合同台账/详情点击某份加工函/合同的“在线查看”。
3. 打开阅读层，显示“加工函附件 2”或“合同附件 1”、加载提示、关闭和下载。已有持久化原名才显示原名；哈希路径不作为用户标题，也不编造历史原名。
4. PDF 展示当前页/总页数、上一页/下一页、缩放和适宽；图片支持缩放、复位适宽、拖动。
5. 用户关闭或按返回键时回到原上下文，保留表单内容、新选文件、列表位置与原附件顺序。失败显示明确说明，允许重试或在有权限时下载。

手机为占满可用视口的阅读层，320/360/390/412 px 宽度下主要按钮可用，不产生工具栏横向溢出；电脑使用大阅读层。按钮支持键盘，打开后焦点进入阅读层，关闭后回到触发入口。浏览器后退与关闭动作都不应直接离开未保存表单。

## Technical Decisions

### 1. 明确边界的增量接入

仅对合同对象的加工函/合同附件启用预览。通用附件组件可以复用，但须由服务端明确返回的预览描述启用；其他对象与对账单保留原行为。实施基线确有合同旧单文件时才增加兼容映射，不迁移或重分类原值。

服务端在原 `payload` / `display` 之外增加只读派生描述，例如按字段组织的 `attachment_previews`：记录 ID、字段、原数组索引、类别显示名、信息 URL、内容 URL、原下载 URL。前端不根据文件扩展名或解析下载 URL 决定权限和类型；也不得使用过滤后的 UI 序号替代原数组索引。列表渲染不逐份打开文件获取 MIME/页数，点击后再读取信息。

### 2. 鉴权与响应

保留 `attachments.download` 的原地址、下载语义和合法文件行为。新增命名路由，建议使用独立 `/attachment-previews/{record}/{field}/{index?}` 前缀返回 JSON 信息，对应 `/attachment-content/{record}/{field}/{index?}` 返回内联字节；正式命名在实施基线核对后确定，避免与原数字 index 路由冲突。

三种读取路径共用同一授权和文件解析规则：登录、对象读取权限、记录数据范围、合法 file/files 字段、原数组索引归属、白名单私有路径、存在性和真实 MIME。预览另加本次业务字段范围限制。不得接受客户端提供的任意磁盘路径或远程 URL。

信息接口仅对授权文件返回 MIME、字节数及可预览类型；内容响应返回正确 MIME、`Content-Disposition: inline`、`Cache-Control: private, no-store` 和 `X-Content-Type-Options: nosniff`。下载继续 `attachment`。信息与内容请求分别实时授权；HTTP Range/HEAD 也不得绕过检查。返回状态需与既有认证契约一致；阅读层能识别登录失效，不能把登录页 HTML 当 PDF。

无权访问时不得返回文件名、大小或内容；缺失、无效索引、路径伪造和非法格式统一安全失败。业务权限撤销后新请求拒绝；已传至用户设备的字节不能承诺可远程收回。

### 3. PDF 和图片阅读

推荐新增 `pdfjs-dist`，按需加载、随站点部署其 worker 和所需字体/CMap 资源，读取同源受保护文件。PDF.js 提供 PDF 文档加载、页面渲染与移动端示例；本方案选用它避免将移动阅读能力完全交给外部浏览器处理。

仅渲染当前页及少量相邻页面；切页、缩放或关闭时取消过时任务，清理 canvas、worker/document 和 object URL，限制渲染像素与并发，避免将 20 MB 多页扫描件全部铺到内存。预览依赖按需加载，不增加普通项目表首屏 PDF 处理成本。内容接口验证 Range；若 Range 不可用则支持完整文件读取，不能无条件承诺首屏加载速度。

图片使用应用内阅读组件，支持缩放和拖动，限制缩放和内存使用。预览只读，不执行 PDF 脚本、嵌入附件或自动跳转外部链接。文件损坏/密码保护等无法展示时提供明确失败原因及授权下载，不承诺修复或解密。

PDF、图片均不送第三方在线预览服务。现有 MIME 校验继续作为安全边界，不新增 SVG/HTML 等主动内容格式。

### 4. 端侧交付

- 第一阶段落实现有 Web：电脑浏览器、Android Chrome、iOS Safari。手机浏览器须真机验证 PDF 多页阅读和图片缩放；桌面模拟视口只证明布局。
- 已有 WebView 壳若存在，可复用阅读界面，但需确认登录会话、同源文件请求、返回栈、worker 和下载行为，并单列真机验收。
- 独立原生安卓客户端在其实现阶段接入同一附件权限语义与受保护内容能力，复用既定移动鉴权。不得为了预览把令牌放入 URL、引入匿名文件地址或重开一套登录体系。未确认 API/认证前不承诺直接消费 Web 会话路由。

客户端实现不能凭空完成。本方案将 Web 可交付与客户端接入依赖分别记录；用户若要求原生客户端作为同一批次上线，则须先补齐客户端实施基线再执行该部分。

## Alternatives

- 只把响应改为 inline：改动较少，但不能证明所有目标手机/容器都有完整阅读能力，也会改变旧下载契约，因此不作为完整交付。
- 仅用 iframe/object：浏览器能力不同，加载失败与返回体验难统一；可用于比较验证，不作为验收替代。
- 第三方 Office/PDF 在线服务：涉及文件外发与额外服务依赖，当前格式无需此方案。
- 服务端逐页转图片：增加转换、缓存和清理负担，当前阶段不引入。

## Tests and Acceptance

L2 PHPUnit：PDF/JPEG/PNG 信息与 inline 响应；原下载仍 attachment；真实 MIME 校验；加工函/合同每个历史索引；旧单文件；多用户和不可见项目；字段或索引篡改；无文件/磁盘文件丢失；未登录与权限撤销；Range 的授权及字节范围。保留上传追加、状态条件、项目金额与合同只读回归。

L3 Vitest：每个入口打开正确文件；多合同/多文件不串档；PDF 页码边界/切页/缩放；图片控制；加载/失败/重试；快速切换文件不显示旧请求结果；关闭清理资源；返回保留未保存表单和新选文件；保存失败不生成成功预览入口；对账单和其他附件行为保持。模拟 PDF.js 的组件测试不能证明实际 PDF 渲染。

浏览器与真机：使用非敏感中文合同 PDF、扫描多页 PDF、20 MB 边界样例、长图/横图验证阅读、印章小字、旋转屏幕、弱网、重新登录和下载。有效支持样例必须全部通过，失败样例须出现可理解状态，越权用例全部拒绝。尺寸和浏览器版本逐项记录，不虚构生产使用率指标；上线后一周收集实际用户重试反馈。

## Delivery Sequence and Estimate

评审通过且基线可用后的人工工作量粗估：基线与接口/渲染验证 0.5–1 天；后端预览与 L2 0.5–1 天；前端阅读及各入口 1–2 天；组件回归、真实 PDF 与手机验证 1 天。Web 合计约 3–5 个工作日；不是工期承诺，不包含客户端从零建设、上线审批或未预见的基线冲突。原生接入在确认仓库/认证后独立估算。

## Migration and Rollback

不改上传数据结构，不批量迁移附件。实施使用经核实的集成基线和隔离工作区；当前未提交工作保持。回退预览入口、新接口和新增展示属性即可恢复原下载路径；保留所有原文件、数组索引、业务状态与下载地址。新依赖只在评审同意后加入锁文件，回退时随代码一并撤回。

## Reference Sources

- Laravel 13 Boost 文档：`response()->file()` 用于浏览器显示文件，`download()` 用于强制下载：https://github.com/laravel/docs/blob/13.x/responses.md
- PDF.js 官方入门与移动端示例：https://mozilla.github.io/pdf.js/getting_started/ 、https://github.com/mozilla/pdf.js/blob/master/examples/mobile-viewer/README.md
- PDF.js 官方常见问题（浏览器支持与 Range 行为）：https://github.com/mozilla/pdf.js/wiki/Frequently-Asked-Questions

## Evidence Boundaries

本次只新增提案文件；OpenSpec 校验与现有代码门禁结果在交付时报告。没有新增预览实现、安装依赖、提交/合并、部署或真实用户阅读成功证据。实施后须分别记录每一层证据。

本轮实际检查：`composer openspec:validate` 15/15 通过，退出码 0；遥测域名不可达产生非阻断警告。`composer quality:gate` 的 OpenSpec 阶段通过，PHPUnit 共 61 项、6 通过、55 错误，错误为 SQLite 测试库缺少 `users.deleted_at`；门禁在该阶段停止，未执行 Vitest 与构建。未修改业务代码处理此基线不一致。日志：`/private/tmp/palantir-attachment-preview-quality-gate.log`。`git diff --check` 通过。


## Approved implementation update (2026-09-08)

- 用户批准实施，并明确要求附件按参考图做独立托盘。详情/编辑区显示有间距的文件卡片；表格保持原行高，使用“共 N 个附件”托盘入口，展开为文件卡片；手机托盘为底部卡片列表，点开文件后全屏阅读。参考图中的删除及上传额度不进入需求。
- 隔离开发位置：`/private/tmp/palantir-attachment-preview`，基线 `7dfb85a`，本地分支 `codex/contract-attachment-preview`。主工作区旧代码与既有未提交修改均未改动。
- 实施前只读核对线上相关文件：`AttachmentController`、`ProjectContractEditor`、`FieldControl` 与上述基线一致。线上确有 `StoredAttachment` 私有存储映射；新解析复用该映射，兼容 local 与云盘，不创建公开链接，也不更改上传规则。当前基线包含 `users.deleted_at` 迁移，原主工作区测试库缺列问题未出现在这个隔离基线。
- PDF.js 锁定 `pdfjs-dist@6.3.289`。PDF 主模块与 worker 按需加载，字体/CMap/WASM/ICC 资源随构建本地发布并带版本路径；不请求外部预览服务。
- 原下载接口保留 attachment 响应。新增 `/attachment-previews/...` JSON 信息和 `/attachment-content/...` 内联内容；预览对实际文件前缀做 MIME 检查。本地文件支持授权 Range；云端不支持范围读取时明确返回完整 200 和 `Accept-Ranges: none`。两类内容都 private/no-store/nosniff。
- 服务端增加 `attachment_previews` 派生描述，不替换旧 payload/display URL。原名从现存 `StoredAttachment` 批量读取；没有历史原名时显示“加工函附件 1.jpg”等类别序号，不显示内部哈希路径，也不编造原名。
- 历史多文件保留原数组索引，包括中间空槽。对账单及其他对象沿用原查看/下载；未引入删除按钮。
- 实际浏览器发现 Inertia 的返回处理会以 `preserveState: false` 重建页面。附件返回监听在应用初始化前注册，仅在阅读层打开且返回同一地址时消费事件，关闭阅读层并保留表单/待上传文件；普通导航不被拦截。该边界增加组件测试并经真实浏览器验证。
- 线上另外含账号设置、客户范围、列顺序等独立变更。发布时应将功能补丁叠加到再次核对的线上源代码后重新构建，不得整包替换为此较早基线的 `public/build`。本次不部署、不写线上业务数据。
