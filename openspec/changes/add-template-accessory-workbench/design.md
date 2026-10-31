# 能力与字段映射

最新 demo progress-nav 三分类 -> /template-accessories 独立入口；原材 nav 保留在 /material-arrivals。
ProgressUI 行内编辑/保存修改/未保存清单/新增弹窗/恢复草稿 -> 原 UI，正式更改批量事务提交；草稿按用户隔离。
进度/发货/月/搜索/回收站 -> 原筛选及分组，小计合计不重复计重，回收站不记忆。
ProgressFiles -> 原表头、合并单元格、空值与零、状态、数量匹配和小计导出保留。
备份/恢复/撤销 -> 仅本模块，恢复带校验、版本与快照；不覆盖原材。
台车字段 project/subproject/type/estimated/shipped/intelligent/quantity/progress/shipping/orderDate/shipDate/note；台架去 intelligent；液压字段 project/type/hydraulic/screws/pins/jacks/shipped/progress/shipping/orderDate/shipDate/note/parent，均原样存储。
原 IndexedDB -> template_accessory_workspaces；localStorage draft/inline/filters/backup -> template_accessory_preferences；幂等回执 -> template_accessory_requests。
原演示 seed、日期补丁、带业务内容的内嵌模板不发布；仅发布清空数据后的格式模板。三份授权 Excel 单独从正式导入入口导入，保留未在文件出现的项目。
必须改变：独立性、云端持久化、权限与返回入口。必须保持：原 UI/字段/流程/原材数据。允许隐藏：无权操作。必须可见：错误及未保存状态、单位和返回入口。禁止推断：新增业务流程及额外历史数据导入。
