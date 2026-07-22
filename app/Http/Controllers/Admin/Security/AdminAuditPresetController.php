<?php

namespace App\Http\Controllers\Admin\Security;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Security\AdminAuditPresetStoreRequest;
use App\Services\Admin\AdminAuditPresetService;
use App\Services\Admin\AdminAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminAuditPresetController extends Controller
{
    public function __construct(
        private readonly AdminAuditPresetService $presets,
        private readonly AdminAuditService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return $this->success([
            'items' => $this->presets->list($request->user('admin')),
        ]);
    }

    public function store(AdminAuditPresetStoreRequest $request): JsonResponse
    {
        $admin = $request->user('admin');
        $preset = $this->presets->save(
            $admin,
            (string) $request->validated('name'),
            (array) $request->validated('filters', []),
        );

        $this->audit->record($request, 'admin.audit', 'preset_create', 'success', 200, admin: $admin, payload: [
            'preset_id' => $preset->id,
            'name' => $preset->name,
        ]);

        return $this->success([
            'preset' => [
                'id' => $preset->id,
                'name' => $preset->name,
                'filters' => (array) $preset->filters,
                'created_at' => optional($preset->created_at)->toDateTimeString(),
            ],
        ]);
    }

    public function destroy(Request $request, int $preset): JsonResponse
    {
        $admin = $request->user('admin');
        $deleted = $this->presets->delete($admin, $preset);

        $this->audit->record($request, 'admin.audit', 'preset_delete', $deleted ? 'success' : 'failure', $deleted ? 200 : 404, admin: $admin, payload: [
            'preset_id' => $preset,
        ]);

        return $this->success([
            'deleted' => $deleted,
        ]);
    }
}
