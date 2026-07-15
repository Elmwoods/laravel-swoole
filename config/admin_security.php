<?php

return [
    'password_crypto' => [
        'private_key' => env('ADMIN_PASSWORD_PRIVATE_KEY'),
        'storage_path' => storage_path('app/private/admin-password-private.pem'),
    ],
];
