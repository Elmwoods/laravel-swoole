<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Reverb Server
    |--------------------------------------------------------------------------
    |
    | This option controls the default server used by Reverb to handle
    | incoming messages as well as broadcasting message to all your
    | connected clients. At this time only "reverb" is supported.
    |
    */

    // 默认 Reverb 服务器，读取 REVERB_SERVER，默认 "reverb"（目前仅支持 reverb）
    'default' => env('REVERB_SERVER', 'reverb'),

    /*
    |--------------------------------------------------------------------------
    | Reverb Servers
    |--------------------------------------------------------------------------
    |
    | Here you may define details for each of the supported Reverb servers.
    | Each server has its own configuration options that are defined in
    | the array below. You should ensure all the options are present.
    |
    */

    // Reverb 服务器定义
    'servers' => [

        'reverb' => [
            // 监听主机，读取 REVERB_SERVER_HOST，默认 0.0.0.0（所有网卡）
            'host' => env('REVERB_SERVER_HOST', '0.0.0.0'),
            // 监听端口，读取 REVERB_SERVER_PORT，默认 6001
            'port' => env('REVERB_SERVER_PORT', 6001),
            // 服务路径前缀，读取 REVERB_SERVER_PATH，默认空
            'path' => env('REVERB_SERVER_PATH', ''),
            // 对外主机名（供客户端连接），读取 REVERB_HOST
            'hostname' => env('REVERB_HOST'),
            'options' => [
                'tls' => [],    // TLS 相关选项（如证书路径），空表示不启用
            ],
            // 单个请求最大字节数，读取 REVERB_MAX_REQUEST_SIZE，默认 10000
            'max_request_size' => env('REVERB_MAX_REQUEST_SIZE', 10_000),
            // 水平扩展（多节点通过 Redis 广播同步）配置
            'scaling' => [
                // 是否启用扩展，读取 REVERB_SCALING_ENABLED，默认 false
                'enabled' => env('REVERB_SCALING_ENABLED', false),
                // 节点间同步用的 Redis 频道，读取 REVERB_SCALING_CHANNEL，默认 "reverb"
                'channel' => env('REVERB_SCALING_CHANNEL', 'reverb'),
                // 扩展所用的 Redis 连接参数
                'server' => [
                    'url' => env('REDIS_URL'),                  // 完整 Redis URL
                    'host' => env('REDIS_HOST', '127.0.0.1'),  // 主机，默认 127.0.0.1
                    'port' => env('REDIS_PORT', '6379'),       // 端口，默认 6379
                    'username' => env('REDIS_USERNAME'),       // 用户名
                    'password' => env('REDIS_PASSWORD'),       // 密码
                    'database' => env('REDIS_DB', '0'),        // 逻辑库编号，默认 0
                    'timeout' => env('REDIS_TIMEOUT', 60),     // 连接超时（秒），默认 60
                ],
            ],
            // 向 Laravel Pulse 上报数据的间隔（秒），默认 15
            'pulse_ingest_interval' => env('REVERB_PULSE_INGEST_INTERVAL', 15),
            // 向 Laravel Telescope 上报数据的间隔（秒），默认 15
            'telescope_ingest_interval' => env('REVERB_TELESCOPE_INGEST_INTERVAL', 15),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Reverb Applications
    |--------------------------------------------------------------------------
    |
    | Here you may define how Reverb applications are managed. If you choose
    | to use the "config" provider, you may define an array of apps which
    | your server will support, including their connection credentials.
    |
    */

    // Reverb 应用管理：定义服务器支持哪些应用及其连接凭据
    'apps' => [

        // 应用来源提供者，"config" 表示应用列表直接来自本配置文件
        'provider' => 'config',

        'apps' => [
            [
                // 应用凭据（key/secret/app_id），均来自环境变量
                'key' => env('REVERB_APP_KEY'),
                'secret' => env('REVERB_APP_SECRET'),
                'app_id' => env('REVERB_APP_ID'),
                'options' => [
                    'host' => env('REVERB_HOST'),                   // 主机名
                    'port' => env('REVERB_PORT', 443),              // 端口，默认 443
                    'scheme' => env('REVERB_SCHEME', 'https'),      // 协议，默认 https
                    'useTLS' => env('REVERB_SCHEME', 'https') === 'https', // scheme 为 https 时启用 TLS
                ],
                // 允许连接的来源域名白名单，['*'] 表示允许全部
                'allowed_origins' => ['*'],
                // 心跳 ping 间隔（秒），默认 60
                'ping_interval' => env('REVERB_APP_PING_INTERVAL', 60),
                // 连接无活动多少秒后判定超时，默认 30
                'activity_timeout' => env('REVERB_APP_ACTIVITY_TIMEOUT', 30),
                // 最大并发连接数，读取 REVERB_APP_MAX_CONNECTIONS，为空表示不限制
                'max_connections' => env('REVERB_APP_MAX_CONNECTIONS'),
                // 单条消息最大字节数，默认 10000
                'max_message_size' => env('REVERB_APP_MAX_MESSAGE_SIZE', 10_000),
                // 接受客户端事件的来源，默认 "members"（仅 presence 频道成员）
                'accept_client_events_from' => env('REVERB_APP_ACCEPT_CLIENT_EVENTS_FROM', 'members'),
                // 限流配置
                'rate_limiting' => [
                    // 是否启用限流，默认 false
                    'enabled' => env('REVERB_APP_RATE_LIMITING_ENABLED', false),
                    // 时间窗内最大尝试次数，默认 60
                    'max_attempts' => env('REVERB_APP_RATE_LIMIT_MAX_ATTEMPTS', 60),
                    // 限流时间窗（秒），默认 60
                    'decay_seconds' => env('REVERB_APP_RATE_LIMIT_DECAY_SECONDS', 60),
                    // 超限时是否直接断开连接，默认 false
                    'terminate_on_limit' => env('REVERB_APP_RATE_LIMIT_TERMINATE', false),
                ],
            ],
        ],

    ],

];
