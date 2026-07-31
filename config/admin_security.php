<?php

// 后台管理端安全相关的自定义配置（非 Laravel 框架自带，属于本应用的安全模块）
return [
    // 后台管理员密码的加解密配置。
    // 前端提交管理员密码时会用 RSA 公钥加密，后端用此处配置的私钥解密后再做校验，
    // 避免密码在传输过程中以明文出现（配合 HTTPS 提供纵深防御）。
    'password_crypto' => [
        // RSA 私钥内容（PEM 格式字符串），通过环境变量 ADMIN_PASSWORD_PRIVATE_KEY 注入；
        // 未设置时为 null，此时应回退到读取下方 storage_path 指向的私钥文件。
        'private_key' => env('ADMIN_PASSWORD_PRIVATE_KEY'),
        // 私钥文件的磁盘路径：storage/app/private/admin-password-private.pem。
        // 当未通过环境变量提供私钥内容时，从该文件加载私钥。
        'storage_path' => storage_path('app/private/admin-password-private.pem'),
    ],
];
