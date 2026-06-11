<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/test', function () {
    // 打印当前进程ID，每次刷新，ID都不会改变，以此验证应用是否常驻内存
    return 'Worker PID: ' . getmypid();
});
