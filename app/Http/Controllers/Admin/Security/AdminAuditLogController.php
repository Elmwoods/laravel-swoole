<?php

namespace App\Http\Controllers\Admin\Security;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Security\AdminAuditIndexRequest;
use App\Models\AdminAuditLog;
use Illuminate\Http\JsonResponse;

class AdminAuditLogController extends Controller
{
    public function index(AdminAuditIndexRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $logs = AdminAuditLog::query()
            ->with('admin:id,name,email')
            ->when(isset($filters['admin_user_id']), fn ($query) => $query->where('admin_user_id', $filters['admin_user_id']))
            ->when(($filters['module'] ?? '') !== '', fn ($query) => $query->where('module', $filters['module']))
            ->when(($filters['action'] ?? '') !== '', fn ($query) => $query->where('action', $filters['action']))
            ->when(($filters['result'] ?? '') !== '', fn ($query) => $query->where('result', $filters['result']))
            ->when(($filters['from'] ?? '') !== '', fn ($query) => $query->where('created_at', '>=', $filters['from']))
            ->when(($filters['to'] ?? '') !== '', fn ($query) => $query->where('created_at', '<=', $filters['to']))
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
}
