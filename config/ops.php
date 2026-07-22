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
    /*
    |--------------------------------------------------------------------------
    | Ops Center 自动巡检配置
    |--------------------------------------------------------------------------
    |
    | 巡检的 Alert Evaluation 检查会跑一次告警评估；单次瞬时失败（部署后未
    | reload、依赖瞬断）降级为 warn，只有连续失败达到阈值才判 fail（推 critical），
    | 避免一次抖动就刷屏。阈值按每分钟的 ops:alerts:evaluate 落库的评估历史统计。
    |
    */
    'inspections' => [
        'alert_eval_fail_threshold' => max(1, (int) env('OPS_INSPECTION_ALERT_EVAL_FAIL_THRESHOLD', 3)),
    ],

    'logs' => [
        'system_sources' => [
            'supervisor' => '/tmp/supervisord.log',
            'octane-error' => storage_path('logs/octane-error.log'),
            'scheduler' => storage_path('logs/scheduler.log'),
            'worker' => storage_path('logs/worker.log'),
            'syslog' => '/var/log/syslog',
            'messages' => '/var/log/messages',
        ],

        'error_watcher' => [
            'enabled' => filter_var(env('OPS_LOG_ERROR_WATCHER_ENABLED', true), FILTER_VALIDATE_BOOL),
            'sources' => [
                'laravel' => storage_path('logs/laravel.log'),
                'octane' => storage_path('logs/octane.log'),
                'worker' => storage_path('logs/worker.log'),
                'scheduler' => storage_path('logs/scheduler.log'),
                'build' => base_path('build.log'),
            ],
            'levels' => array_values(array_filter(explode(',', env('OPS_LOG_ERROR_WATCHER_LEVELS', 'ERROR,CRITICAL,EMERGENCY')))),
            'context_lines' => (int) env('OPS_LOG_ERROR_WATCHER_CONTEXT_LINES', 3),
            'max_events_per_run' => (int) env('OPS_LOG_ERROR_WATCHER_MAX_EVENTS_PER_RUN', 50),
            'state_file' => storage_path('app/ops-log-watcher-state.json'),
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
        // 通知通道单一来源：新增通道只需在此登记，Service / Settings / 请求校验 / 前端都据此遍历。
        'channels' => ['telegram', 'mail', 'webhook', 'dingtalk', 'feishu'],

        'thresholds' => [
            'disk_usage_warning' => (int) env('OPS_ALERT_DISK_USAGE_WARNING', 85),
            'disk_usage_critical' => (int) env('OPS_ALERT_DISK_USAGE_CRITICAL', 95),
            'queue_pending_warning' => (int) env('OPS_ALERT_QUEUE_PENDING_WARNING', 100),
            'failed_jobs_warning' => (int) env('OPS_ALERT_FAILED_JOBS_WARNING', 1),
            'network_mbps_warning' => (float) env('OPS_ALERT_NETWORK_MBPS_WARNING', 50),
            'docker_exited_enabled' => filter_var(env('OPS_ALERT_DOCKER_EXITED_ENABLED', true), FILTER_VALIDATE_BOOL),
            'auto_resolve_enabled' => filter_var(env('OPS_ALERT_AUTO_RESOLVE_ENABLED', true), FILTER_VALIDATE_BOOL),
            'auto_resolve_grace_minutes' => (int) env('OPS_ALERT_AUTO_RESOLVE_GRACE_MINUTES', 5),
            'notification_repeat_minutes' => (int) env('OPS_ALERT_NOTIFICATION_REPEAT_MINUTES', 30),
            'escalation_enabled' => filter_var(env('OPS_ALERT_ESCALATION_ENABLED', true), FILTER_VALIDATE_BOOL),
            'escalation_after_minutes' => (int) env('OPS_ALERT_ESCALATION_AFTER_MINUTES', 30),
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

        'webhook' => [
            'enabled' => filter_var(env('OPS_ALERT_WEBHOOK_ENABLED', false), FILTER_VALIDATE_BOOL),
            'url' => env('OPS_ALERT_WEBHOOK_URL'),
            'secret' => env('OPS_ALERT_WEBHOOK_SECRET'),
        ],

        'dingtalk' => [
            'enabled' => filter_var(env('OPS_ALERT_DINGTALK_ENABLED', false), FILTER_VALIDATE_BOOL),
            'webhook' => env('OPS_ALERT_DINGTALK_WEBHOOK'),
            'secret' => env('OPS_ALERT_DINGTALK_SECRET'),
        ],

        'feishu' => [
            'enabled' => filter_var(env('OPS_ALERT_FEISHU_ENABLED', false), FILTER_VALIDATE_BOOL),
            'webhook' => env('OPS_ALERT_FEISHU_WEBHOOK'),
            'secret' => env('OPS_ALERT_FEISHU_SECRET'),
        ],

        'demo' => [
            'enabled' => filter_var(env('OPS_ALERT_DEMO_ENABLED', env('APP_ENV', 'local') !== 'production'), FILTER_VALIDATE_BOOL),
        ],

        // 异常登录（新 IP / 新设备）主动告警：命中 phase-17 登录事件的风控标记时升起告警并经现有通道推送。
        'login_alerts' => [
            'enabled' => filter_var(env('OPS_LOGIN_ALERTS_ENABLED', true), FILTER_VALIDATE_BOOL),
            'severity' => env('OPS_LOGIN_ALERTS_SEVERITY', 'warning'),
        ],

        // 定时聚合摘要：把一段窗口内的告警汇总成一条消息经现有通道推送。默认关闭（opt-in），避免意外外发。
        'digest' => [
            'enabled' => filter_var(env('OPS_ALERT_DIGEST_ENABLED', false), FILTER_VALIDATE_BOOL),
            'window_hours' => (int) env('OPS_ALERT_DIGEST_WINDOW_HOURS', 24),
            'severity' => env('OPS_ALERT_DIGEST_SEVERITY', 'info'),
            'send_when_empty' => filter_var(env('OPS_ALERT_DIGEST_SEND_WHEN_EMPTY', false), FILTER_VALIDATE_BOOL),
        ],
    ],
];
