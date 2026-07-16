<?php

namespace App\Http\Controllers\Admin\Security;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Security\AdminAuditIndexRequest;
use App\Models\AdminAuditLog;
use App\Services\Admin\AdminAuditService;
use App\Services\Admin\AdminCsvExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminAuditLogController extends Controller
{
    public function index(AdminAuditIndexRequest $request): JsonResponse
    {
        $filters = $request->validated();
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

    public function export(
        AdminAuditIndexRequest $request,
        AdminCsvExportService $csv,
        AdminAuditService $audit,
    ): StreamedResponse
    {
        $filters = $request->validated();
        $rows = $this->query($filters)
            ->orderByDesc('id')
            ->limit(5000)
            ->get()
            ->map(fn (AdminAuditLog $log): array => [
                $log->id,
                $log->admin_user_id,
                $log->admin_email,
                $log->module,
                $log->action,
                $log->result,
                $log->status_code,
                $this->targetSummary($log, $csv),
                $log->ip_address,
                optional($log->created_at)->toDateTimeString(),
                json_encode($audit->sanitizePayload($log->payload ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ])
            ->all();

        return $csv->stream('admin-audit-logs-'.now()->format('Ymd-His').'.csv', [
            'id',
            'admin_user_id',
            'admin_email',
            'module',
            'action',
            'result',
            'status_code',
            'target',
            'ip_address',
            'created_at',
            'payload_summary',
        ], $rows);
    }

    private function query(array $filters): Builder
    {
        return AdminAuditLog::query()
            ->with('admin:id,name,email')
            ->when(isset($filters['admin_user_id']), fn ($query) => $query->where('admin_user_id', $filters['admin_user_id']))
            ->when(($filters['module'] ?? '') !== '', fn ($query) => $query->where('module', $filters['module']))
            ->when(($filters['action'] ?? '') !== '', fn ($query) => $query->where('action', $filters['action']))
            ->when(($filters['result'] ?? '') !== '', fn ($query) => $query->where('result', $filters['result']))
            ->when(isset($filters['status_code']), fn ($query) => $query->where('status_code', $filters['status_code']))
            ->when(($filters['from'] ?? '') !== '', fn ($query) => $query->where('created_at', '>=', $filters['from']))
            ->when(($filters['to'] ?? '') !== '', fn ($query) => $query->where('created_at', '<=', $filters['to']));
    }

    private function targetSummary(AdminAuditLog $log, AdminCsvExportService $csv): string
    {
        if (! $log->target_type && ! $log->target_id) {
            return '';
        }

        if (! $log->target_type) {
            return $csv->escapeCell($log->target_id);
        }

        return $log->target_type.($log->target_id ? ':'.$csv->escapeCell($log->target_id) : '');
    }
}
