<?php

use Illuminate\Support\Facades\Route;

// Web 路由：SPA 前端入口（catch-all）。
// 除 api 开头的路径外，所有 GET 请求都返回同一个 app 视图（Blade 里挂载前端单页应用），
// 由前端路由接管页面切换；^(?!api).* 正则确保 /api/* 请求不被这里吞掉，交给 routes/api.php 处理。
Route::get('/{any}', function () {
    return view('app');
})->where('any', '^(?!api).*$');
