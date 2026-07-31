<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Broadcaster
    |--------------------------------------------------------------------------
    |
    | This option controls the default broadcaster that will be used by the
    | framework when an event needs to be broadcast. You may set this to
    | any of the connections defined in the "connections" array below.
    |
    | Supported: "reverb", "pusher", "ably", "redis", "logs", "null"
    |
    */

    // 默认广播驱动，读取 BROADCAST_CONNECTION，默认 "null"（不广播）；可选 reverb/pusher/ably/redis/logs
    'default' => env('BROADCAST_CONNECTION', 'null'),

    /*
    |--------------------------------------------------------------------------
    | Broadcast Connections
    |--------------------------------------------------------------------------
    |
    | Here you may define all of the broadcast connections that will be used
    | to broadcast events to other systems or over WebSockets. Samples of
    | each available type of connection are provided inside this array.
    |
    */

    // 广播连接：定义各种向 WebSocket/外部系统广播事件的连接
    'connections' => [

        // Laravel Reverb（官方自建 WebSocket 服务器）连接
        'reverb' => [
            'driver' => 'reverb',
            // 应用凭据，均来自环境变量（key/secret/app_id）
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                // Reverb 服务器主机名
                'host' => env('REVERB_HOST'),
                // 端口，读取 REVERB_PORT，默认 443
                'port' => env('REVERB_PORT', 443),
                // 协议方案，读取 REVERB_SCHEME，默认 "https"
                'scheme' => env('REVERB_SCHEME', 'https'),
                // 是否使用 TLS：当 scheme 为 https 时为 true
                'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
            ],
            'client_options' => [
                // Guzzle client options: https://docs.guzzlephp.org/en/stable/request-options.html
            ],
        ],

        // Pusher（第三方托管 WebSocket 服务）连接
        'pusher' => [
            'driver' => 'pusher',
            // 应用凭据，均来自环境变量
            'key' => env('PUSHER_APP_KEY'),
            'secret' => env('PUSHER_APP_SECRET'),
            'app_id' => env('PUSHER_APP_ID'),
            'options' => [
                // Pusher 集群标识
                'cluster' => env('PUSHER_APP_CLUSTER'),
                // 主机名：优先 PUSHER_HOST，否则按集群拼接为 api-<cluster>.pusher.com（cluster 默认 mt1）
                'host' => env('PUSHER_HOST') ?: 'api-'.env('PUSHER_APP_CLUSTER', 'mt1').'.pusher.com',
                // 端口，读取 PUSHER_PORT，默认 443
                'port' => env('PUSHER_PORT', 443),
                // 协议方案，读取 PUSHER_SCHEME，默认 "https"
                'scheme' => env('PUSHER_SCHEME', 'https'),
                // 是否加密传输
                'encrypted' => true,
                // 是否使用 TLS：当 scheme 为 https 时为 true
                'useTLS' => env('PUSHER_SCHEME', 'https') === 'https',
            ],
            'client_options' => [
                // Guzzle client options: https://docs.guzzlephp.org/en/stable/request-options.html
            ],
        ],

        // Ably（第三方实时消息服务）连接，凭据来自 ABLY_KEY
        'ably' => [
            'driver' => 'ably',
            'key' => env('ABLY_KEY'),
        ],

        // 日志驱动：将广播事件写入日志而非真正推送，便于本地调试
        'logs' => [
            'driver' => 'logs',
        ],

        // 空驱动：丢弃所有广播事件（禁用广播）
        'null' => [
            'driver' => 'null',
        ],

    ],

];
