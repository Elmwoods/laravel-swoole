<?php

namespace App\Http\Controllers\Admin\Security;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Security\AdminAuditIndexRequest;
use App\Models\AdminAuditLog;
use App\Services\Admin\AdminAuditService;
use App\Services\Admin\AdminCsvExportService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 后台审计日志查询控制器。
 *
 * 作用：为后台「安全 - 审计日志」页面提供只读查询能力，服务的路由包括：
 *  - GET  audit-logs         按筛选条件分页查看审计日志（index）
 *  - GET  audit-logs/export  按当前筛选条件流式导出 CSV（export）
 *  - GET  audit-logs/facets  返回可用的筛选枚举值（模块/动作/结果）（facets）
 *
 * 「为什么」：审计日志量可能很大，列表走分页、导出走 lazy 流式并设硬上限，
 * 兼顾可用性与内存安全；本控制器只读，日志写入由各业务处的 AdminAuditService 负责。
 */
class AdminAuditLogController extends Controller
{
    /**
     * 作用：按筛选条件分页返回审计日志列表。
     *
     * @param  AdminAuditIndexRequest  $request  已校验的筛选请求（模块/动作/结果/关键词/时间范围/分页参数）
     * @return JsonResponse items（本页日志）+ pagination（分页元信息）
     *
     * 「为什么」：per_page 被夹在 5~100、page 至少为 1，防止前端传入极端值拖垮数据库。
     */
    public function index(AdminAuditIndexRequest $request): JsonResponse
    {
        $filters = $request->validated();
        // 复用 query() 构建筛选后的查询，按 ID 倒序（最新在前）分页。
        $logs = $this->query($filters)
            ->orderByDesc('id')
            ->paginate(
                perPage: max(5, min((int) ($filters['per_page'] ?? 20), 100)),
                page: max(1, (int) ($filters['page'] ?? 1)),
            );

        return $this->success([
            'items' => collect($logs->items())
                ->map(fn (AdminAuditLog $log): array => [
                    'id' => $log->id,
                    'admin_user_id' => $log->admin_user_id,
                    'admin_email' => $log->admin_email,
                    'admin_name' => $log->admin?->name,
                    'module' => $log->module,
                    'action' => $log->action,
                    'result' => $log->result,
                    'status_code' => $log->status_code,
                    'target_type' => $log->target_type,
                    'target_id' => $log->target_id,
                    'payload' => $log->payload ?? [],
                    'ip_address' => $log->ip_address,
                    'message' => $log->message,
                    'created_at' => optional($log->created_at)->toDateTimeString(),
                ])
                ->all(),
            'pagination' => [
                'current_page' => $logs->currentPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
                'last_page' => $logs->lastPage(),
            ],
        ]);
    }

    /**
     * 导出上限：按当前筛选流式导出全部匹配，硬顶防止极端情况下 OOM。
     */
    private const EXPORT_MAX_ROWS = 100000;

    /**
     * 作用：按当前筛选条件将审计日志流式导出为 CSV 文件。
     *
     * @param  AdminAuditIndexRequest  $request  已校验的筛选请求（与 index 同一套条件）
     * @param  AdminCsvExportService  $csv  CSV 导出服务，负责流式输出与单元格转义
     * @param  AdminAuditService  $audit  审计服务，用于导出前对 payload 做脱敏
     * @return StreamedResponse 以附件形式流式下载的 CSV 响应
     *
     * 「为什么」：用 lazy() 逐行游标读取 + EXPORT_MAX_ROWS 硬顶，避免一次性载入海量行导致 OOM；
     * payload 经 sanitizePayload 脱敏后再序列化，防止把敏感字段导出到文件。
     */
    public function export(
        AdminAuditIndexRequest $request,
        AdminCsvExportService $csv,
        AdminAuditService $audit,
    ): StreamedResponse {
        $filters = $request->validated();
        // 复用同一筛选查询；limit 硬顶导出行数，lazy 逐行游标读取以控内存。
        $rows = $this->query($filters)
            ->orderByDesc('id')
            ->limit(self::EXPORT_MAX_ROWS)
            ->lazy()
            ->map(fn (AdminAuditLog $log): array => [
                $log->id,
                $log->admin_user_id,
                $log->admin_email,
                $log->admin?->name,
                $log->module,
                $log->action,
                $log->result,
                $log->status_code,
                // 目标对象摘要（target_type:target_id，含转义）。
                $this->targetSummary($log, $csv),
                $log->ip_address,
                $log->message,
                optional($log->created_at)->toDateTimeString(),
                // payload 先脱敏再 JSON 序列化（保留中文与斜杠原样），避免导出敏感字段。
                json_encode($audit->sanitizePayload($log->payload ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);

        // 交给 CSV 服务流式输出，文件名带时间戳；第二参为表头，第三参为惰性行集合。
        return $csv->stream('admin-audit-logs-'.now()->format('Ymd-His').'.csv', [
            'id',
            'admin_user_id',
            'admin_email',
            'admin_name',
            'module',
            'action',
            'result',
            'status_code',
            'target',
            'ip_address',
            'message',
            'created_at',
            'payload_summary',
        ], $rows);
    }

    /**
     * 作用：返回审计日志各筛选维度当前可选的枚举值（供前端下拉/标签用）。
     *
     * @return JsonResponse modules/actions/results 三个去重后的取值列表
     *
     * 「为什么」：枚举值来自实际数据而非硬编码，前端筛选项能随真实日志动态变化。
     */
    public function facets(): JsonResponse
    {
        return $this->success([
            'modules' => $this->distinctColumn('module'),
            'actions' => $this->distinctColumn('action'),
            'results' => $this->distinctColumn('result'),
        ]);
    }

    /**
     * 作用：取某列去重、非空、已排序的全部取值。
     *
     * @param  string  $column  列名（module/action/result）
     * @return array 去重排序后的取值数组
     *
     * 「为什么」：过滤掉 NULL 与空字符串，避免脏值污染前端筛选选项。
     */
    private function distinctColumn(string $column): array
    {
        return AdminAuditLog::query()
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->distinct()
            ->orderBy($column)
            ->pluck($column)
            ->all();
    }

    /**
     * 作用：根据筛选数组构建审计日志查询 Builder（供 index/export 共用）。
     *
     * @param  array  $filters  筛选条件（admin_user_id/module/action/result/keyword/status_code/from/to）
     * @return Builder 已挂载各筛选条件、预加载 admin 关联的查询构造器
     *
     * 「为什么」：用 when() 惰性拼接条件，仅当对应筛选有值时才加约束；keyword 走
     * 分组 OR 模糊匹配（邮箱/消息/模块/动作），避免与其它 AND 条件的括号错乱。
     */
    private function query(array $filters): Builder
    {
        return AdminAuditLog::query()
            // 只选取 admin 关联的 id/name/email 三列，减少数据传输。
            ->with('admin:id,name,email')
            ->when(isset($filters['admin_user_id']), fn ($query) => $query->where('admin_user_id', $filters['admin_user_id']))
            ->when(($filters['module'] ?? '') !== '', fn ($query) => $query->where('module', $filters['module']))
            ->when(($filters['action'] ?? '') !== '', fn ($query) => $query->where('action', $filters['action']))
            ->when(($filters['result'] ?? '') !== '', fn ($query) => $query->where('result', $filters['result']))
            // 关键词：在括号内做 OR 模糊匹配，整体作为一个 AND 条件参与，避免逻辑串位。
            ->when(($filters['keyword'] ?? '') !== '', function ($query) use ($filters): void {
                $like = '%'.$filters['keyword'].'%';
                $query->where(function ($inner) use ($like): void {
                    $inner->where('admin_email', 'like', $like)
                        ->orWhere('message', 'like', $like)
                        ->orWhere('module', 'like', $like)
                        ->orWhere('action', 'like', $like);
                });
            })
            ->when(isset($filters['status_code']), fn ($query) => $query->where('status_code', $filters['status_code']))
            ->when(($filters['from'] ?? '') !== '', fn ($query) => $query->where('created_at', '>=', $filters['from']))
            ->when(($filters['to'] ?? '') !== '', fn ($query) => $query->where('created_at', '<=', $filters['to']));
    }

    /**
     * 作用：把日志的操作目标（target_type + target_id）拼成一列可读且防注入的文本。
     *
     * @param  AdminAuditLog  $log  当前日志行
     * @param  AdminCsvExportService  $csv  CSV 服务，用于对单元格做转义（防 CSV 注入）
     * @return string 形如 "type:id" 或 "type"、或裸 id、或空字符串
     *
     * 「为什么」：target_id 可能来自外部输入，导出前经 escapeCell 转义，
     * 防止以 =,+,-,@ 开头的值在电子表格中被当作公式执行（CSV 注入）。
     */
    private function targetSummary(AdminAuditLog $log, AdminCsvExportService $csv): string
    {
        // 既无类型也无 ID：无目标，返回空。
        if (! $log->target_type && ! $log->target_id) {
            return '';
        }

        // 只有 ID 无类型：返回转义后的裸 ID。
        if (! $log->target_type) {
            return $csv->escapeCell($log->target_id);
        }

        // 有类型：附加 ":转义ID"（若存在 ID）。
        return $log->target_type.($log->target_id ? ':'.$csv->escapeCell($log->target_id) : '');
    }
}
