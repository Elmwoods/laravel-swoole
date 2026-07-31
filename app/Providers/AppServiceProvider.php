<?php

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * 应用核心服务提供者。
 *
 * 职责：
 * - register()：当前未注册任何容器绑定（占位，预留后续扩展）。
 * - boot()：负责路由的注册与加载，是本类的主要职责。
 *   - 为 routes/api.php 中的路由套用 'api' 中间件组，并统一加上 'api' URL 前缀。
 *   - 为 routes/web.php 中的路由套用 'web' 中间件组（会话、CSRF 等），不加前缀。
 */
class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
        // 注册 API 路由：套用 api 中间件组，并为所有路由加上 /api 前缀
        Route::middleware('api')
            ->prefix('api')
            ->group(base_path('routes/api.php'));

        // 注册 Web 路由：套用 web 中间件组（含会话、CSRF 保护等），无前缀
        Route::middleware('web')
            ->group(base_path('routes/web.php'));
    }
}
