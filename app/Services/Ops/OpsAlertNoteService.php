<?php

namespace App\Services\Ops;

use App\Models\AdminUser;
use App\Models\OpsAlert;
use App\Models\OpsAlertNote;

/**
 * 告警处理备注：与状态事件（ops_alert_events）分离的自由文本协作记录。仅作者可删。
 */
class OpsAlertNoteService
{
    public function list(OpsAlert $alert): array
    {
        return OpsAlertNote::query()
            ->where('alert_id', $alert->id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (OpsAlertNote $note): array => [
                'id' => $note->id,
                'author' => $note->author,
                'admin_user_id' => $note->admin_user_id,
                'body' => $note->body,
                'created_at' => optional($note->created_at)->toDateTimeString(),
            ])
            ->all();
    }

    public function add(OpsAlert $alert, AdminUser $admin, string $body): OpsAlertNote
    {
        return OpsAlertNote::query()->create([
            'alert_id' => $alert->id,
            'admin_user_id' => $admin->id,
            'author' => $admin->name,
            'body' => trim($body),
        ]);
    }

    /**
     * 仅作者可删（owner 在 WHERE，非作者静默 no-op）。
     */
    public function delete(AdminUser $admin, int $noteId): bool
    {
        return OpsAlertNote::query()
            ->where('admin_user_id', $admin->id)
            ->whereKey($noteId)
            ->delete() > 0;
    }
}
