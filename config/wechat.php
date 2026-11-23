<?php

return [
    'web_login_version' => env('WECHAT_WEB_LOGIN_VERSION', 'release'),
    'appid' => env('WECHAT_MINIAPP_APPID', ''),
    'secret' => env('WECHAT_MINIAPP_SECRET', ''),

    'notify' => [
        'enabled' => env('WECHAT_NOTIFY_ENABLED', false),
        'state' => env('WECHAT_NOTIFY_STATE', 'formal'),

        /*
        |--------------------------------------------------------------------------
        | Subscribe message templates (OA reimbursement only)
        |--------------------------------------------------------------------------
        |
        | Template ids come from the WeChat MP console under 订阅消息 → 一次性订阅.
        | The field map must match the keywords chosen in the console: key is the
        | WeChat field (type + index), value is the rendered source.
        |
        | Supported sources for approval: type, owner, amount, submitted_at.
        | Supported sources for result: status, title, time, reason.
        |
        */
        'templates' => [
            'reimbursement_approval' => [
                'id' => (string) env('WECHAT_TEMPLATE_REIMBURSEMENT_APPROVAL', ''),
                'label' => '报销待办提醒',
                'page' => 'pages/login/login?notice=approval',
                'fields' => [
                    'thing16' => 'type',
                    'thing17' => 'owner',
                    'amount19' => 'amount',
                    'time18' => 'submitted_at',
                ],
            ],
            'reimbursement_result' => [
                'id' => (string) env('WECHAT_TEMPLATE_REIMBURSEMENT_RESULT', ''),
                'label' => '报销结果通知',
                'page' => 'pages/login/login?notice=result',
                'fields' => [
                    'phrase1' => 'status',
                    'thing2' => 'title',
                    'date3' => 'time',
                    'thing5' => 'reason',
                ],
            ],
        ],
    ],
];
