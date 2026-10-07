<?php

$field = fn (string $key, string $label, string $type = 'text', array $extra = []) => array_merge([
    'key' => $key,
    'label' => $label,
    'type' => $type,
], $extra);
$code = fn (string $key, string $label) => $field($key, $label, 'readonly', ['system' => 'code']);
$relation = fn (string $key, string $label, string $target, array $extra = []) => $field($key, $label, 'relation', array_merge(['target' => $target], $extra));
$multirelation = fn (string $key, string $label, string $target, array $extra = []) => $field($key, $label, 'multirelation', array_merge(['target' => $target], $extra));
$multiaccount = fn (string $key, string $label, array $extra = []) => $field($key, $label, 'multiaccount', $extra);
$creatableRelation = fn (string $key, string $label, string $target, array $extra = []) => $field($key, $label, 'creatable_relation', array_merge(['target' => $target], $extra));
$select = fn (string $key, string $label, array $options, array $extra = []) => $field($key, $label, 'select', array_merge(['options' => $options], $extra));
$date = fn (string $key, string $label, array $extra = []) => $field($key, $label, 'date', $extra);
$datetime = fn (string $key, string $label, array $extra = []) => $field($key, $label, 'datetime', $extra);
$number = fn (string $key, string $label, array $extra = []) => $field($key, $label, 'number', $extra);
$file = fn (string $key, string $label, array $extra = []) => $field($key, $label, 'file', $extra);
$files = fn (string $key, string $label, array $extra = []) => $field($key, $label, 'files', $extra);
$projectStatuses = ['投标中', '已中标', '已拿到加工函', '合同签署', '已完成'];

return [
    'default_role' => 'basic',
    'tender_timezone' => env('TENDER_TIMEZONE', 'Asia/Shanghai'),
    'retired_roles' => ['warehouse'],

    'roles' => [
        ['name' => 'basic', 'label' => '基础角色', 'description' => '默认注册角色：查看大盘和使用授权的 AI 助手。', 'locked' => false],
        ['name' => 'admin', 'label' => '管理', 'description' => '系统管理、RBAC、全部对象权限。', 'locked' => true],
        ['name' => 'business', 'label' => '业务', 'description' => '客户、合同和项目推进。', 'locked' => false],
        ['name' => 'business_manager_view', 'label' => '业务管理查看', 'description' => '查看全部业务数据，仅维护本人归属且原有权限允许的数据；共享客户只读。', 'locked' => true],
        ['name' => 'engineering', 'label' => '技术', 'description' => '保留技术角色身份；业务权限由现行授权管理。', 'locked' => false],
        ['name' => 'procurement', 'label' => '采购', 'description' => '保留采购角色身份；业务权限由现行授权管理。', 'locked' => false],
        ['name' => 'production_manager', 'label' => '生产负责人', 'description' => '保留生产负责人角色身份；业务权限由现行授权管理。', 'locked' => false],
        ['name' => 'production', 'label' => '生产', 'description' => '保留生产角色身份；业务权限由现行授权管理。', 'locked' => false],
        ['name' => 'finance', 'label' => '财务', 'description' => '项目财务台账、开票和回款。', 'locked' => false],
        ['name' => 'tender', 'label' => '招投标', 'description' => '维护招投标信息并参与客户前期对接。', 'locked' => false],
    ],

    'permissions' => [
        ['key' => 'timebook.view', 'module' => 'timebook', 'action' => 'view', 'label' => '查看工日簿'],
        ['key' => 'timebook.create', 'module' => 'timebook', 'action' => 'create', 'label' => '新增记工'],
        ['key' => 'timebook.update', 'module' => 'timebook', 'action' => 'update', 'label' => '修改记工'],
        ['key' => 'timebook.delete', 'module' => 'timebook', 'action' => 'delete', 'label' => '删除及恢复记工'],
        ['key' => 'timebook.export', 'module' => 'timebook', 'action' => 'export', 'label' => '导出工日簿'],
        ['key' => 'timebook.audit', 'module' => 'timebook', 'action' => 'audit', 'label' => '查看记工留痕'],
        ['key' => 'timebook.ai.query', 'module' => 'timebook', 'action' => 'ai.query', 'label' => 'AI 只读查询工日簿'],
        ['key' => 'dashboard.view', 'module' => 'dashboard', 'action' => 'view', 'label' => '查看大盘'],
        ['key' => 'ai.harness.view', 'module' => 'ai', 'action' => 'view', 'label' => '使用 AI 数据助手'],
        ['key' => 'rbac.manage', 'module' => 'system', 'action' => 'manage', 'label' => '管理用户与权限'],
    ],

    'role_permissions' => [
        'basic' => ['dashboard.view', 'ai.harness.view'],
        'admin' => ['*'],
        'business' => ['dashboard.view', 'ai.harness.view'],
        'business_manager_view' => [
            'dashboard.view',
            'object.customer.view',
            'object.customer_contact.view',
            'object.tender.view',
            'object.project.view',
            'object.project_business_summary.view',
            'object.contract.view',
        ],
        'engineering' => ['dashboard.view', 'ai.harness.view'],
        'procurement' => ['dashboard.view', 'ai.harness.view'],
        'production_manager' => ['dashboard.view', 'ai.harness.view'],
        'production' => ['dashboard.view', 'ai.harness.view'],
        'finance' => ['dashboard.view', 'ai.harness.view'],
        'tender' => [
            'dashboard.view',
            'ai.harness.view',
            'object.customer.view',
            'object.customer.create',
            'object.customer.update',
        ],
    ],

    'objects' => [
        [
            'key' => 'customer', 'label' => '客户信息', 'group' => '主数据', 'code_prefix' => 'CUST', 'title_field' => 'name', 'roles' => ['business', 'finance'], 'write_roles' => ['business'],
            'fields' => [
                $code('customer_no', '客户编号'),
                $field('name', '客户名称', 'text', ['required' => true]),
                $field('address', '地址'),
                $select('level', '客户等级', ['A', 'B', 'C']),
                $select('customer_nature', '客户性质', ['国央企', '私企']),
                $field('cooperation_history', '合作历史'),
                $field('remark', '备注'),
            ],
        ],
        [
            'key' => 'customer_contact', 'label' => '客户联系人', 'group' => '主数据', 'code_prefix' => 'CONTACT', 'title_field' => 'name', 'roles' => ['business'],
            'fields' => [
                $field('name', '联系人姓名', 'text', ['required' => true]),
                $field('phone', '联系电话'),
                $relation('customer_id', '所属客户', 'customer', ['required' => true]),
                $multirelation('project_ids', '关联项目', 'project', ['readonly' => true]),
            ],
        ],
        [
            'key' => 'tender', 'label' => '招投标信息', 'group' => '招投标', 'code_prefix' => 'ZB', 'title_field' => 'name', 'roles' => ['tender', 'business'], 'write_roles' => ['tender'],
            'fields' => [
                $code('tender_no', '招投标编号'),
                $field('name', '标的名称', 'text', ['required' => true]),
                $creatableRelation('customer_id', '客户名称', 'customer', ['required' => true]),
                $field('tender_agency', '招标单位/代理机构'),
                $field('source_site', '信息来源网站'),
                $date('announce_date', '公告日期'),
                $datetime('register_deadline', '报名截止时间', ['required' => true]),
                $datetime('purchase_deadline', '购买标书截止时间', ['required' => true]),
                $datetime('submit_deadline', '投标截止时间', ['required' => true]),
                $datetime('bid_open_at', '开标时间'),
                $number('budget_amount', '预算金额', ['min' => 0]),
                $number('doc_fee', '标书费用', ['min' => 0]),
                $select('purchase_status', '标书购买状态', ['未购买', '已购买'], ['default' => '未购买']),
                $select('status', '招投标状态', ['跟踪中', '已报名', '已购标书', '制作中', '已递交', '已中标', '未中标', '已放弃'], [
                    'default' => '跟踪中',
                    'restricted_options' => ['已中标'],
                ]),
                $file('tender_file', '招标文件'),
                $file('bid_file', '投标文件扫描件'),
                $relation('converted_project_id', '流转项目', 'project', ['readonly' => true]),
                $field('assignee_user_id', '接手业务员', 'account', ['editable_when_status' => ['已中标']]),
                $field('manager', '投标负责人'),
            ],
        ],
        [
            'key' => 'project', 'label' => '业务项目', 'group' => '业务与合同', 'code_prefix' => 'XYC', 'title_field' => 'name', 'roles' => ['business', 'finance'], 'write_roles' => ['business', 'finance'],
            'fields' => [
                $field('business_owner_user_id', '负责业务员', 'account'),
                $code('project_no', '项目编号'),
                $relation('customer_id', '客户名称', 'customer', ['required' => true]),
                $field('name', '项目名称', 'text', ['required' => true]),
                $date('handover_date', '项目对接日期'),
                $date('first_shipment_date', '首次发货日期'),
                $date('last_shipment_date', '末次发货日期'),
                $number('signed_weight', '累计签收重量', ['min' => 0, 'step' => 0.01]),
                $number('occurred_amount', '已发生金额', ['step' => 0.01]),
                $number('paid_amount', '已回款金额', ['step' => 0.01]),
                $number('unpaid_amount', '未回款金额', ['step' => 0.01]),
                $date('last_payment_date', '末次回款日期'),
                $number('payment_progress', '回款进度', ['readonly' => true, 'step' => 0.01]),
                $number('reconciled_amount', '对账金额', ['step' => 0.01]),
                $number('invoiced_amount', '开票金额', ['step' => 0.01]),
                $number('uninvoiced_amount', '未开票金额', ['step' => 0.01]),
                $field('contract_status', '合同状态', 'readonly'),
                $files('unassigned_processing_letter_attachments', '加工函／订货及中标附件（待关联主合同）'),
                $files('other_attachments', '其他附件（担保书等）'),
                $number('weight', '合同重量（吨）', ['min' => 0, 'step' => 0.01]),
                $number('contract_amount', '合同金额', ['step' => 0.01]),
                $multirelation('customer_contact_ids', '客户联系人', 'customer_contact'),
                $field('customer_address', '客户地址', 'lookup'),
                $field('customer_level', '客户等级', 'lookup'),
                $field('customer_nature', '客户性质', 'lookup'),
                $field('collection_count', '催款次数', 'readonly'),
                $field('risk', '当前风险点'),
                $multiaccount('informed_business_user_ids', '知会人员'),
                $select('overall_status', '总体状态', $projectStatuses, ['default' => '投标中']),
                $field('remark', '备注'),
            ],
        ],
        [
            'key' => 'project_business_summary', 'label' => '业务概括表', 'group' => '主数据', 'code_prefix' => 'XYC', 'title_field' => 'name', 'roles' => ['business', 'finance'], 'write_roles' => [], 'read_only' => true,
            'fields' => [
                $field('business_owner_user_id', '负责业务员', 'account', ['readonly' => true]),
                $field('project_no', '项目编号', 'readonly', ['system' => 'code']),
                $relation('customer_id', '客户名称', 'customer', ['readonly' => true]),
                $field('name', '项目名称', 'readonly', ['system' => 'title']),
                $number('occurred_amount', '已发生金额', ['readonly' => true]),
                $number('paid_amount', '已回款金额', ['readonly' => true]),
                $number('unpaid_amount', '未回款金额', ['readonly' => true]),
            ],
        ],
        [
            'key' => 'contract', 'label' => '合同表', 'group' => '业务与合同', 'code_prefix' => 'HT', 'title_field' => 'code', 'roles' => ['business'], 'write_roles' => [],
            'fields' => [
                $field('business_owner_name', '负责业务员', 'lookup', ['readonly' => true, 'source' => 'project_business_owner']),
                $code('contract_no', '合同编号'),
                $relation('customer_id', '客户', 'customer', ['required' => true]),
                $relation('project_id', '项目名称', 'project', ['required' => true]),
                $field('project_no', '项目编号', 'readonly'),
                $select('status', '合同状态', ['未签署', '已有加工函', '已签署'], ['default' => '未签署']),
                $select('ctype', '合同类型', ['销售合同', '加工合同', '补充协议']),
                $number('amount', '合同金额'),
                $date('signed_date', '签订日期'),
                $files('processing_letter_attachments', '加工函附件'),
                $files('contract_attachments', '合同附件'),
                $files('statement_attachments', '对账单附件'),
                $files('other_attachments', '其他附件（承诺书等）'),
                $field('contract_chase_record', '合同催要记录'),
                $number('contract_qty', '合同数量'),
                $field('remark', '备注'),
            ],
        ],
    ],
];
