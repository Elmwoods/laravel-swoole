<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application, which will be used when the
    | framework needs to place the application's name in a notification or
    | other UI elements where an application name needs to be displayed.
    |
    */

    // 应用名称，读取 APP_NAME 环境变量，默认 "Laravel"；用于通知、界面标题等展示场景
    'name' => env('APP_NAME', 'Laravel'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    // 运行环境标识，读取 APP_ENV，默认 "production"；决定各类服务的配置方式
    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    // 调试模式开关，读取 APP_DEBUG，默认 false；开启后错误页会显示详细堆栈，生产环境务必关闭
    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | the application so that it's available within Artisan commands.
    |
    */

    // 应用根 URL，读取 APP_URL，默认 http://localhost；命令行（Artisan）生成 URL 时以此为基准
    'url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. The timezone
    | is set to "Asia/Shanghai" by default for Beijing time.
    |
    */

    // 默认时区，读取 APP_TIMEZONE，默认 "Asia/Shanghai"（北京时间）；影响 PHP 日期/时间函数
    'timezone' => env('APP_TIMEZONE', 'Asia/Shanghai'),

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by Laravel's translation / localization methods. This option can be
    | set to any locale for which you plan to have translation strings.
    |
    */

    // 默认语言环境，读取 APP_LOCALE，默认 "en"；决定翻译/本地化方法使用的语言
    'locale' => env('APP_LOCALE', 'en'),

    // 回退语言环境，读取 APP_FALLBACK_LOCALE，默认 "en"；当前语言缺少翻译时使用
    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    // Faker 假数据生成器使用的语言，读取 APP_FAKER_LOCALE，默认 "en_US"；用于数据填充/测试
    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is utilized by Laravel's encryption services and should be set
    | to a random, 32 character string to ensure that all encrypted values
    | are secure. You should do this prior to deploying the application.
    |
    */

    // 加密算法，固定为 AES-256-CBC，供 Laravel 加密服务使用
    'cipher' => 'AES-256-CBC',

    // 应用加密密钥，读取 APP_KEY（应为 32 位随机字符串）；用于所有加密操作，部署前必须设置
    'key' => env('APP_KEY'),

    // 历史加密密钥列表：从 APP_PREVIOUS_KEYS（逗号分隔）解析并过滤空值，
    // 用于在轮换 APP_KEY 后仍能解密由旧密钥加密的数据
    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache"
    |
    */

    // 维护模式配置：控制 Laravel "维护模式" 状态的存取方式
    'maintenance' => [
        // 维护模式驱动，读取 APP_MAINTENANCE_DRIVER，默认 "file"；用 "cache" 可跨多台机器共享维护状态
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        // 当 driver 为 "cache" 时使用的缓存存储，读取 APP_MAINTENANCE_STORE，默认 "database"
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

];
