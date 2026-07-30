<?php

return [
    'project.arrears' => [
        'label' => '项目欠款',
        'aliases' => ['欠款', '未回款', '应收余额'],
        'formula' => 'arrears；为空时 max(contract_amount - paid_amount, 0)',
        'unit' => '元',
        'null_strategy' => '合同金额和回款金额均为空时保留为空，并提示数据质量问题',
    ],
    'project.count' => [
        'label' => '项目数',
        'aliases' => ['项目数量', '项目总数'],
        'formula' => 'count(project)',
        'unit' => '个',
        'null_strategy' => '只统计当前用户可见项目',
    ],
];
