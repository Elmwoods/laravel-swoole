# Ops Center Phase 31 — 告警中心筛选预设（owner-scoped 命名预设）

## 范围

把 phase 21 的**审计筛选预设**模式平行搬到**告警中心**：把当前告警筛选（状态/严重级/来源）存成命名预设、
下拉一键应用、可删除；仅本人可见可用（owner-scoped）。纯复用 phase-21 模式，无新机制。

## 复用锚点

- 预设 CRUD 模式：phase-21 `AdminAuditPreset` / `AdminAuditPresetService`（`list/save/delete` + `ALLOWED_FILTER_KEYS` 白名单 + ownership 写在 WHERE）。
- 告警筛选字段唯一集合：`AlertIndexRequest`——`status`(open/acknowledged/resolved)、`severity`(critical/warning/info)、`source`(regex)。`page/per_page` 是分页，不入预设。
- 权限复用 `ops.alerts.view`（能看告警即可存自己的预设），不新增 slug。

## 数据表 / 接口

- `ops_alert_presets`：`admin_user_id`(idx)、`name`(80)、`filters`(json)、时间戳、`unique(admin_user_id,name)`（同名 upsert）。
- `GET /api/ops/alerts/presets`（本人列表）。
- `POST /api/ops/alerts/presets`（保存，白名单 sanitize；审计 `admin.audit:ops.alerts,preset_create`）。
- `DELETE /api/ops/alerts/presets/{preset}`（owner-scoped 删除；审计 `preset_delete`）。
  > 审计走**路由级** `admin.audit` 中间件（`api/ops/` 的 POST 路由约定必须挂 `admin.audit:`，由 `PhaseFiveSecurityTest` 守），非 in-controller。

## 前端

告警中心筛选区加：预设 `el-select`（选中即应用 status/severity/source + 刷新）+「保存筛选」按钮（`ElMessageBox.prompt` 取名 → 序列化当前筛选去空 → 保存）+「删除预设」。`onMounted` 加载本人预设。纯文本、无 `v-html`。

## 安全边界 / 不做

- 预设 owner-scoped：只读/删本人（ownership 在 WHERE 强制）；filters 存前白名单，杜绝存任意键/注入。
- 权限沿用 `ops.alerts.view`，不新增权限点。
- **不做**：跨账号共享/团队预设、预设默认自动应用、把 SLA/静默页也加预设。

## 验收

```
docker … php vendor/bin/phpunit --filter 'PhaseThirtyOneAlertPreset'
docker … php vendor/bin/phpunit          # 全量回归
npm run build
rg -n "v-html|innerHTML" resources/js/pages/ops/AlertCenter.vue
# 手动：告警中心存「open+critical+disk」为预设 → 刷新页面下拉一键应用 → 删除。
```
