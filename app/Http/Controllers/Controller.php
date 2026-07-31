<?php

namespace App\Http\Controllers;

use App\Traits\ApiResponse;

/**
 * 所有控制器的抽象基类。
 *
 * 作用：作为项目内全部 HTTP 控制器的公共父类，通过 ApiResponse trait
 * 注入统一的 JSON 返回辅助方法（如 success()），保证各接口返回结构一致。
 * 「为什么」：Laravel 11 起框架不再提供内置的 Controller 基类，需要项目
 * 自行定义；此处集中挂载 ApiResponse，使所有子控制器无需重复引入即可复用。
 */
abstract class Controller
{
    //
    // 引入统一 API 响应 trait，提供 success()/error() 等标准返回方法。
    use ApiResponse;
}
