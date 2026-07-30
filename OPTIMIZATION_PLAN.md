# 鑫源昌智造中枢 — 优化改造技术方案

> 本方案供执行方（GPT）按阶段实施。每一项改动都必须遵守第 6 章的回归边界与约束。
> 技术栈：PHP 8.4 / Laravel 13 / Inertia v3 / React 19 / Tailwind CSS v4 / Reverb / PHPUnit 12 / Vitest 4。

---

## 0. 项目现状摘要

- 后端：`app/Http/Controllers`（13）、`app/Actions`（13）、`app/Models`（9）、`app/Support`（4，含 `ObjectRelations.php` 1176 行）、`app/Ai`、`app/Mcp`。
- 前端：`resources/js/Pages`（Dashboard、Ontology、Requisitions、TeamLogs、Rbac、Ai、Notifications、Auth），共享组件在 `resources/js/Components`。
- 样式：`resources/css/app.css` 1133 行手写 CSS + CSS 变量；Tailwind v4 已接入但组件几乎未使用工具类。
- 公开入口（无需登录，受 throttle/签名保护）：`/purchase-request*`、`/team-log/public`（signed）。
- 核心写流程：`CreateObjectRecord`、`AdvanceProjectWorkflow`、`SyncProjectFinance`、`SyncProjectNotifications`、`SyncXycMetadata`、AI 写提案确认（`ConfirmAiWriteProposal`）。
- 文案层：`resources/js/businessLanguage.js` 在前端运行时把"本体/对象/主档"等术语替换为业务语言。

---

## 1. 改造目标与优先级

| 阶段 | 内容 | 风险 | 优先级 |
|---|---|---|---|
| P0 | 回归基线固化（先补测试，不改功能） | 低 | 最高 |
| P1 | 后端结构拆分（巨型类、权限粒度） | 中 | 高 |
| P2 | 导航与权限 key 化（去掉中文字面量匹配） | 中 | 高 |
| P3 | 设计令牌统一 + Tailwind 收敛 + 页头/色彩纪律（第 6 章 UI 走查任务） | 低 | 中 |
| P4 | UX 增强（批量审批、表格可读性专项 6.7、逐页布局修正、AI hook 抽取） | 中 | 中 |

硬性原则：**P0 未完成前不得开始 P1–P4**。任何阶段不得改变外部可观察行为（URL、权限语义、数据结构、公开表单契约），除非本方案明确列出并经确认。

---

## 2. P0 — 回归基线固化（先行）

### 2.1 现有测试基线

- 后端：`php artisan test --compact`（现有 Feature：AI 信任契约、AI 写提案、Dashboard 任务中心、全链路 Seeder、库存退役）。
- 前端：`npm run test:ui`（Vitest，已有 ObjectGrid、Layout、Dashboard、Ontology、Ai 等组件测试）。

执行方第一步：在干净检出上跑通两条基线并记录通过数，作为后续每阶段的对照。

### 2.2 需补充的 Feature 测试（不改生产代码）

在 `tests/Feature/` 新增，全部使用工厂/Seeder 造数，sqlite 测试库：

1. `OntologyRecordCrudTest` — 本体记录的新建/编辑/删除/CSV 导出，含字段校验失败路径、附件下载授权。
2. `RequisitionFlowTest` — 采购申请提交（登录态 + 公开态）、审批通过、审批驳回，断言 `AdvanceProjectWorkflow` 被触发后的记录状态与通知生成。
3. `PublicTeamLogTest` — 签名 URL 有效/过期/篡改三种情况，throttle 生效。
4. `RbacPermissionTest` — 无权限用户访问 `/objects`、`/admin/rbac`、`/procurement/approvals` 返回 403；横向越权（A 角色的记录 B 角色不可见，走 `ProjectVisibility`）。
5. `NavigationContractTest` — 登录后 Inertia shared props 中 `nav` 的结构快照（label/href/visible/children/new_task_count），为 P2 改造提供对照。

### 2.3 E2E 回归的边界与约束（重点）

**环境约束**

- E2E/Feature 测试一律使用独立 sqlite 测试库（`phpunit.xml` 已配置则沿用），**禁止连接开发库或生产库**。
- 测试数据一律由 Factory/Seeder 现场创建并在事务内回滚；不得依赖开发库里已有的业务记录。
- Reverb/Echo 在测试中 mock（`Broadcast::fake()` 或 Echo mock），不发起真实 WebSocket 连接。
- AI 相关测试不得调用真实模型；沿用现有 fake/契约测试方式。

**冻结清单（改造期间不得变更的外部契约）**

| 类别 | 冻结内容 |
|---|---|
| 公开路由 | `/purchase-request`（GET/POST）、`/purchase-request/material-options`、`/team-log/public`（signed, GET/POST）的 URL、方法、请求字段名、throttle 名称、签名参数 |
| 登录态路由 | 所有现有命名路由的 URL 与 name；重构控制器只允许改 class 归属，不允许改 URL/name |
| 数据流 | `CreateObjectRecord`、`AdvanceProjectWorkflow`（合同激活/图纸释放/工单完成/阶段推进/财务同步）、`SyncProjectFinance`、`SyncProjectNotifications`、`SyncXycMetadata` 的触发时机、写入的 `object_records.payload` 字段结构 |
| AI 契约 | `AiRun`/`AiRunEvent` 的事件流格式、写提案 confirm/reject 语义、轮询端点 `/ai/runs/{run}` 与 `/ai/runs/{run}/events` 的响应结构 |
| 权限语义 | 现有权限 key（`dashboard.view`、`requisition.create`、`object.*.*`、`rbac.manage`、`ai.harness.view`）的含以不变；新增权限只允许增量 |
| Inertia props | shared props（`auth`、`nav`、`flash`、`notificationUnreadCount`）字段名不变；页面 props 字段只增不删 |
| 数据库 | 不做破坏性 migration；只允许新增表/列，且新列必须可空或有默认值。禁止 rename/drop 现有列 |
| 前端公开行为 | 登录/注册页、公开采购申请表单、公开报工表单的字段、校验与成功跳转不变 |

**每阶段完成判定（DoD）**

1. `php artisan test --compact` 全绿（含 P0 新增用例）。
2. `npm run test:ui` 全绿。
3. `vendor/bin/pint --format agent` 无 diff 或仅本阶段改动文件的 diff。
4. `npm run build` 成功。
5. 手工冒烟清单（见 6.3）逐项通过。

---

## 3. P1 — 后端结构拆分

### 3.1 拆分 `OntologyController`（786 行 → 3 个控制器）

- `ObjectController`：`index`（对象列表/记录网格）、`exportCsv`
- `RecordController`：`store`（移到此处或并入 ObjectController，按路由归属定）、`update`、`destroy`
- 路由 URL 与 name 全部保持不变，只改 `[Controller::class, 'method']` 指向。
- 私有辅助方法随职责迁移；共用逻辑下沉到 `app/Support` 或对应 Action。

### 3.2 拆分 `App\Support\ObjectRelations`（1176 行）

按职责拆为同命名空间下的多个协作类，保留 `ObjectRelations` 作为 Facade 式入口（委托给新类），避免一次性改所有调用点：

- `RelationResolver` — 关系定义解析、目标对象定位
- `RelationQuery` — 选项查询（供 `RelationOptionsController`）
- `RelationWriter` — 写入/校验关系字段

约束：公开方法签名不变；拆分时逐方法移动，不重写逻辑。

### 3.3 权限粒度修正

- 新增权限 `requisition.approve`，审批相关路由（`/procurement/approvals`、`/requests/{record}/approve|reject`）从 `object.requisition.update` 切换到新权限；通过 Seeder 把新权限授予现有拥有 `object.requisition.update` 的角色，保证行为等价（用测试证明）。
- 核对 `RelationOptionsController` 与 `/objects/*` 是否缺少权限中间件；如需新增，新增权限必须默认授予当前所有能访问这些页面的角色（以现有可见性为准，不得收紧任何人的访问）。

### 3.4 Inertia 复杂 props 固化

- Dashboard 页面 props（`workSummary`、`workItems`、`boards`、`projectFlows` 等 10 项）抽出 `App\Http\Resources` 或 `Support\DashboardPayload` 组装层，并加结构断言测试。只重组装位置，不改字段与取值。

---

## 4. P2 — 导航与权限 key 化

现状问题：`Layout.jsx` 用中文字面量（`'通知中心'`、`'采购OA审批'`）匹配图标/徽标/移动端优先级，label 一改就静默失效。

改造：

1. 后端 nav 组装处为每个一级项增加稳定 `key`（如 `dashboard`、`notifications`、`requisition-create`、`approvals`、`team-log`、`ontology`、`ai`、`rbac`），`label` 保持现状。
2. `Layout.jsx` 改为按 `key` 映射图标与徽标，中文 label 匹配逻辑删除；移动端优先级表改用 key 列表。
3. 同步更新 `Layout.test.jsx` 与 P0 的 `NavigationContractTest` 快照。
4. `businessLanguage.js`：本阶段**保留**（文案下沉是独立事项，见第 7 章"明确不做"），仅禁止在 P2 中新增替换规则。

---

## 5. P3 — 设计令牌统一

1. 把 `:root` 中的 CSS 变量迁入 `@theme`（Tailwind v4 令牌），保留同名变量别名一个阶段，组件逐步迁移到工具类后再删别名。
2. 统一字体：`@theme` 与 `:root` 目前分别是 Instrument Sans 和 Inter，二选一（建议保留 Inter 栈），删除另一处。
3. 令牌扩展：radius（sm/md/lg）、shadow（sm/md）、spacing 沿用 Tailwind 默认刻度；语义色 `steel/mint/amber/red` 映射为 Tailwind 颜色令牌。
4. 组件迁移顺序：`Layout` → `Dashboard` → `ObjectGrid` → 表单类组件 → Ai 页。每迁一个页面跑一次对应 Vitest 与手工冒烟。
5. 视觉修正（顺带、低风险）：提高侧栏与内容区对比；给 Dashboard 首要待办区增加视觉权重（色彩锚点）；按钮 disabled/loading 状态规范化。

约束：不改布局结构、不删 DOM 节点层级（测试依赖 class 的选择器同步更新）。

### 5.4 页头统一与色彩纪律（2026-07-30 全页面实测截图走查结论）

以下来自对 8 个页面（经营大盘、业务资料、采购审批、提交采购申请、现场报工、RBAC、通知中心、AI 助手）实际运行截图的走查，作为 P3 的具体执行任务。

**5.4.1 统一全站页头组件（最高优先级）**

现状：各页 eyebrow/H1 来源随意（"全厂总览/待审批/注册用户/合同与回款"等 5 种规格），业务资料页无页头；"管理员视图"胶囊孤立漂浮在各页右上角，位置不一，且与侧栏底部用户卡片的角色信息重复。

任务：
- 在 `Layout.jsx` 的 `workspace-head` 固化两级页头规范：eyebrow = 模块名（与侧栏一级导航 label 一致），H1 = 页面名；所有页面通过 `title`/`eyebrow` props 传入，不允许页面自行渲染页头。
- 移除游离的 `.role-strip` 胶囊；角色信息只保留在侧栏用户卡片。若确需页内提示，并入页头右侧固定位置。
- 业务资料页（Ontology）接入页头：eyebrow="业务资料"，H1=当前对象显示名（如"项目资料"）。
- 更新 `Layout.test.jsx` 与各页面测试。

**5.4.2 卡片边界与操作色彩纪律**

现状：卡片与背景对比不足（`#f5f7f9` 底 + 白卡 + 极浅 1px 边框 + 近零阴影），区块漂浮感；行内操作多色实底按钮并排（审批页"通过申请"绿底/"驳回"黄底，业务资料行尾"查看/编辑/删除"蓝蓝红色块）。

任务：
- 卡片边框色加深（如 `--line` 由 `#e3e7ec` 调到 `#d6dde5`）并/或给 `.surface` 一档更实的阴影；以截图对比确认卡片在灰底上清晰可辨。
- 操作按钮规范：每行/每卡只保留 1 个主操作（文字按钮），其余收进"···"溢出菜单；危险操作（删除/驳回）只用红色文字样式，禁用实色底；通过/确认类主操作统一用主色（steel），不用绿色实底。
- 规范落地为 `Components/RowActions.jsx` 之类的共享组件，审批页与业务资料表格复用。

**5.4.3 命名一致性**

- "数据分析助手"（AI 页头）与"AI 数据助手"（侧栏）统一为一个名字。
- 通知中心页面：eyebrow/H1 与侧栏统一为"通知中心"，去掉"合同与回款 / 站内通知"混用。
- 经营大盘看板内部标题"经营大盘"与页面 H1 重名，看板标题改为"项目流转"。

## 6. P4 — UX 增强

1. **批量审批**：`Approvals.jsx` 增加行选择 + 批量同意/驳回；后端新增 `POST /requests/bulk-approve`、`/requests/bulk-reject`（新路由，不动旧路由），逐条复用现有 approve/reject 的 Action 与事务语义，单条失败不影响其他条且返回明细。新增权限沿用 `requisition.approve`。
2. **导航展开**：侧栏父项支持"展开/收起"与"跳转"分离（箭头按钮展开，点击文本跳转）。
3. **移动端导航**：后端 nav 项下发 `mobile_priority`（数字）替代前端硬编码表，最多取 4 项的逻辑保留在前端。
4. **AI 页重构**：`Pages/Ai/Index.jsx`（490 行）抽取 `useAiRun(runId)` hook（轮询 + Echo 订阅 + `applyRunEvent`），连接中断时给出可见的重连提示；`Artifacts.jsx` 维持 lazy。事件契约不变。
5. **表格能力（可选项，确认后再做）**：`ObjectGrid` 增加列宽/列显隐的本地持久化已有则复用 `objectGridColumnState.js`；不做服务端分页改造（超出本方案范围）。

### 6.6 逐页布局修正（2026-07-30 截图走查结论）

**6.6.1 业务资料页（Ontology）— 问题最多，优先改**

> v2 进展（2026-07-30 第二轮走查确认已完成）：页头接入、工具条归位（搜索 placeholder 化、每页/排序/方向内联、应用/导出/新建同组）、对象切换移出侧栏改为页内 Tabs、操作列收敛为"查看 + ···"、深色侧栏。剩余问题见 6.7（表格专项）与 6.8。

- ~~顶部工具条重排~~（v2 已完成）。
- ~~对象切换移出侧栏~~（v2 已完成，Tabs 溢出处理见 6.8）。
- ~~"+ 新建"按钮归位~~（v2 已完成）。
- 修复提示与实际不符：见 6.7.5。

**6.6.2 经营大盘**

- 顶部三张统计卡压扁为紧凑 KPI 条（当前卡高内容只有一行，留白过多）。
- "采购大盘 / 财务大盘"看板指标少留白多，改为一行式指标条或合并为单卡双列。
- 项目流转节点区保持不变（当前全站最好，作为其他区块的参考基准）。

**6.6.3 采购审批页**

- 申请卡片改为"左侧信息区（编号+标题+备注 / 字段两列）+ 右侧操作区"的横向布局，替代当前四列等大网格导致的字段过度分散。
- "待审批"区增加视觉强调（色彩锚点），"已处理"区降级弱化（折叠或更浅色）。

**6.6.4 现场报工 / 提交采购申请**

- 文件上传控件自定义样式并中文化（当前是浏览器原生 "Choose File / No file chosen"）。
- "手机端 · 一分钟完成"文案按断点显示，桌面端隐藏或改写。
- 这两页表单规范（字段分组、辅助文案、主按钮）抽象为共享表单规范，作为 5.4 之后其他页的参照模板。

**6.6.5 通知中心 / AI 助手**

- 通知中心空态精简：去掉"返回经营大盘"按钮，缩小空态区高度；为后续通知类型预留分类 Tab 结构。
- AI 页空态增加历史会话入口提示（当前历史藏在汉堡菜单，无任何可见线索）；宽屏下给聊天列明确的栏边界。

### 6.7 表格可读性专项（2026-07-30 第二轮走查，最高优先）

> 背景：第一轮改版后骨架已正确（页头、对象 Tabs、工具条归位、操作列收敛），但 `ObjectGrid` 表格本身"看表费劲"。本节给出表格显示的完整规范，适用于 `ObjectGrid` 及全站所有数据表格（含 Dashboard 最近项目表）。改动仅限表现层，不得变更数据接口与双击编辑等既有交互。

**6.7.1 列宽策略（费劲的根因）**

现状：列被均分拉伸填满容器（5 列均分 ~1500px），短文本列空荡、关系列被截断，视线长距离横扫。

规范：
- 表格改为 `table-layout: auto`，列宽按内容类型分配，不再强制 `width: 100%` 均分；总宽超出容器时容器横向滚动。
- 字段类型 → 列宽映射（作为 `ObjectGrid` 的默认列宽规则）：
  - 编号/编码列（如 XYC-…）：180–200px，等宽字体（`.mono`）
  - 枚举/状态/单位/短标签列：100–140px
  - 数量/金额列：110–140px，**右对齐**，等宽数字（`font-variant-numeric: tabular-nums`）
  - 普通文本列（名称/标题）：min 200px，max 320px，超出 ellipsis + title tooltip
  - 关系/长文本列（如关联项目）：min 280px，允许最宽，超出 ellipsis + tooltip
  - 操作列：固定 120px，右对齐，冻结在横向滚动右侧（sticky right，白底+左阴影分隔）
- 已有的列宽拖拽/本地持久化（`objectGridColumnState.js`）保留，用户手动调整优先于默认规则。

**6.7.2 行级可读性**

- 行高压至 44px（当前偏大且内容只有一行小字）。
- 行底加分隔线 `#e8edf2`；行 hover 底色 `#f6f9fc`；数据行 >7 行时加斑马纹（偶数行 `#fafcfd`）。
- 空值统一渲染浅灰 "—"（`--muted`），不留白，区分"无值"与"未加载"。
- 文本 ellipsis 处必须可查看完整内容（title 或 hover 浮层）。

**6.7.3 表头**

- 列宽拖拽手柄默认透明，仅 hover 表头单元格时显示，避免与行分隔线视觉错位。
- 表头底色 `#f7fafc`、文字 `--muted` 12px 600 字重，与数据区形成层级。
- 支持排序的列表头加排序指示图标（当前排序入口只在工具条，列头不可点）。

**6.7.4 操作列**

- 行主操作（查看）由实底蓝按钮降级为钢蓝色文字按钮，"···"溢出菜单不变；行数多时不出现"一列蓝色块"。
- 与 5.4.2 的色彩纪律共用 `RowActions` 组件。

**6.7.5 平铺提示修正**

- "已平铺全部 N 个字段，可左右滚动查看"按**实际渲染列数**动态生成；修复 v1 中"提示 27 字段但仅渲染 3 列"的提示与实际不符问题（先定位是平铺逻辑 bug 还是文案静态写死）。

**6.7.6 验收标准（表格专项 DoD）**

- 在 1440px 宽视口下，任意一行的所有单元格内容无需横向滚动即可读完（关系列允许 ellipsis 但有 tooltip）。
- 相邻列内容间距 ≤ 内容所需宽度 + 24px padding，无大段空列。
- 截图对比：改造前后同页截图附在提交说明中。

### 6.8 第二轮走查的其他问题

- **对象 Tabs 溢出**：14 个对象 Tab 单行放不下时末尾被硬截断（"开票记…"）。Tabs 容器改 `overflow-x: auto`（隐藏滚动条样式）或超出项收进"更多"下拉；当前激活 Tab 需保持在可视区内（激活时 scrollIntoView）。
- **深色侧栏分割线过亮**：侧栏底部用户卡片上方的分割线在新深色主题下是一条高亮白线，改为 `rgba(255,255,255,.08)` 级别的低透明度。
- **深色侧栏主题令牌化**：v2 深色侧栏色值需回收进 `--nav`/`--nav-2` 令牌（`:root` 里已有定义但 v1 未使用），避免硬编码 hex 散落各组件。
- **Dashboard 最近项目表**：与 6.7 同规范改造（列宽、行高、空值 "—"）。
- **审批页卡片**：执行 6.6.3 时，信息区字段同样遵守 6.7.1 的对齐规则（数值右对齐、空值 "—"）。

---

## 7. 明确不做（防止范围蔓延）

- 不做数据库 rename/drop，不改 `object_records.payload` 结构。
- 不替换 Inertia/不引入 SSR 架构变更，不升级依赖主版本。
- 不在本次把 `businessLanguage.js` 文案下沉到后端（单独立项）。
- 不引入 TypeScript 迁移、不换组件库、不加暗色模式（仅预留令牌结构）。
- 不改变公开表单的字段与交互（签名校验、throttle 行为）。

## 8. 执行顺序与提交规范

1. 按 P0 → P1 → P2 → P3 → P4 顺序，每个阶段独立提交（commit 前缀 `refactor:` / `feat:` / `style:`），便于回滚。
2. 每个阶段完成后输出：改动文件清单、测试结果（通过数对比基线）、手工冒烟结果。
3. 发现本方案与代码现状冲突时，停止并在交付说明中列出冲突点，不得自行改变冻结清单内容。

## 9. 手工冒烟清单（每阶段执行）

- [ ] 登录 / 退出 / 注册
- [ ] Dashboard 待办、风险卡、项目流转选择器、最近项目表渲染
- [ ] 侧栏导航高亮、子菜单、通知徽标；移动端底部导航 ≤760px
- [ ] 本体工作台：列表、新建、编辑、删除、CSV 导出、关系字段远程选项
- [ ] 提交采购申请（登录态 + 公开链接）、审批通过/驳回各一条
- [ ] 现场报工（签名链接）提交成功；过期签名被拒绝
- [ ] RBAC：角色权限勾选保存后对应用户菜单可见性变化
- [ ] AI 助手：发起 run、事件流渲染、提案确认/拒绝、取消
- [ ] 通知中心：列表、单条已读、全部已读
