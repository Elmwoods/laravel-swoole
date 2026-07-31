<?php

use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogUdpHandler;
use Monolog\Processor\PsrLogMessageProcessor;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Log Channel
    |--------------------------------------------------------------------------
    |
    | This option defines the default logs channel that is utilized to write
    | messages to your logs. The value provided here should match one of
    | the channels present in the list of "channels" configured below.
    |
    */

    // 默认日志通道，读取 LOG_CHANNEL，默认 "stack"；须匹配下方 channels 中的某个键
    'default' => env('LOG_CHANNEL', 'stack'),

    /*
    |--------------------------------------------------------------------------
    | Deprecations Log Channel
    |--------------------------------------------------------------------------
    |
    | This option controls the logs channel that should be used to logs warnings
    | regarding deprecated PHP and library features. This allows you to get
    | your application ready for upcoming major versions of dependencies.
    |
    */

    // 弃用警告日志：记录 PHP 及依赖库的弃用特性，便于提前适配大版本升级
    'deprecations' => [
        // 记录弃用警告使用的通道，读取 LOG_DEPRECATIONS_CHANNEL，默认 "null"（不记录）
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        // 是否包含堆栈跟踪，读取 LOG_DEPRECATIONS_TRACE，默认 false
        'trace' => env('LOG_DEPRECATIONS_TRACE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Channels
    |--------------------------------------------------------------------------
    |
    | Here you may configure the logs channels for your application. Laravel
    | utilizes the Monolog PHP logging library, which includes a variety
    | of powerful logs handlers and formatters that you're free to use.
    |
    | Available drivers: "single", "daily", "slack", "syslog",
    |                    "errorlog", "monolog", "custom", "stack"
    |
    */

    // 日志通道定义：基于 Monolog，可自由组合各类处理器与格式化器
    'channels' => [

        // stack 通道：把日志同时写入多个子通道
        'stack' => [
            'driver' => 'stack',
            // 组合的子通道列表，从 LOG_STACK（逗号分隔）解析，默认 "single"
            'channels' => explode(',', (string) env('LOG_STACK', 'single')),
            // 子通道抛异常时是否忽略（false 表示不忽略）
            'ignore_exceptions' => false,
        ],

        // single 通道：所有日志写入单个文件
        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),     // 日志文件路径
            'level' => env('LOG_LEVEL', 'debug'),           // 记录级别，读取 LOG_LEVEL，默认 debug
            'replace_placeholders' => true,                 // 是否替换消息中的 {占位符}
        ],

        // daily 通道：按天切割日志文件
        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => env('LOG_DAILY_DAYS', 14),            // 保留天数，读取 LOG_DAILY_DAYS，默认 14
            'replace_placeholders' => true,
        ],

        // slack 通道：将日志推送到 Slack
        'slack' => [
            'driver' => 'slack',
            'url' => env('LOG_SLACK_WEBHOOK_URL'),          // Slack Webhook 地址
            'username' => env('LOG_SLACK_USERNAME', env('APP_NAME', 'Laravel')), // 显示用户名，默认应用名
            'emoji' => env('LOG_SLACK_EMOJI', ':boom:'),    // 消息 emoji，默认 :boom:
            'level' => env('LOG_LEVEL', 'critical'),        // 记录级别，此处默认 critical
            'replace_placeholders' => true,
        ],

        // papertrail 通道：通过 syslog UDP 推送到 Papertrail 等远程日志服务
        'papertrail' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            // Monolog handler 类，默认 SyslogUdpHandler
            'handler' => env('LOG_PAPERTRAIL_HANDLER', SyslogUdpHandler::class),
            'handler_with' => [
                'host' => env('PAPERTRAIL_URL'),            // 远程主机
                'port' => env('PAPERTRAIL_PORT'),           // 远程端口
                // 拼接的 TLS 连接串
                'connectionString' => 'tls://'.env('PAPERTRAIL_URL').':'.env('PAPERTRAIL_PORT'),
            ],
            'processors' => [PsrLogMessageProcessor::class],
        ],

        // stderr 通道：日志写入标准错误输出（容器化部署常用）
        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'handler_with' => [
                'stream' => 'php://stderr',
            ],
            // 自定义格式化器类，读取 LOG_STDERR_FORMATTER
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'processors' => [PsrLogMessageProcessor::class],
        ],

        // syslog 通道：写入系统 syslog
        'syslog' => [
            'driver' => 'syslog',
            'level' => env('LOG_LEVEL', 'debug'),
            'facility' => env('LOG_SYSLOG_FACILITY', LOG_USER), // syslog facility，默认 LOG_USER
            'replace_placeholders' => true,
        ],

        // errorlog 通道：写入 PHP error_log
        'errorlog' => [
            'driver' => 'errorlog',
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        // null 通道：丢弃所有日志
        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

        // deprecations 通道：弃用警告专用文件
        'deprecations' => [
            'driver' => 'single',
            'path' => storage_path('logs/deprecations.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        // emergency 通道：当日志系统本身出错时的兜底文件
        'emergency' => [
            'path' => storage_path('logs/laravel.log'),
        ],

    ],

];
