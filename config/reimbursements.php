<?php

return [
    'types' => ['住宿费', '加油费', '话费', '高速费', '差旅交通', '客户招待', '办公采购', '其他'],
    'batch_enabled' => env('REIMBURSEMENT_BATCH_ENABLED', false),
    'batch_memory_path' => env('REIMBURSEMENT_BATCH_MEMORY_PATH', '/run/palantir-invoices'),
    'wechat_template' => env('REIMBURSEMENT_WECHAT_TEMPLATE', ''),
    'wechat_state' => env('REIMBURSEMENT_WECHAT_STATE', 'formal'),
    'wechat_title_field' => env('REIMBURSEMENT_WECHAT_TITLE_FIELD', 'thing1'),
    'wechat_status_field' => env('REIMBURSEMENT_WECHAT_STATUS_FIELD', 'phrase2'),
];
