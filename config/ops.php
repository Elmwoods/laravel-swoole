<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Ops Center 队列监控配置
    |--------------------------------------------------------------------------
    |
    | 这里集中声明需要展示在运维后台的 Laravel Queue 名称。生产环境中如增加
    | high、mail、webhook 等队列，只需要修改 OPS_QUEUE_NAMES 即可同步到前端。
    |
    */
    'queues' => [
        'names' => array_values(array_filter(explode(',', env('OPS_QUEUE_NAMES', 'default,high,low')))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Ops Center Supervisor 配置
    |--------------------------------------------------------------------------
    |
    | process_allow_pattern 用于保护 start/stop/restart/logs 等操作的进程名，
    | 防止把任意字符串拼到 supervisorctl 命令里。日志路径与 docker/8.4/*.conf
    | 保持一致，便于 Sail + Octane 容器内直接查看。
    |
    */
    'supervisor' => [
        'process_allow_pattern' => '/^[A-Za-z0-9_.:-]+$/',

        'logs' => [
            'octane' => storage_path('logs/octane.log'),
            'laravel-worker' => storage_path('logs/worker.log'),
            'laravel-reverb' => storage_path('logs/reverb.log'),
            'scheduler' => storage_path('logs/scheduler.log'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Ops Center 日志中心配置
    |--------------------------------------------------------------------------
    |
    | system_sources 是系统日志白名单。前端只能传 key，不能传文件路径，
    | 防止日志查看接口读取任意服务器文件。
    |
    */
    'logs' => [
        'system_sources' => [
            'supervisor' => '/tmp/supervisord.log',
            'octane-error' => storage_path('logs/octane-error.log'),
            'scheduler' => storage_path('logs/scheduler.log'),
            'worker' => storage_path('logs/worker.log'),
            'syslog' => '/var/log/syslog',
            'messages' => '/var/log/messages',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Ops Center 告警配置
    |--------------------------------------------------------------------------
    |
    | 第四阶段告警中心默认只开启本地记录和 WebSocket 轻量推送。
    | Telegram / 邮件通知需要显式配置环境变量，避免开发环境误发通知。
    |
    */
    'alerts' => [
        'thresholds' => [
            'disk_usage_warning' => (int) env('OPS_ALERT_DISK_USAGE_WARNING', 85),
            'disk_usage_critical' => (int) env('OPS_ALERT_DISK_USAGE_CRITICAL', 95),
            'queue_pending_warning' => (int) env('OPS_ALERT_QUEUE_PENDING_WARNING', 100),
            'failed_jobs_warning' => (int) env('OPS_ALERT_FAILED_JOBS_WARNING', 1),
            'network_mbps_warning' => (float) env('OPS_ALERT_NETWORK_MBPS_WARNING', 50),
            'docker_exited_enabled' => filter_var(env('OPS_ALERT_DOCKER_EXITED_ENABLED', true), FILTER_VALIDATE_BOOL),
        ],

        'telegram' => [
            'enabled' => filter_var(env('OPS_ALERT_TELEGRAM_ENABLED', false), FILTER_VALIDATE_BOOL),
            'bot_token' => env('OPS_ALERT_TELEGRAM_BOT_TOKEN'),
            'chat_id' => env('OPS_ALERT_TELEGRAM_CHAT_ID'),
        ],

        'mail' => [
            'enabled' => filter_var(env('OPS_ALERT_MAIL_ENABLED', false), FILTER_VALIDATE_BOOL),
            'to' => array_values(array_filter(explode(',', env('OPS_ALERT_MAIL_TO', '')))),
        ],
    ],
];
