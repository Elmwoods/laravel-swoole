<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Mailer
    |--------------------------------------------------------------------------
    |
    | This option controls the default mailer that is used to send all email
    | messages unless another mailer is explicitly specified when sending
    | the message. All additional mailers can be configured within the
    | "mailers" array. Examples of each type of mailer are provided.
    |
    */

    // 默认邮件发送器，读取 MAIL_MAILER，默认 "logs"（把邮件写入日志而非真正发送）
    'default' => env('MAIL_MAILER', 'logs'),

    /*
    |--------------------------------------------------------------------------
    | Mailer Configurations
    |--------------------------------------------------------------------------
    |
    | Here you may configure all of the mailers used by your application plus
    | their respective settings. Several examples have been configured for
    | you and you are free to add your own as your application requires.
    |
    | Laravel supports a variety of mail "transport" drivers that can be used
    | when delivering an email. You may specify which one you're using for
    | your mailers below. You may also add additional mailers if needed.
    |
    | Supported: "smtp", "sendmail", "mailgun", "ses", "ses-v2",
    |            "postmark", "resend", "logs", "array",
    |            "failover", "roundrobin"
    |
    */

    // 邮件发送器配置：定义各发送器及其传输驱动
    'mailers' => [

        // SMTP 发送器
        'smtp' => [
            'transport' => 'smtp',
            'scheme' => env('MAIL_SCHEME'),                 // 传输方案（如 smtp/smtps），读取 MAIL_SCHEME
            'url' => env('MAIL_URL'),                       // 完整连接 URL（可替代下方字段）
            'host' => env('MAIL_HOST', '127.0.0.1'),        // SMTP 主机，默认 127.0.0.1
            'port' => env('MAIL_PORT', 2525),               // SMTP 端口，默认 2525
            'username' => env('MAIL_USERNAME'),             // 认证用户名
            'password' => env('MAIL_PASSWORD'),             // 认证密码
            'timeout' => null,                              // 连接超时，null 表示用默认
            // EHLO 本地域名，读取 MAIL_EHLO_DOMAIN，默认取 APP_URL 的主机名
            'local_domain' => env('MAIL_EHLO_DOMAIN', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST)),
        ],

        // Amazon SES 发送器（凭据见 services.php）
        'ses' => [
            'transport' => 'ses',
        ],

        // Postmark 发送器
        'postmark' => [
            'transport' => 'postmark',
            // 'message_stream_id' => env('POSTMARK_MESSAGE_STREAM_ID'),
            // 'client' => [
            //     'timeout' => 5,
            // ],
        ],

        // Resend 发送器
        'resend' => [
            'transport' => 'resend',
        ],

        // sendmail 发送器：调用本机 sendmail 程序
        'sendmail' => [
            'transport' => 'sendmail',
            // sendmail 可执行路径及参数，读取 MAIL_SENDMAIL_PATH，默认 "/usr/sbin/sendmail -bs -i"
            'path' => env('MAIL_SENDMAIL_PATH', '/usr/sbin/sendmail -bs -i'),
        ],

        // logs 发送器：把邮件写入日志，便于本地开发调试
        'logs' => [
            'transport' => 'logs',
            // 写入的日志通道，读取 MAIL_LOG_CHANNEL，为空则用默认通道
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],

        // array 发送器：邮件存入内存数组（测试用，不真正发送）
        'array' => [
            'transport' => 'array',
        ],

        // failover 发送器：按顺序尝试，前者失败则降级到后者
        'failover' => [
            'transport' => 'failover',
            'mailers' => [
                'smtp',
                'logs',
            ],
            'retry_after' => 60,        // 失败后重试间隔（秒）
        ],

        // roundrobin 发送器：在多个发送器间轮询分发
        'roundrobin' => [
            'transport' => 'roundrobin',
            'mailers' => [
                'ses',
                'postmark',
            ],
            'retry_after' => 60,        // 失败后重试间隔（秒）
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Global "From" Address
    |--------------------------------------------------------------------------
    |
    | You may wish for all emails sent by your application to be sent from
    | the same address. Here you may specify a name and address that is
    | used globally for all emails that are sent by your application.
    |
    */

    // 全局发件人：所有邮件默认使用的发件地址与名称
    'from' => [
        // 发件邮箱，读取 MAIL_FROM_ADDRESS，默认 hello@example.com
        'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
        // 发件人名称，读取 MAIL_FROM_NAME，默认应用名
        'name' => env('MAIL_FROM_NAME', env('APP_NAME', 'Laravel')),
    ],

];
