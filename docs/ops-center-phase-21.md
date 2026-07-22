# Ops Center Phase 21 — 服务端保存的审计筛选预设

> 个人命名预设：把审计筛选存下来，下拉一键应用

## 范围

phase 13 增强了审计日志筛选（facets/关键词/快捷时间段），但推迟了「服务端保存的自定义筛选预设」。本阶段补齐：管理员可把当前审计筛选存成**命名预设**、下拉一键应用、删除；**仅本人可见可用**（owner-scoped）。

> 告警阈值「可视化配置」已由 `ops_alert_rules` + 规则配置面板实现，故本阶段做真正未做的筛选预设。范围先做审计；告警筛选预设可后续用同一套模式平行添加。

## 数据 / 后端

- **表** `admin_audit_presets`：`admin_user_id`(idx)、`name`(80)、`filters`(json)、`timestamps`，`unique(admin_user_id,name)`（同名 upsert）。模型 `app/Models/AdminAuditPreset.php`（`filters` cast array）。
- **服务** `app/Services/Admin/AdminAuditPresetService.php`（owner-scoped）：`list` / `save`（白名单清洗 filters 到 8 字段后 updateOrCreate）/ `delete`（作用域在 WHERE，非 owner id 静默 no-op）。`ALLOWED_FILTER_KEYS` = `admin_user_id, module, action, result, keyword, status_code, from, to`（`page/per_page` 是分页，不入预设）。
- **请求** `AdminAuditPresetStoreRequest`：`name` required max80；`filters.*` 各字段镜像 `AdminAuditIndexRequest`（result 限 success/failure、status_code 100–599、from/to date、module/action regex）。
- **控制器** `AdminAuditPresetController`：`index/store/destroy`，`store`/`destroy` 用显式 `AdminAuditService::record`（`preset_create` / `preset_delete`，成功/失败按结果）。

## 接口（`/api/admin/audit-logs/presets`，`admin.auth` + `admin.permission:admin.audit.view`）

| 方法 | 路径 | 说明 |
|---|---|---|
| GET | `/presets` | 本人预设列表 |
| POST | `/presets` | 保存（同名覆盖）+ 审计 preset_create |
| DELETE | `/presets/{preset}` | 删除本人预设 + 审计 preset_delete |

## 前端

`resources/js/pages/admin/AdminAuditLogs.vue` 工具栏新增：预设 `el-select`（选中即应用：`Object.assign(filters, ...)` + 由 `from/to` 重建 `range` → 查询）+「保存筛选」（`ElMessageBox.prompt` 取名 → 序列化当前筛选去分页 → 保存）+ 删除。api 在 `resources/js/api/adminSecurity.ts`（`AdminAuditPreset` + `getAuditPresets/saveAuditPreset/deleteAuditPreset`）。纯文本渲染、无 `v-html`。

## 安全边界 / 不做

- owner-scoped：只读/删本人（ownership 在 WHERE 强制）；filters 存前白名单，杜绝存任意键。
- 权限沿用 `admin.audit.view`，不新增权限点。
- **不做**：告警筛选预设前端、跨账号共享/团队预设、预设自动默认应用。

## 验收

```bash
docker … php vendor/bin/phpunit --filter 'PhaseTwentyOneAuditPreset|Audit'
docker … php vendor/bin/phpunit          # 全量回归
docker … php vendor/bin/pint <改动文件>
npm run build
rg -n "v-html|innerHTML" resources/js/pages/admin/AdminAuditLogs.vue
# 手动：审计页存「某模块+失败+近7天」为预设 → 刷新页面下拉一键应用 → 删除。
```
