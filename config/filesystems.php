<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    // 默认文件系统磁盘，读取 FILESYSTEM_DISK，默认 "local"
    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    // 磁盘定义：可配置任意数量的磁盘（可同驱动多实例）
    'disks' => [

        // 私有本地磁盘：根目录 storage/app/private，不对外公开访问
        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,        // 是否允许通过内置路由提供文件访问
            'throw' => false,       // 操作失败时是否抛异常（false 则静默返回失败）
            'report' => false,      // 是否上报操作异常
        ],

        // 公开本地磁盘：根目录 storage/app/public，通过 /storage 对外访问
        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            // 对外访问 URL：以 APP_URL 为基准（去掉末尾斜杠）拼接 /storage
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',   // 文件可见性为公开
            'throw' => false,
            'report' => false,
        ],

        // AWS S3（或兼容对象存储）磁盘
        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),          // 访问密钥
            'secret' => env('AWS_SECRET_ACCESS_KEY'),   // 密钥
            'region' => env('AWS_DEFAULT_REGION'),      // 区域
            'bucket' => env('AWS_BUCKET'),              // 存储桶名
            'url' => env('AWS_URL'),                    // 自定义访问 URL
            'endpoint' => env('AWS_ENDPOINT'),          // 自定义端点（用于 S3 兼容服务，如 MinIO）
            // 是否使用 path-style 端点（如 endpoint/bucket），读取 AWS_USE_PATH_STYLE_ENDPOINT，默认 false
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    // 符号链接：执行 `php artisan storage:link` 时创建，键为链接位置，值为目标目录
    'links' => [
        // public/storage -> storage/app/public
        public_path('storage') => storage_path('app/public'),
    ],

];
