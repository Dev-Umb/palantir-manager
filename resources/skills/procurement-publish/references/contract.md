# JSON v1 契约

兼容现有 JSON，不要求 schema_version；提供时为整数 1。最大 200 条、序列化内容最大 2 MiB。完整结构校验失败时整批不发布；结构有效的记录逐条报告处理状态。返回字段路径便于修正。

顶层：title（标题）、date（YYYY-MM-DD）、window（原始采集窗口）、scope（采集范围）、items、coverage（每行4个字符串）、exclusions（每行2个字符串）、limitations（字符串列表）。后三项可为空列表。

每条必须含字符串：id、title、category、priority、unit、code、published、registration、deadline、quantity、material、contacts、judgment、terms、url、platform、status、issues、nature、subtype；invited 和 framework 必须为 JSON 布尔值；evidence 为文件名字符串列表。
id 在批次内唯一且非空；title、platform、code、nature 非空。priority 为 P1/P2/P3/待核实；status 为 纳入/待核实。url 必须 http(s)。其余未知文本可空，不补造。自然身份使用平台和采购编号，不使用可能共用的列表URL。缺少编号需人工核对，不能用编造编号上传。

nature 是采购方式，subtype 是采购性质，framework 是框架协议标志，不混用。issues 独立于 priority；纳入的 P2 也可能有问题。published、registration、deadline 原文保留；只有日期不补时分，文件获取不能当报名截止。quantity 始终保留原单位与暂估说明。judgment 为采集端初判，不能称平台验证结论。evidence 只是未上传的文件引用。

idempotency_key 在同采集端唯一，同键同内容返回原批次；同键不同内容冲突。内容未变返回 duplicate；同采集端更改返回 pending_update 等管理员处理；跨采集端同身份返回 identity_review 且不泄露已有记录ID。邀请数据仅上传人员与管理员可见。返回 completed 表示批次处理完成，不代表每条已新增。

扩展字段保留在原始 payload，但不自动变为可筛选字段。批次 scope/coverage/exclusions/limitations 在批次详情查看。
