<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Cache Store
    |--------------------------------------------------------------------------
    |
    | This option controls the default cache store that will be used by the
    | framework. This connection is utilized if another isn't explicitly
    | specified when running a cache operation inside the application.
    |
    */

    // 默认缓存存储，读取 CACHE_STORE，默认 "database"；未显式指定时所有缓存操作使用它
    'default' => env('CACHE_STORE', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Cache Stores
    |--------------------------------------------------------------------------
    |
    | Here you may define all of the cache "stores" for your application as
    | well as their drivers. You may even define multiple stores for the
    | same cache driver to group types of items stored in your caches.
    |
    | Supported drivers: "array", "database", "file", "memcached",
    |                    "redis", "dynamodb", "storage", "octane",
    |                    "session", "failover", "null"
    |
    */

    // 缓存存储列表：定义各缓存 store 及其驱动
    'stores' => [

        // array 驱动：仅存于当前进程内存，请求结束即失效；serialize=false 表示不序列化值
        'array' => [
            'driver' => 'array',
            'serialize' => false,
        ],

        // database 驱动：将缓存存入数据库表
        'database' => [
            'driver' => 'database',
            // 使用的数据库连接，读取 DB_CACHE_CONNECTION，为空则用默认连接
            'connection' => env('DB_CACHE_CONNECTION'),
            // 缓存数据表名，读取 DB_CACHE_TABLE，默认 "cache"
            'table' => env('DB_CACHE_TABLE', 'cache'),
            // 原子锁使用的连接，读取 DB_CACHE_LOCK_CONNECTION
            'lock_connection' => env('DB_CACHE_LOCK_CONNECTION'),
            // 原子锁使用的数据表，读取 DB_CACHE_LOCK_TABLE
            'lock_table' => env('DB_CACHE_LOCK_TABLE'),
        ],

        // file 驱动：将缓存写入文件，路径与锁路径均为 storage/framework/cache/data
        'file' => [
            'driver' => 'file',
            'path' => storage_path('framework/cache/data'),
            'lock_path' => storage_path('framework/cache/data'),
        ],

        // storage 驱动：将缓存写入某个文件系统 disk
        'storage' => [
            'driver' => 'storage',
            // 使用的文件系统磁盘，读取 CACHE_STORAGE_DISK
            'disk' => env('CACHE_STORAGE_DISK'),
            // 磁盘内的存储路径，读取 CACHE_STORAGE_PATH，默认 "framework/cache/data"
            'path' => env('CACHE_STORAGE_PATH', 'framework/cache/data'),
        ],

        // memcached 驱动
        'memcached' => [
            'driver' => 'memcached',
            // 持久连接 ID，读取 MEMCACHED_PERSISTENT_ID
            'persistent_id' => env('MEMCACHED_PERSISTENT_ID'),
            // SASL 认证的用户名/密码（来自环境变量）
            'sasl' => [
                env('MEMCACHED_USERNAME'),
                env('MEMCACHED_PASSWORD'),
            ],
            'options' => [
                // Memcached::OPT_CONNECT_TIMEOUT => 2000,
            ],
            // 服务器节点列表
            'servers' => [
                [
                    // 主机，默认 127.0.0.1；端口，默认 11211；权重 100
                    'host' => env('MEMCACHED_HOST', '127.0.0.1'),
                    'port' => env('MEMCACHED_PORT', 11211),
                    'weight' => 100,
                ],
            ],
        ],

        // redis 驱动
        'redis' => [
            'driver' => 'redis',
            // 使用的 redis 连接（见 database.php 的 redis 段），读取 REDIS_CACHE_CONNECTION，默认 "cache"
            'connection' => env('REDIS_CACHE_CONNECTION', 'cache'),
            // 原子锁使用的 redis 连接，读取 REDIS_CACHE_LOCK_CONNECTION，默认 "default"
            'lock_connection' => env('REDIS_CACHE_LOCK_CONNECTION', 'default'),
        ],

        // AWS DynamoDB 驱动
        'dynamodb' => [
            'driver' => 'dynamodb',
            // AWS 凭据与区域（来自环境变量，region 默认 us-east-1）
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            // DynamoDB 缓存表名，读取 DYNAMODB_CACHE_TABLE，默认 "cache"
            'table' => env('DYNAMODB_CACHE_TABLE', 'cache'),
            // 自定义端点（如本地 DynamoDB），读取 DYNAMODB_ENDPOINT
            'endpoint' => env('DYNAMODB_ENDPOINT'),
        ],

        // octane 驱动：使用 Octane（Swoole/RoadRunner）内存表作为缓存
        'octane' => [
            'driver' => 'octane',
        ],

        // failover 驱动：按顺序降级，database 不可用时回退到 array
        'failover' => [
            'driver' => 'failover',
            'stores' => [
                'database',
                'array',
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Key Prefix
    |--------------------------------------------------------------------------
    |
    | When utilizing the APC, database, memcached, Redis, and DynamoDB cache
    | stores, there might be other applications using the same cache. For
    | that reason, you may prefix every cache key to avoid collisions.
    |
    */

    // 缓存键前缀，读取 CACHE_PREFIX，默认由应用名 slug 拼成 "<app>-cache-"；避免多应用共享缓存时键名冲突
    'prefix' => env('CACHE_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')).'-cache-'),

    /*
    |--------------------------------------------------------------------------
    | Serializable Classes
    |--------------------------------------------------------------------------
    |
    | This value determines the classes that can be unserialized from cache
    | storage. By default, no PHP classes will be unserialized from your
    | cache to prevent gadget chain attacks if your APP_KEY is leaked.
    |
    */

    // 允许从缓存反序列化的类白名单：默认 false（不允许任何 PHP 类），防止 APP_KEY 泄露时的 gadget chain 攻击
    'serializable_classes' => false,

];
