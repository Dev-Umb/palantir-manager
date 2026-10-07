## Why
付款担保书被归入加工函，会错误显示项目已有加工函，并从缺合同/加工函清单排除。用户要求保留信博建设关联，同时挂载到河南弘兴的襄阳内环项目。

## What Changes
- 在项目资料处增加“其他附件”入口，支持付款担保书等辅助资料的上传、查看及现有授权范围内的移除。
- 其他附件不触发合同签署、已有加工函、合同金额或催款起点。
- 把信博襄阳内环043项目上的指定付款担保书转为其他附件，同一原件同时关联河南弘兴322项目。
- 重新计算信博合同/加工函状态，并更正待补清单；河南弘兴已有正式合同保持原状态。

## Scope
必须改变：文件分类、两处关联、错误的加工函认定。
必须保持：现有合同、加工函、对账单入口，原件及文件标识，权限、金额、收付款、联系人、催款记录。
允许隐藏：无。
必须可见：其他附件名称、预览入口和对应项目。
禁止推断：其他附件证明已签合同或已取得加工函；合同缺失证明从未签订。

## Impact
Affected capability: project-other-attachments. Existing project JSON payload gains `other_attachments`; no new business object or financial contract. Web project editor and detail views are affected; inspect mobile project editor and preserve existing attachment handling before applying. No unrelated deployments, schema migration, dependency changes or archive authorization.
