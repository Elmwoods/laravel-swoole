<?php

use Illuminate\Support\Str;
use Pdo\Mysql;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for database operations. This is
    | the connection which will be utilized unless another connection
    | is explicitly specified when you execute a query / statement.
    |
    */

    // 默认数据库连接名，读取 DB_CONNECTION，默认 "sqlite"；未显式指定连接的查询/语句都走它
    'default' => env('DB_CONNECTION', 'sqlite'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Below are all of the database connections defined for your application.
    | An example configuration is provided for each database system which
    | is supported by Laravel. You're free to add / remove connections.
    |
    */

    // 数据库连接定义：每种数据库系统一份示例配置
    'connections' => [

        // SQLite 连接
        'sqlite' => [
            'driver' => 'sqlite',
            // 完整连接 URL（可替代下方各字段），读取 DB_URL
            'url' => env('DB_URL'),
            // 数据库文件路径，读取 DB_DATABASE，默认 database/database.sqlite
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            // 表名前缀（空）
            'prefix' => '',
            // 是否启用外键约束，读取 DB_FOREIGN_KEYS，默认 true
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            // 忙等待超时（毫秒），null 表示用 SQLite 默认
            'busy_timeout' => null,
            // 日志模式（如 WAL），null 表示不设置
            'journal_mode' => null,
            // 同步级别，null 表示用默认
            'synchronous' => null,
            // 事务模式，此处为 DEFERRED（延迟加锁）
            'transaction_mode' => 'DEFERRED',
        ],

        // MySQL 连接
        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DB_URL'),                                  // 完整连接 URL（可替代下方各字段）
            'host' => env('DB_HOST', '127.0.0.1'),                  // 主机，默认 127.0.0.1
            'port' => env('DB_PORT', '3306'),                       // 端口，默认 3306
            'database' => env('DB_DATABASE', 'laravel'),            // 库名，默认 laravel
            'username' => env('DB_USERNAME', 'root'),               // 用户名，默认 root
            'password' => env('DB_PASSWORD', ''),                   // 密码，默认空
            'unix_socket' => env('DB_SOCKET', ''),                  // Unix socket 路径，默认空
            'charset' => env('DB_CHARSET', 'utf8mb4'),              // 字符集，默认 utf8mb4
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'), // 排序规则，默认 utf8mb4_unicode_ci
            'prefix' => '',                                          // 表名前缀（空）
            'prefix_indexes' => true,                               // 是否给索引名也加前缀
            'strict' => true,                                       // 严格模式（启用 MySQL strict SQL mode）
            'engine' => null,                                       // 建表存储引擎，null 表示用数据库默认
            // SSL 选项：仅在加载了 pdo_mysql 扩展时生效，通过 MYSQL_ATTR_SSL_CA 指定 CA 证书路径
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                Mysql::ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        // MariaDB 连接（字段含义同 MySQL）
        'mariadb' => [
            'driver' => 'mariadb',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                Mysql::ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        // PostgreSQL 连接
        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),               // PostgreSQL 默认端口 5432
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',                      // 查询时的 schema 搜索路径，默认 public
            'sslmode' => env('DB_SSLMODE', 'prefer'),       // SSL 模式，读取 DB_SSLMODE，默认 prefer
        ],

        // SQL Server 连接
        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', '1433'),               // SQL Server 默认端口 1433
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            // 'encrypt' => env('DB_ENCRYPT', 'yes'),
            // 'trust_server_certificate' => env('DB_TRUST_SERVER_CERTIFICATE', 'false'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run on the database.
    |
    */

    // 迁移记录表：追踪哪些迁移已执行
    'migrations' => [
        // 记录迁移的数据表名
        'table' => 'migrations',
        // 发布厂商迁移时是否更新文件日期
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis is an open source, fast, and advanced key-value store that also
    | provides a richer body of commands than a typical key-value system
    | such as Memcached. You may define your connection settings here.
    |
    */

    // Redis 数据库配置
    'redis' => [

        // Redis 客户端，读取 REDIS_CLIENT，默认 "phpredis"（也可用 "predis"）
        'client' => env('REDIS_CLIENT', 'phpredis'),

        // 全局选项
        'options' => [
            // 集群模式，读取 REDIS_CLUSTER，默认 "redis"（原生集群）
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            // 键前缀，默认由应用名 slug 拼成 "<app>-database-"，避免多应用键冲突
            'prefix' => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')).'-database-'),
            // 是否使用持久连接，读取 REDIS_PERSISTENT，默认 false
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        // 默认连接（一般用于队列、Session 等），使用逻辑库 REDIS_DB（默认 0）
        'default' => [
            'url' => env('REDIS_URL'),                        // 完整连接 URL（可替代下方各字段）
            'host' => env('REDIS_HOST', '127.0.0.1'),         // 主机，默认 127.0.0.1
            'username' => env('REDIS_USERNAME'),              // 用户名（Redis 6+ ACL）
            'password' => env('REDIS_PASSWORD'),              // 密码
            'port' => env('REDIS_PORT', '6379'),              // 端口，默认 6379
            'database' => env('REDIS_DB', '0'),               // 逻辑库编号，默认 0
            'max_retries' => env('REDIS_MAX_RETRIES', 3),     // 最大重试次数，默认 3
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'), // 重试退避算法
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100), // 退避基准（毫秒），默认 100
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),  // 退避上限（毫秒），默认 1000
        ],

        // 缓存专用连接，使用独立逻辑库 REDIS_CACHE_DB（默认 1），与 default 隔离
        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),         // 缓存逻辑库编号，默认 1
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

    ],

];
