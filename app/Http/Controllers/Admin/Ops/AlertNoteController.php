<?php

namespace App\Http\Controllers\Admin\Ops;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Ops\AlertNoteStoreRequest;
use App\Models\OpsAlert;
use App\Services\Ops\OpsAlertNoteService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 告警处理备注控制器。
 *
 * 作用：承载单条告警下的处理备注(note) 增删查路由——列出某告警的备注、追加备注、删除备注。
 *   路由通常嵌套于 ops/alerts/{alert}/notes 之下。
 * 「为什么」：备注是值班协作的处置记录（谁在何时做了什么），从告警主控制器拆出便于聚焦。
 */
class AlertNoteController extends Controller
{
    use ApiResponse;

    /**
     * 作用：注入告警备注服务。
     *
     * @param  OpsAlertNoteService  $notes  负责备注读写与删除权限判定的服务
     * @return void
     */
    public function __construct(private readonly OpsAlertNoteService $notes) {}

    /**
     * 作用：列出指定告警的全部处理备注。
     *
     * @param  OpsAlert  $alert  路由模型绑定注入的目标告警
     * @return JsonResponse 含 items 备注数组
     */
    public function index(OpsAlert $alert): JsonResponse
    {
        return $this->success(['items' => $this->notes->list($alert)]);
    }

    /**
     * 作用：为指定告警追加一条处理备注，返回新建备注的精简结构。
     *
     * @param  AlertNoteStoreRequest  $request  已校验的备注正文（body），并携带当前登录管理员
     * @param  OpsAlert  $alert  路由模型绑定注入的目标告警
     * @return JsonResponse 含 note（新建备注 id/作者/正文/时间等）
     *
     * 「为什么」：作者取 $request->user('admin') 而非前端传入，防止伪造他人署名。
     */
    public function store(AlertNoteStoreRequest $request, OpsAlert $alert): JsonResponse
    {
        // 委派 Service 落库备注：绑定当前管理员为作者，正文来自已校验入参
        $note = $this->notes->add($alert, $request->user('admin'), (string) $request->validated('body'));

        return $this->success(['note' => [
            'id' => $note->id,
            'author' => $note->author,
            'admin_user_id' => $note->admin_user_id,
            'body' => $note->body,
            'created_at' => optional($note->created_at)->toDateTimeString(),
        ]]);
    }

    /**
     * 作用：删除一条备注，返回是否删除成功。
     *
     * @param  Request  $request  当前请求，用于取出操作管理员做删除权限判定
     * @param  OpsAlert  $alert  路由模型绑定注入的所属告警（用于路由层级约束）
     * @param  int  $note  待删除备注的主键 id
     * @return JsonResponse 含 deleted 布尔结果
     *
     * 「为什么」：把当前管理员传入 Service，由 Service 判定该管理员是否有权删除该备注（如仅作者/管理员可删）。
     */
    public function destroy(Request $request, OpsAlert $alert, int $note): JsonResponse
    {
        // 删除权限校验下沉到 Service，此处传入操作管理员与目标备注 id
        return $this->success(['deleted' => $this->notes->delete($request->user('admin'), $note)]);
    }
}
