<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection Name
    |--------------------------------------------------------------------------
    |
    | Laravel's queue supports a variety of backends via a single, unified
    | API, giving you convenient access to each backend using identical
    | syntax for each. The default queue connection is defined below.
    |
    */

    // 默认队列连接，读取 QUEUE_CONNECTION，默认 "database"
    'default' => env('QUEUE_CONNECTION', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    |
    | Here you may configure the connection options for every queue backend
    | used by your application. An example configuration is provided for
    | each backend supported by Laravel. You're also free to add more.
    |
    | Drivers: "sync", "database", "beanstalkd", "sqs", "redis",
    |          "deferred", "background", "failover", "null"
    |
    */

    // 队列连接定义：每种队列后端一份配置
    'connections' => [

        // sync 驱动：同步执行任务（不入队，立即运行），本地调试用
        'sync' => [
            'driver' => 'sync',
        ],

        // database 驱动：任务存入数据库表
        'database' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),         // 使用的数据库连接，为空则用默认
            'table' => env('DB_QUEUE_TABLE', 'jobs'),           // 任务表名，默认 "jobs"
            'queue' => env('DB_QUEUE', 'default'),              // 队列名，默认 "default"
            // 任务被视为超时并可重试前的等待秒数，读取 DB_QUEUE_RETRY_AFTER，默认 90
            'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 90),
            'after_commit' => false,                            // 是否等数据库事务提交后再派发任务
        ],

        // beanstalkd 驱动
        'beanstalkd' => [
            'driver' => 'beanstalkd',
            'host' => env('BEANSTALKD_QUEUE_HOST', 'localhost'),    // 主机，默认 localhost
            'queue' => env('BEANSTALKD_QUEUE', 'default'),          // 队列名（tube），默认 default
            'retry_after' => (int) env('BEANSTALKD_QUEUE_RETRY_AFTER', 90), // 重试等待秒数，默认 90
            'block_for' => 0,                                       // 阻塞等待新任务的秒数，0 表示不阻塞
            'after_commit' => false,
        ],

        // Amazon SQS 驱动
        'sqs' => [
            'driver' => 'sqs',
            'key' => env('AWS_ACCESS_KEY_ID'),                  // AWS 访问密钥
            'secret' => env('AWS_SECRET_ACCESS_KEY'),           // AWS 密钥
            // 队列 URL 前缀，读取 SQS_PREFIX，默认示例账号 URL
            'prefix' => env('SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'),
            'queue' => env('SQS_QUEUE', 'default'),             // 队列名，默认 default
            'suffix' => env('SQS_SUFFIX'),                      // 队列名后缀
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'), // 区域，默认 us-east-1
            'after_commit' => false,
        ],

        // redis 驱动
        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'), // 使用的 redis 连接，默认 default
            'queue' => env('REDIS_QUEUE', 'default'),               // 队列名，默认 default
            'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 90), // 重试等待秒数，默认 90
            'block_for' => null,                                    // 阻塞等待秒数，null 表示不阻塞
            'after_commit' => false,
        ],

        // deferred 驱动：延迟到当前请求响应发送后再执行
        'deferred' => [
            'driver' => 'deferred',
        ],

        // background 驱动：在后台进程中执行
        'background' => [
            'driver' => 'background',
        ],

        // failover 驱动：database 不可用时降级到 deferred
        'failover' => [
            'driver' => 'failover',
            'connections' => [
                'database',
                'deferred',
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Job Batching
    |--------------------------------------------------------------------------
    |
    | The following options configure the database and table that store job
    | batching information. These options can be updated to any database
    | connection and table which has been defined by your application.
    |
    */

    // 任务批处理：存储批次信息的数据库与表
    'batching' => [
        'database' => env('DB_CONNECTION', 'sqlite'),   // 使用的数据库连接，默认 sqlite
        'table' => 'job_batches',                       // 批次信息表名
    ],

    /*
    |--------------------------------------------------------------------------
    | Failed Queue Jobs
    |--------------------------------------------------------------------------
    |
    | These options configure the behavior of failed queue job logging so you
    | can control how and where failed jobs are stored. Laravel ships with
    | support for storing failed jobs in a simple file or in a database.
    |
    | Supported drivers: "database-uuids", "dynamodb", "file", "null"
    |
    */

    // 失败任务记录：控制失败任务如何/在哪里存储
    'failed' => [
        // 失败任务驱动，读取 QUEUE_FAILED_DRIVER，默认 "database-uuids"
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'sqlite'),   // 使用的数据库连接，默认 sqlite
        'table' => 'failed_jobs',                       // 失败任务表名
    ],

];
