<?php

namespace App\Http\Controllers\Admin\Ops;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Ops\AlertNoteStoreRequest;
use App\Models\OpsAlert;
use App\Services\Ops\OpsAlertNoteService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AlertNoteController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly OpsAlertNoteService $notes) {}

    public function index(OpsAlert $alert): JsonResponse
    {
        return $this->success(['items' => $this->notes->list($alert)]);
    }

    public function store(AlertNoteStoreRequest $request, OpsAlert $alert): JsonResponse
    {
        $note = $this->notes->add($alert, $request->user('admin'), (string) $request->validated('body'));

        return $this->success(['note' => [
            'id' => $note->id,
            'author' => $note->author,
            'admin_user_id' => $note->admin_user_id,
            'body' => $note->body,
            'created_at' => optional($note->created_at)->toDateTimeString(),
        ]]);
    }

    public function destroy(Request $request, OpsAlert $alert, int $note): JsonResponse
    {
        return $this->success(['deleted' => $this->notes->delete($request->user('admin'), $note)]);
    }
}
