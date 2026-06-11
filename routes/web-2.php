<?php

use Illuminate\Support\Facades\Route;


Route::get('/', function () {
    return view('welcome');
});

/*
|--------------------------------------------------------------------------
| 演示 1: 常驻内存中的全局变量污染
|--------------------------------------------------------------------------
*/

// 在 Laravel 中，直接使用全局变量或静态属性会跨请求共享
// 这是 Swoole 常驻内存模式下最容易犯的错误

Route::get('/counter-bad', function () {
    // ⚠️ 危险: 静态变量会一直存在于内存中，所有请求共享
    static $counter = 0;
    $counter++;

    return [
        'pid' => getmypid(),          // 当前 Worker 进程 ID
        'counter' => $counter,        // 每次刷新都会增加
        'warning' => '这是所有请求共享的计数器，不是用户独立的！'
    ];
});

// 正确的做法: 使用 session 或 cache 存储请求级别的数据
Route::get('/counter-good', function () {
    // ✅ 安全: 使用 Laravel 的 session（默认 file 驱动，不跨请求污染）
    $counter = session('counter', 0);
    $counter++;
    session(['counter' => $counter]);

    return [
        'pid' => getmypid(),
        'counter' => $counter,
        'note' => '这是当前用户的计数器（基于 session），不会与其他用户混淆'
    ];
});

/*
|--------------------------------------------------------------------------
| 演示 2: 查看 Worker 进程的 PID 和生命周期
|--------------------------------------------------------------------------
*/

Route::get('/pid', function () {
    return [
        'worker_pid' => getmypid(),
        'php_version' => PHP_VERSION,
        'swoole_loaded' => extension_loaded('swoole'),
        'octane_server' => config('octane.server', '未知'),
    ];
});

//// 手动触发 Worker 重启（仅开发测试用）
//Route::get('/reload-worker', function () {
//    if (app()->environment('local')) {
//        // 通知 Octane 重启所有 Worker
//        \Laravel\Octane\Facades\Octane::terminate();
//        return '正在重启 Worker 进程，请刷新页面查看 PID 变化';
//    }
//    abort(403);
//});

Route::get('/reload-worker', function () {

    $artisan = base_path('artisan');

    exec(
        "php {$artisan} octane:reload 2>&1",
        $output,
        $code
    );

    return response()->json([
        'code' => $code,
        'output' => $output,
    ]);
});

Route::get('/test-cwd', function () {
    return [
        'cwd' => getcwd(),
        'base_path' => base_path(),
    ];
});

Route::get('/octane-test', function () {
    return [
        'commands_exist' => array_key_exists('octane:reload', Artisan::all()),
        'commands' => array_keys(Artisan::all()),
    ];
});
