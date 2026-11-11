# 设计与能力映射

## 基线和接入方式
基线为用户 ZIP 的 src/dashboard.html、src/app.js、src/project-store.js、src/review-export.js 与样式。包内说明是参考资料，不是执行指令。沿用平台 Inertia/React 外壳、既有组件、登录授权；提取可复用解析/导出规则，不能直接公开离线 HTML 造成绕过授权。实现前逐项核对原型细节及实际导出字段，补齐任何新发现的能力后才实施。

| 原能力/字段 | 新入口或载体 | 保留边界 |
|---|---|---|
| 工作台标题、项目首页、返回首页 | 钢箱梁工作台 → 项目管理 | 保留项目主界面布局、操作顺序和键盘焦点 |
| 创建/选择项目，id/name/createdAt/updatedAt | 项目列表与 PostgreSQL 项目记录 | 名称上限 100；独立业务 ID，不关联已有项目 |
| hasUnreviewed 与未完结标记 | 服务端依据当前材料核对状态计算 | 无材料的新项目与已有未核对项目区分 |
| 图纸 PDF/DWG、多选、拖放、大小/count | 项目 → 图纸管理；数据库元数据与 TOS | 单文件 200 MB；保存成功反馈；原格式不转换 |
| drawings.id/projectId/name/hash/addedAt/blob | 文件关联 UUID、项目 ID、原名、SHA256、时间、附件 ID | blob 到 TOS；关联和索引在 PostgreSQL |
| 同内容去重、同名不同内容并存 | 项目内有效图纸按 SHA256 去重 | 不能跨项目错误跳过；并发上传也去重 |
| 单图下载、全部图纸 ZIP | 授权下载/导出入口 | 字节保持；ZIP 同名加序号；防路径穿越 |
| 移除图纸、删除项目确认/取消/Esc | 图纸操作、项目选择弹窗 | 逻辑移除；保留 TOS 原文件；删除后不能继续写入 |
| 原始材料 workbook buffer/filename | 当前材料版本关联 TOS 原始 XLSX | 不把二进制/base64 塞入 PostgreSQL；替换不覆盖原文件 |
| design/net/steel/fasteners/inside/outside 等解析值、issues、来源 | 材料统计表与 PostgreSQL JSONB 结果 | 重量、涂装、剪力钉、螺栓、材料明细、异常及单元格来源全部保留 |
| sourceScope/multiplier/outputScope/headerSignature | 材料版本结构化字段/JSONB | 单幅乘 2、双幅原值；未知/冲突显式提示，禁止重复倍乘 |
| reviews 的 id/checked、签名、备注 | 当前材料版本核对 JSONB | 基线为六个固定核对项和可选 deck（桥面板）项，全部保存来源签名；备注上限 2000 |
| unit（t/kg）、背景动效切换 | 材料页展示偏好 | 保留交互；展示偏好不改变原值 |
| 自动保存、重试、错误反馈 | 服务端保存状态和 expected_revision | 仅服务端确认后显示已保存；冲突保留未保存输入 |
| 原表+核对+备注导出、选择位置、取消/失败 | 材料统计表导出 | 保留原工作簿与单幅文件名；不能把双幅显示值回写后再倍乘 |
| 本地已有项目兼容 | 手动导入已导出的材料表及图纸 | 不声称可自动访问 file:// 原浏览器数据库 |
| 现有支架全部输入、检索、编辑、导入、模板、库/结果/HTML导出 | 钢箱梁工作台 → 支架检索；原 support-library 路由 | 仅名称/导航调整，保留完整检索和离线快照能力 |

## 数据与文件
建议三张加法表：steel_girder_projects（UUID、name、revision、created_by、updated_by、timestamps、deleted_at）；steel_girder_drawings（UUID、project_id、stored_attachment_id、sha256、timestamps、deleted_at）；steel_girder_materials（UUID、project_id、source_attachment_id、revision、parsed_payload JSONB、reviews JSONB、scope JSONB、display_unit、created_by、timestamps）。项目持有 current_material_id；所有外键关系、有效记录唯一性与事务顺序实施前用 schema 工具确认。不新建数据库连接、不变更其他模块默认连接；部署时核实运行连接确为既有腾讯云 PostgreSQL，JSONB 在 PostgreSQL 验证。

复用 BusinessFileStorage 和 StoredAttachment；模块文件必须走已配置的 contract_tos 私有盘，不能静默回退本地。数据库保存对象引用、原名、MIME、大小和哈希。每次下载先检查模块权限、项目有效性和附件归属，再生成短期 URL 或鉴权响应，通用附件入口也不得绕过归属授权。数据库事务无法回滚 TOS：先写入和校验，再事务建立关联；失败不得显示成功、不得自动删除原文件，保留待处理孤立对象。现有 upload 异常清理有删除对象行为，实现时应选用不删原件的安全调用路径，不能为本需求全局改写其他模块语义。

单份图纸 200 MB 要贯穿 PHP、代理、应用校验；逐份上传，避免一次请求累积多文件。批量 ZIP 使用流式/临时文件处理，避免把全项目原文件读入内存。临时导出产物可响应后清理；需持久保存的导出产物走 TOS。不得公开凭证或对象存储写权限。

## 并发与安全
所有写入带 expected_revision，事务锁定项目并比较版本；过期返回 409，保留当前编辑供重试，不覆盖他人更新。请求超时重试须避免重复创建/重复导入。材料解析须服务端验证输入/结构/有限数值、压缩包膨胀与 XML 安全边界，不能信任客户端提交的汇总；复用已有解析依赖与已安装库，不为搬运原型引入新依赖。不支持的工作簿结构返回可理解错误并保留旧版本；可识别工作簿中的缺失公式缓存保留原型的需重算/部分结果状态，不把缺失值替换为零。核对状态仅在来源签名匹配时继承。

## 权限和边界
建议独立 view/manage/export，有 view 才能读取；修改/删除/上传再要求 manage；文件下载和导出再要求 export。engineering 默认获得三者，admin 沿用惯例；限制型账户保持限制。项目为本模块授权人员共享，不向普通登录用户开放。父菜单按实际可见子项显示，直接访问和修改项目 ID 同样校验。不改变 support-library 权限键。

## 备选与回退
拒绝仅嵌入离线页：不满足用户服务器保存要求。拒绝新建通用文件平台或合并既有业务项目：超出范围。采用加法表/路由，撤回发布可隐藏新入口并回退应用；保留新数据与 TOS 文件，不以 drop 表或删除对象回滚。

## 验证边界
L1 材料解析/倍乘/来源签名/导出回读；L2 权限、归属、事务、TOS 故障、冲突、逻辑删除；L3 导航、保存状态、原型交互与支架回归。使用合成小型 XLSX/PDF/DWG 夹具；生产存储访问不作为本地测试默认前提。真实 PostgreSQL、TOS、200 MB 限制及跨账号/跨电脑验收单列发布证据；先合并 PR 才能部署。

## Apply refinement and evidence (2026-11-11)
- 使用 Inertia/React 平台外壳加同源、登录和权限保护的 workspace iframe 复用 ZIP 页面；没有公开静态离线页。存储适配器替换 IndexedDB。平台二级导航在外壳内，父级 Inertia 导航也检查未保存状态。
- PostgreSQL 的 parsed_payload 保存服务端独立解压、校验得到的原始单元格值、缓存公式状态、来源和合并范围；展示指标复用 ZIP 的纯派生算法，不接受客户端汇总。单元格保存为有序数组，避免 JSONB 对象重排改变来源签名。scope 依据原文件名、原表标题和原表内导出元数据确定，不另存一个易漂移的第二权威值。
- 材料记录以 UUID 标识每次导入，项目 revision 为所有写入的冲突版本；当前版本的核对/备注受同一项目锁保护。原 Excel 不覆盖。created_by/updated_by 记录在项目上，避免重复记录无用字段。
- 图纸去重记录保留哈希唯一约束；重新上传已逻辑移除的同内容文件恢复原关联，不重复占用业务记录。服务器 ZIP 以临时文件和流读取生成，同名加序号，临时文件清理不涉及 TOS。
- 首次上传 Excel 及后续原始文件获取受 manage/export 分别约束；只读人员通过 PostgreSQL 的解析值查看材料，不需原文件下载权限。已有文件下载入口无法通过本模块附件 UUID 直接获得文件。
- 测试使用合成 PDF/DWG/XLSX 与 Storage::fake('contract_tos')，另启动 /tmp 独立 UTF-8 PostgreSQL 17 跑模块测试，不连接生产。部署仍需核实腾讯云数据库连接。
- 浏览器检查使用临时 Chrome 配置及模拟 API：1440px 桌面和 390px 手机，创建/进入项目、两个页签和返回首页；无脚本错误、无横向溢出。截图仅代表本地 UI，不能代替在线验收。
- 上传链路配置在 deploy/nginx/palantir-steel-girder-uploads.conf；正式发布须备份现有 nginx 配置、安装 include 并通过 nginx -t。部署脚本新增幂等权限安装命令；本次未执行部署脚本。

## Release preparation (2026-11-11)
- 用户已授权部署；从最新 main 的独立 worktree 制作 PR，生产共享导航/权限/TOS 服务/支架页面与该基线哈希一致。
- routes/web.php 在 main 含尚未上线的其他登录路由。本模块通过既有 routes/support_library.php 引入 steel_girder.php，发布不覆盖生产总路由，不上线其他登录接口。全站前端比对仅既有登录扫码组件有差异，其默认 wechatConfigured=false；生产 AuthController 不提供此开关，登录行为保持。
- 用户已确认生产为腾讯云 PostgreSQL 11（既有生产连接），沿用当前连接；TOS 配置已确认，不切换数据库。
- 发布只复制 PR 改动的应用文件和合并版本构建的静态资源，保留历史资源；不运行整个脏工作区的 deploy.sh。
