<?php

return [
    'project.unpaid_amount' => [
        'label' => '项目未回款金额',
        'aliases' => ['欠款', '未回款', '应收余额'],
        'formula' => '直接读取项目主档 unpaid_amount；有欠款项目限定 unpaid_amount > 0',
        'unit' => '元',
        'null_strategy' => '未回款金额为空时保持未知，不按零计入，不从合同或已回款金额补算；零和负数保留原值',
    ],
    'project.count' => [
        'label' => '项目数',
        'aliases' => ['项目数量', '项目总数'],
        'formula' => 'count(project)',
        'unit' => '个',
        'null_strategy' => '只统计当前用户可见项目',
    ],
];
