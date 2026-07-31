<?php

namespace App\Http\Middleware;

use App\Services\Admin\AdminAuditService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * 后台审计中间件。
 *
 * 职责：作为后台受保护路由的"环绕式"切面，把每一次请求的处理结果
 * （成功 / 失败、HTTP 状态码、异常信息）记录进审计日志。
 * 通过路由中间件参数 module / action 标注被操作的业务模块与动作，
 * 便于后续在安全审计页面按模块、动作维度检索操作轨迹。
 */
class AdminAuditMiddleware
{
    public function __construct(
        private readonly AdminAuditService $audit,
    ) {}

    /**
     * 环绕请求执行并落库审计记录。
     *
     * 流程：
     * 1. 先把路由参数（如 {id}）合并进 request，使审计服务能读取到被操作对象标识；
     * 2. 正常返回时：根据响应状态码判定 result，>=400 视为 failure，否则 success；
     * 3. 抛出异常时：仍记录一条 failure 审计（附带异常消息），再原样重新抛出，
     *    以保证审计"不吞异常"、不改变原有错误处理流程（零功能副作用）。
     *
     * @param  string  $module  被审计的业务模块名（由路由中间件参数传入）
     * @param  string  $action  被审计的动作名（由路由中间件参数传入）
     */
    public function handle(Request $request, Closure $next, string $module, string $action)
    {
        // 将路由标量参数并入 request，供审计服务提取目标资源标识
        $this->mergeScalarRouteParameters($request);

        try {
            $response = $next($request);
            // 依据最终响应状态码判定成功 / 失败（4xx、5xx 记为 failure）
            $statusCode = $response->getStatusCode();
            $this->audit->record(
                request: $request,
                module: $module,
                action: $action,
                result: $statusCode >= 400 ? 'failure' : 'success',
                statusCode: $statusCode,
            );

            return $response;
        } catch (Throwable $e) {
            // 将异常映射为对应 HTTP 状态码后记录 failure，随后重新抛出交由全局异常处理
            $statusCode = $this->statusCodeForException($e);

            $this->audit->record(
                request: $request,
                module: $module,
                action: $action,
                result: 'failure',
                statusCode: $statusCode,
                message: $e->getMessage(),
            );

            throw $e;
        }
    }

    /**
     * 把路由参数中的标量值合并进请求输入。
     *
     * 仅合并标量或 null（如 {id}、{alert}），跳过被隐式模型绑定解析出的对象，
     * 避免把整个 Eloquent 模型塞进 request 输入导致审计取值异常。
     */
    private function mergeScalarRouteParameters(Request $request): void
    {
        $route = $request->route();

        // 控制台命令等无路由场景直接返回
        if ($route === null) {
            return;
        }

        $parameters = [];

        foreach ($route->parameters() as $key => $value) {
            // 只保留标量 / null；对象型（模型绑定结果）一律忽略
            if (is_scalar($value) || $value === null) {
                $parameters[$key] = $value;
            }
        }

        if ($parameters !== []) {
            $request->merge($parameters);
        }
    }

    /**
     * 将异常归类为审计所需的 HTTP 状态码。
     */
    private function statusCodeForException(Throwable $e): int
    {
        // 表单验证失败统一记为 422
        if ($e instanceof ValidationException) {
            return 422;
        }

        // HTTP 异常（403/404/419 等）沿用其自带状态码
        if ($e instanceof HttpExceptionInterface) {
            return $e->getStatusCode();
        }

        // 其余未知异常一律按服务器错误 500 记录
        return 500;
    }
}
