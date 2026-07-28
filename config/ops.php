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

        // 文本通道（telegram/mail/dingtalk/feishu）通知模板。留空=用内置多行格式。
        // 占位符：{title} {severity} {source} {status} {time} {message}
        'message_template' => (string) env('OPS_ALERT_MESSAGE_TEMPLATE', ''),

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

        // 多级升级链（L1→L2→L3，部署期设，同 SLA 目标口径）：open critical 告警随时长逐级升级，
        // 每级可指定 channels（空=全部启用通道）与是否改派给当前值班人。after_minutes 为距 created_at 的绝对时长。
        'escalation_levels' => [
            ['after_minutes' => (int) env('OPS_ALERT_ESCALATION_L1_MINUTES', 30), 'channels' => [], 'reassign_on_call' => false],
            ['after_minutes' => (int) env('OPS_ALERT_ESCALATION_L2_MINUTES', 60), 'channels' => [], 'reassign_on_call' => true],
            ['after_minutes' => (int) env('OPS_ALERT_ESCALATION_L3_MINUTES', 120), 'channels' => [], 'reassign_on_call' => false],
        ],

        'telegram' => [
            'enabled' => filter_var(env('OPS_ALERT_TELEGRAM_ENABLED', false), FILTER_VALIDATE_BOOL),
            'bot_token' => env('OPS_ALERT_TELEGRAM_BOT_TOKEN'),
            'chat_id' => env('OPS_ALERT_TELEGRAM_CHAT_ID'),
            'message_template' => (string) env('OPS_ALERT_TELEGRAM_MESSAGE_TEMPLATE', ''),
        ],

        'mail' => [
            'enabled' => filter_var(env('OPS_ALERT_MAIL_ENABLED', false), FILTER_VALIDATE_BOOL),
            'to' => array_values(array_filter(explode(',', env('OPS_ALERT_MAIL_TO', '')))),
            'message_template' => (string) env('OPS_ALERT_MAIL_MESSAGE_TEMPLATE', ''),
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
            'message_template' => (string) env('OPS_ALERT_DINGTALK_MESSAGE_TEMPLATE', ''),
        ],

        'feishu' => [
            'enabled' => filter_var(env('OPS_ALERT_FEISHU_ENABLED', false), FILTER_VALIDATE_BOOL),
            'webhook' => env('OPS_ALERT_FEISHU_WEBHOOK'),
            'secret' => env('OPS_ALERT_FEISHU_SECRET'),
            'message_template' => (string) env('OPS_ALERT_FEISHU_MESSAGE_TEMPLATE', ''),
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

        // 审计异常检测：定时扫描 admin_audit_logs，命中失败登录暴增 / 敏感操作时升 security_audit 告警。
        'audit_anomaly' => [
            'enabled' => filter_var(env('OPS_AUDIT_ANOMALY_ENABLED', true), FILTER_VALIDATE_BOOL),
            'window_minutes' => max(1, (int) env('OPS_AUDIT_ANOMALY_WINDOW_MINUTES', 10)),
            'failed_login_threshold' => max(1, (int) env('OPS_AUDIT_ANOMALY_FAILED_LOGIN_THRESHOLD', 5)),
            'sensitive_actions' => array_values(array_filter(explode(',', env('OPS_AUDIT_ANOMALY_SENSITIVE_ACTIONS', 'admin.users:create,admin.users:reset_password,admin.users:two_factor_reset,admin.users:two_factor_reset_cli,admin.roles:create,admin.roles:update')))),
            'max_rows_per_run' => max(1, (int) env('OPS_AUDIT_ANOMALY_MAX_ROWS_PER_RUN', 500)),
            'state_file' => storage_path('app/ops-audit-anomaly-state.json'),
        ],

        // 通知通道健康自检：定时静默探测每个已启用通道的连通性，连续失败超阈值升 channel_health 告警。
        // 默认 opt-in 关闭（定时外拨 + 钉钉/飞书心跳）。
        'health' => [
            'enabled' => filter_var(env('OPS_ALERT_HEALTH_ENABLED', false), FILTER_VALIDATE_BOOL),
            'fail_threshold' => max(1, (int) env('OPS_ALERT_HEALTH_FAIL_THRESHOLD', 2)),
            // 限定主动探测的通道（csv）；默认空 = 探测全部已启用通道。用于排除钉钉/飞书心跳。
            'probe_channels' => array_values(array_filter(array_map('trim', explode(',', (string) env('OPS_ALERT_HEALTH_PROBE_CHANNELS', ''))))),
        ],

        // 告警处理 SLA 达标监控：定时扫描未闭环告警，按严重级的确认/恢复时限判违约，命中升 sla_breach 告警。
        // 目标走 env（部署期设，csv 顺序 critical,warning,info，分钟）；默认 opt-in 关闭。
        'sla' => [
            'enabled' => filter_var(env('OPS_ALERT_SLA_ENABLED', false), FILTER_VALIDATE_BOOL),
            // csv 顺序 critical,warning,info；由 AlertCenterService::slaTargets 解析成 per-severity map。
            'ack_minutes' => (string) env('OPS_ALERT_SLA_ACK_MINUTES', '10,30,120'),
            'resolve_minutes' => (string) env('OPS_ALERT_SLA_RESOLVE_MINUTES', '60,240,1440'),
        ],

        // 值班排班自动指派：开启后，规则引擎新建的告警自动指派给当前值班人（值班排班表按绝对时间段维护）。
        // 默认 opt-in 关闭。
        'on_call' => [
            'enabled' => filter_var(env('OPS_ALERT_ON_CALL_ENABLED', false), FILTER_VALIDATE_BOOL),
        ],

        // 指派通知推送：告警被指派/认领时向被指派人推送一条通知（复用现有通道，severity=info 路由）。默认 opt-in 关闭。
        'assignment_notify' => [
            'enabled' => filter_var(env('OPS_ALERT_ASSIGNMENT_NOTIFY_ENABLED', false), FILTER_VALIDATE_BOOL),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Ops Center 安全准入配置
    |--------------------------------------------------------------------------
    |
    | 后台登录 IP 白/黑名单：CIDR 级准入，在认证入口 + 中间件强制。
    | 全局启用开关与名单模式默认写在这里作种子，运行时可被 admin_security_settings
    | 表覆盖（后台 UI 可切换）。CIDR 规则本身存 admin_ip_rules 表。
    |
    | ⚠️ 反向代理：要按真实客户端 IP 生效，必须先配 OPS_TRUSTED_PROXIES，
    | 否则 request->ip() 是代理 IP（详见 docs/ops-center-deploy-runbook.md）。
    |
    */
    'security' => [
        'ip_access' => [
            // opt-in：默认关闭，不改现有登录行为；黑名单模式为默认（空名单=全部放行）。
            'enabled' => filter_var(env('OPS_IP_ACCESS_ENABLED', false), FILTER_VALIDATE_BOOL),
            'mode' => env('OPS_IP_ACCESS_MODE', 'blocklist'),
        ],

        // 可信反向代理列表（逗号分隔的 IP/CIDR，或单个 '*' 表示信任全部）。默认空 = 保持现状不改行为。
        'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', (string) env('OPS_TRUSTED_PROXIES', ''))))),

        // 滥用来源自动封禁：定时按 IP 统计失败登录，超阈值自动写临时 deny 规则（带过期）。
        // 默认 opt-in 关闭（激进；代理后未配 OPS_TRUSTED_PROXIES 前不要开，否则可能封掉代理=锁死所有人）。
        // enabled 作种子，运行时被 admin_security_settings.auto_ban_enabled 覆盖（UI 可切换）；阈值/窗口/时长走 env。
        'auto_ban' => [
            'enabled' => filter_var(env('OPS_AUTO_BAN_ENABLED', false), FILTER_VALIDATE_BOOL),
            'threshold' => max(1, (int) env('OPS_AUTO_BAN_THRESHOLD', 10)),
            'window_minutes' => max(1, (int) env('OPS_AUTO_BAN_WINDOW_MINUTES', 10)),
            'ban_minutes' => max(1, (int) env('OPS_AUTO_BAN_MINUTES', 60)),
            'max_rows_per_run' => max(1, (int) env('OPS_AUTO_BAN_MAX_ROWS_PER_RUN', 500)),
            // never-ban 护栏：默认含 loopback，永不自动封禁这些来源。
            'never_ban' => array_values(array_filter(array_map('trim', explode(',', (string) env('OPS_AUTO_BAN_NEVER_BAN', '127.0.0.1/8,::1'))))),
            'state_file' => storage_path('app/ops-auto-ban-state.json'),
        ],
    ],
];
