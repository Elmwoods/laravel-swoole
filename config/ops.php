<?php

// 增强 SupervisorService
// 增加配置映射
return [

    'supervisor' => [

        'logs' => [

            'laravel-octane' =>
                storage_path('logs/octane.logs'),

            'queue-default' =>
                storage_path('logs/queue-default.logs'),

            'queue-high' =>
                storage_path('logs/queue-high.logs'),

            'scheduler' =>
                storage_path('logs/scheduler.logs'),
        ],
    ],
];
