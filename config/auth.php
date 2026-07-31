<?php

use App\Models\AdminUser;
use App\Models\User;

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | This option defines the default authentication "guard" and password
    | reset "broker" for your application. You may change these values
    | as required, but they're a perfect start for most applications.
    |
    */

    // 认证默认项：定义默认使用的守卫（guard）与密码重置代理（broker）
    'defaults' => [
        // 默认认证守卫，读取 AUTH_GUARD，默认 "web"；对应下方 guards 中的键
        'guard' => env('AUTH_GUARD', 'web'),
        // 默认密码重置代理，读取 AUTH_PASSWORD_BROKER，默认 "users"；对应下方 passwords 中的键
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Guards
    |--------------------------------------------------------------------------
    |
    | Next, you may define every authentication guard for your application.
    | Of course, a great default configuration has been defined for you
    | which utilizes session storage plus the Eloquent user provider.
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | Supported: "session"
    |
    */

    // 认证守卫：定义各守卫如何维持用户登录状态（此处均基于 session）
    'guards' => [
        // 前台用户守卫：基于 session 存储，用户来源为 users provider
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
        // 后台管理员守卫：基于 session 存储，用户来源为 admin_users provider（AdminUser 模型）
        'admin' => [
            'driver' => 'session',
            'provider' => 'admin_users',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Providers
    |--------------------------------------------------------------------------
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | If you have multiple user tables or models you may configure multiple
    | providers to represent the model / table. These providers may then
    | be assigned to any extra authentication guards you have defined.
    |
    | Supported: "database", "eloquent"
    |
    */

    // 用户提供者：定义如何从数据库/存储中检索用户（此处均使用 Eloquent）
    'providers' => [
        // 前台用户提供者：使用 Eloquent，模型读取 AUTH_MODEL，默认 App\Models\User
        'users' => [
            'driver' => 'eloquent',
            'model' => env('AUTH_MODEL', User::class),
        ],

        // 后台管理员提供者：使用 Eloquent，固定映射到 App\Models\AdminUser 模型
        'admin_users' => [
            'driver' => 'eloquent',
            'model' => AdminUser::class,
        ],

        // 'users' => [
        //     'driver' => 'database',
        //     'table' => 'users',
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resetting Passwords
    |--------------------------------------------------------------------------
    |
    | These configuration options specify the behavior of Laravel's password
    | reset functionality, including the table utilized for token storage
    | and the user provider that is invoked to actually retrieve users.
    |
    | The expiry time is the number of minutes that each reset token will be
    | considered valid. This security feature keeps tokens short-lived so
    | they have less time to be guessed. You may change this as needed.
    |
    | The throttle setting is the number of seconds a user must wait before
    | generating more password reset tokens. This prevents the user from
    | quickly generating a very large amount of password reset tokens.
    |
    */

    // 密码重置配置：控制密码重置令牌的存储表、有效期与节流
    'passwords' => [
        'users' => [
            // 关联的用户提供者
            'provider' => 'users',
            // 令牌存储表，读取 AUTH_PASSWORD_RESET_TOKEN_TABLE，默认 "password_reset_tokens"
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            // 令牌有效期（分钟），此处为 60 分钟
            'expire' => 60,
            // 节流时间（秒）：两次生成重置令牌之间必须等待的秒数，此处为 60 秒
            'throttle' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Confirmation Timeout
    |--------------------------------------------------------------------------
    |
    | Here you may define the number of seconds before a password confirmation
    | window expires and users are asked to re-enter their password via the
    | confirmation screen. By default, the timeout lasts for three hours.
    |
    */

    // 密码确认窗口超时（秒），读取 AUTH_PASSWORD_TIMEOUT，默认 10800（3 小时）；
    // 超时后用户需在确认屏重新输入密码
    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

];
