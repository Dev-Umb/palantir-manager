<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'feishu' => [
        'enabled' => env('FEISHU_ENABLED', false),
        'base_url' => env('FEISHU_BASE_URL', 'https://open.feishu.cn/open-apis'),
        'app_id' => env('FEISHU_APP_ID'),
        'app_secret' => env('FEISHU_APP_SECRET'),
        'verification_token' => env('FEISHU_VERIFICATION_TOKEN'),
        'tenant_key' => env('FEISHU_TENANT_KEY'),
        'attachment_disk' => env('FEISHU_ATTACHMENT_DISK', 'local'),
        'attachment_max_bytes' => env('FEISHU_ATTACHMENT_MAX_BYTES', 20 * 1024 * 1024),
        'cli' => [
            'enabled' => env('FEISHU_CLI_ENABLED', false),
            'binary' => env('FEISHU_CLI_BINARY', 'lark-cli'),
            'profile' => env('FEISHU_CLI_PROFILE', 'palantir'),
            'timeout' => env('FEISHU_CLI_TIMEOUT', 45),
            'max_rows' => env('FEISHU_CLI_MAX_ROWS', 200),
            'max_columns' => env('FEISHU_CLI_MAX_COLUMNS', 20),
            'max_payload_bytes' => env('FEISHU_CLI_MAX_PAYLOAD_BYTES', 200000),
        ],
    ],

];
