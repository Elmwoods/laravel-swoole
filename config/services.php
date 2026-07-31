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

    // Postmark 邮件服务：API 密钥来自 POSTMARK_API_KEY
    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    // Resend 邮件服务：API 密钥来自 RESEND_API_KEY
    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    // Amazon SES 邮件服务：AWS 凭据与区域（region 默认 us-east-1）
    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // Slack 通知：用于 Laravel Slack 通知渠道
    'slack' => [
        'notifications' => [
            // Slack Bot 的 OAuth 令牌，来自 SLACK_BOT_USER_OAUTH_TOKEN
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            // 默认发送的频道，来自 SLACK_BOT_USER_DEFAULT_CHANNEL
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
