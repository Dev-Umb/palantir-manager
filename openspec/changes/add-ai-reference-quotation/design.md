## Scope

- 必须改变：追加参考报价独立入口、对话参数确认、参考价格推荐及按业务员采纳留档的设计。
- 必须保持：无业务状态、无审批；用户提供优先；历史价确认；每日网价单独确认；参考性质；既有项目/合同/财务/AI能力。
- 允许隐藏：详细输入字段、计算依据、长来源信息可展开。
- 必须可见：规格配置、计价数量与单位、税运范围、来源、网价日期、未确认项、文件性质、归属。
- 禁止推断：模糊图片数字、单重/总重、自动成交/合同回写、历史价适用性、网价替代规则、管理员跨用户权限。

## UI decisions

桌面沿用蓝灰色平台侧栏；参考报价下有“生成报价”“我的报价档案”。生成页左侧对话与附件，中间紧邻历史价与网价确认，右侧纸面预览。移动端分为对话/报价标签，确认卡保持在对话中，预览和留档不丢失。

选择项目可选，显示客户和标段，未匹配也可独立填写。上传不代表识别成功，低可信字段先追问。先确认历史价，再获取网价，网价确认显示日期、市场、品种、规格、品牌、价格、税口径、来源与发布时间；查不到明确显示原因和手动输入，不静默替代。用户手动价格也必须有口径，材料价变化会取消该价格及当前参数确认并使旧计算不再可采纳。确定数量、单位、税运范围后点击“确认参数并计算”。

网价和历史价确认属于数据确认，不是报价业务状态。不会添加草稿、审批、已发送、成交、作废状态或看板。

采纳按钮显示报价名、当前业务员、项目（可空）与快照内容，点击后归档。档案只有归属、时间、项目、报价名、金额及查看/下载/重新报价操作，没有状态字段。重新报价复制参数进入新的工作区，并重新确认价格；禁止原快照随实时行情变化。

## Preserved capability map

| 既有能力/要求 | 新承载位置 | 验证 |
|---|---|---|
| 现有AI数据查询、对话历史、上传合同 | 原页面原入口 | 本轮不修改生产组件或路由 |
| 无状态的临时参考报价 | 对话工作区与参考报价预览 | 原型无业务状态筛选或审批 |
| 文本/图片输入、缺参追问 | 左侧对话及附件预览 | 示例资料、文本输入、模糊值演示 |
| 用户价格优先 | 价格卡手动输入 | 覆盖推荐价后重新确认 |
| 历史报价参考 | 独立历史价格卡 | 显示规格、历史日期、来源与确认按钮 |
| 网价单独确认 | 独立网价卡 | 未确认不得计算；取价日期变更重新确认 |
| 金额预览和导出 | 右侧预览及文件操作 | 示例计算、表格下载/打印、缺重量单价模式 |
| 业务员独立留档 | 我的报价档案 | 两个演示视角列表互不混合，服务端权限尚未实施 |
| 历史报价不被新输入覆盖 | 采纳快照只读详情 | 新工作区改价格后旧快照不变 |

## Prototype evidence boundary

独立HTML原型内价格和数据均为示例，联网查价及AI回复是交互演示。上传只在本页预览，不传服务器，不执行OCR。采纳快照仅保存在页面内存，刷新消失；演示视角切换不是实际登录或鉴权。CSV和浏览器打印为原型导出，正式Excel/PDF后续实现。原型允许按页检查窄屏布局。原型不能作为服务端权限、真实AI/搜索或生产归档成功的证据。

## Later implementation considerations

复用现有Laravel/Inertia及附件/AI能力，但报价上下文与权限独立。服务端确定归属并检查每次查看/下载/复用；客户端不可提交其他业务员为owner。冻结来源数值及时间、数量、费项、舍入和最终文件；历史检索只在允许范围内，跨业务员推荐需另定授权。留档写入需幂等、防重复点击、失败后保留输入。按吨/套/吨日及含税/未税分支计算；金融条件只是输入说明，不擅自生成资金成本。PDF/Excel导出及图纸吨重计算在实现阶段定义验证。

## Alternatives

仅对话无法让用户清楚核对输入和金额；纯表单无法承接图片与追问，采用对话加可检查参数及预览。直接修改现有AI页会扩大范围，本轮使用独立原型，后续只追加入口。将报价变成合同或审批对象与用户要求冲突，不采用。

## Validation and rollback

原型测试确认门槛、参数变化、单价模式、归属隔离、冻结快照、空档案、失败场景、键盘弹窗和响应式；严格OpenSpec及项目质量门检查分开报告。删除本change及outputs/reference-quote-ui-20261005即可撤销设计交付，无生产数据迁移。

## Approved apply scope (2026-10-05)

用户明确授权开发及部署独立模块。第一版独立 /quotations 入口；admin、business、business_manager 可进入，档案始终仅当前账号可读，无管理员跨账号例外。项目关联继续使用当前项目权限。会话仅请求上下文，不持久化临时草稿；采纳时新增 quotation_archives 表，服务端冻结计算快照。价格确认使用服务端签名，与品种/规格/税运/单位/日期绑定；参数变化失效。计算后签名预览，采纳只接受本账号签名结果，不能信任客户端金额。采纳 UUID 幂等并检查内容一致。
支持吨、套、吨日（租赁）及只出单价；零价格允许，未知数量不推为0。基价为用户分别确认的加工费与材料基价之和，税口径明确，额外税前费用单列；不从图纸猜重量或擅算融资费用。元金额按分四舍五入，吨数量至三位小数，天数整数。Excel兼容CSV与可打印HTML导出，无新增依赖。
历史推荐来自当前账号已采纳快照，不跨账号读取。联网搜索使用公开钢材信息并展示原始链接、原文、时间、品种规格税口径，查不到匹配日期/规格不返回可采纳价格；用户确认后才计算，不允许AI记忆报价。
正式图片及PDF随本次请求发给平台已配置AI，临时附件不归档。AI只返回候选字段与追问，不具有业务数据写入或确认权限。单独Vite入口及quotation-build目录，通过Inertia独立根视图加载；不覆盖平台app bundle。部署只增量追加路由与导航，保留原静态资源，仅运行本模块迁移，不运行元数据seed或全量rsync删除。

AI配置直接复用ai.default、对应provider.models.text和ai.request_timeout，与线上AI助手一致；报价模块不引入独立密钥、模型或provider覆盖。

归档外键在账号删除时置空，保留采纳快照中原业务员信息；不改变既有账号删除行为，也不将孤立档案开放给其他账号。

## Approved conversational card revision

The user requested a single chat window on 2026-10-05. Replace side-by-side form panels with inline parameter, price-confirmation, and quote-result cards, followed by the conversation composer. Preserve all public fields, signatures, calculation, exports, role access, and immutable personal archives. Confirmed parameter groups may collapse but remain editable. No prices or missing tax/quantity facts may be inferred or confirmed automatically. Empty AI results must fail visibly and not be added as blank conversation messages. Validation messages must name missing information in Chinese.

Aimon uses the same configured provider credentials, URL, model defaults and request timeout as the platform assistant. The quotation-only SDK gateway adapts structured non-streaming requests to chat/completions, as used by the existing procurement gateway; attachments retain their actual bytes. No global provider configuration is replaced.

The final user revision removes the outer title, banner and toolbar from the chat workspace. Personal quotation search, list, pagination and new quote actions move to the collapsible left menu. Main-platform routes remain accessible under a foldable platform menu. Necessary reference-only and archive notices remain in the composer, confirmation cards and quote document. No stored fields or permissions change.

## Platform shell restoration and upstream failure repair

The user requires the existing dashboard shell and visual style. Reuse the production Layout component (including platform navigation, account settings/password reminder, logout and mobile controls) and the existing production stylesheet; scope every quotation rule below q-shell. The separate quotation menu remains inside the platform workspace. Main platform assets and unrelated source remain untouched. Cross-module navigation uses normal full visits because the isolated quote bundle only resolves quotation pages. Retry at most once for transient upstream HTTP 429/5xx errors within the existing shared timeout; never switch providers/models or automatically confirm a price. Keep failed user messages visible and all input available to retry.

CSV export fetches the signed preview as a Blob and downloads it without navigating away from the chat. Export failure retains the preview and dialogue for retry.
