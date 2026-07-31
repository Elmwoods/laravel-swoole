<?php

namespace App\Services\Ops;

use App\Models\AdminUser;
use App\Models\OpsAlert;
use App\Models\OpsAlertNote;

/**
 * 告警处理备注：与状态事件（ops_alert_events）分离的自由文本协作记录。仅作者可删。
 *
 * 在告警 raise/通知 notify/广播 broadcast 流水线中的定位：
 * - 本类不参与 raise（生成告警）与 notify/broadcast（对外发送）阶段；
 * - 它服务于告警"落库之后"的人工协作环节：值班人员在处理告警时留下的自由文本讨论。
 * - 刻意与结构化的状态事件表（ops_alert_events，记录确认/静音/解决等状态流转）分开存储，
 *   备注是纯人工评论，不驱动任何自动化通知或状态机，避免污染事件时间线。
 * 「为什么」这样设计：状态事件需要机器可读、可审计；而备注是松散的人类沟通，
 *   两者混在一张表里会让事件流噪声过大，因此拆成独立的 ops_alert_notes。
 */
class OpsAlertNoteService
{
    /**
     * 作用：列出指定告警下的全部处理备注（按 id 倒序，最新在前）。
     *
     * @param  OpsAlert  $alert  目标告警模型（仅用其 id 作为过滤条件）
     * @return array<int, array<string, mixed>> 备注数组，每项含 id/author/admin_user_id/body/created_at
     */
    public function list(OpsAlert $alert): array
    {
        // 仅按 alert_id 过滤该告警的备注；orderByDesc('id') 让最新备注排在最前，符合评论区阅读习惯
        return OpsAlertNote::query()
            ->where('alert_id', $alert->id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (OpsAlertNote $note): array => [
                'id' => $note->id,
                'author' => $note->author,
                'admin_user_id' => $note->admin_user_id,
                'body' => $note->body,
                // optional() 兜底：created_at 可能为 null（极端情况下未落时间戳），避免直接调用方法报错
                'created_at' => optional($note->created_at)->toDateTimeString(),
            ])
            ->all();
    }

    /**
     * 作用：为指定告警新增一条处理备注。
     *
     * @param  OpsAlert  $alert  被评论的告警（提供 alert_id 外键）
     * @param  AdminUser  $admin  评论作者（同时冗余记录其 id 与展示名）
     * @param  string  $body  备注正文（会去除首尾空白）
     * @return OpsAlertNote 新建的备注模型
     */
    public function add(OpsAlert $alert, AdminUser $admin, string $body): OpsAlertNote
    {
        return OpsAlertNote::query()->create([
            'alert_id' => $alert->id,
            'admin_user_id' => $admin->id,
            // 冗余存储作者展示名（author）：即便日后 AdminUser 改名或被删，历史备注仍能显示当时的署名
            'author' => $admin->name,
            'body' => trim($body), // 去除首尾空白，避免存入纯空格或换行导致的"空评论"
        ]);
    }

    /**
     * 作用：删除一条备注，且只允许原作者删除。
     *
     * @param  AdminUser  $admin  发起删除的当前用户（其 id 作为归属校验条件）
     * @param  int  $noteId  待删除备注的主键 id
     * @return bool 实际删除了记录返回 true；否则（不存在或非本人）返回 false
     *
     * 「为什么」把作者归属放进 WHERE 而不是先查再判断：
     *   将 admin_user_id 与主键一起放进删除条件，非作者的删除会命中 0 行、静默 no-op，
     *   既避免了额外一次查询，也天然防止越权删他人备注，无需显式抛授权异常。
     */
    public function delete(AdminUser $admin, int $noteId): bool
    {
        // admin_user_id 归属校验与主键条件同时生效；delete() 返回受影响行数，>0 表示确实删除了本人备注
        return OpsAlertNote::query()
            ->where('admin_user_id', $admin->id)
            ->whereKey($noteId)
            ->delete() > 0;
    }
}
