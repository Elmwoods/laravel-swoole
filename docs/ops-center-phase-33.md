# Ops Center Phase 33 — 告警运营三合一（规则导入导出 + 指派值班 + 通知模板自定义）

## 背景

告警中心已具备规则阈值管理、指派字段、多通道通知，但缺三块运营闭环：

1. **规则难迁移** —— 13 条阈值规则只能在 UI 里逐条改，没法在环境间迁移或版本化备份。
2. **指派不成闭环** —— 虽有 `assign` 端点与 `assigned_to` 字段，但列表无法按指派人筛选，也没有「认领给我」的快捷动作。
3. **通知文案写死** —— `formatMessage` 是硬编码的 6 行格式，无法按团队习惯自定义。

本阶段一次补齐三块（互相独立，围绕告警中心）。

## 范围

### A. 告警规则 JSON 导入导出

- **导出** `GET /api/ops/alerts/rules/export`（`ops.alerts.view`）→ `{exported_at, rules:[{key,name,warning_threshold,critical_threshold,is_active}]}`，只含用户可调字段。
- **导入** `POST /api/ops/alerts/rules/import`（`ops.alerts.manage` + `admin.audit:ops.alerts,rule_import`）→ body `{rules:[...]}`。
  - 只认 `AlertRuleRegistryService::allowedKeys()` 的 13 个白名单 key；未知 key → 跳过（reason `unknown_key`）。
  - 数值按该 key 的 `definition` min/max 校验、`critical >= warning`；越界 → 跳过（reason `invalid`）。
  - 只写 `warning_threshold` / `critical_threshold` / `is_active`（系统字段不可改）。
  - 逐条 skip 不整单失败；返回 `{applied, total, skipped:[{key,reason}]}`。
- 实现：`AlertRuleRegistryService::export()/import()`、`AlertRuleController::export/import`、`AlertRuleImportRequest`（仅校验结构，min/max 在 service 按 key 判）。

### B. 告警指派 / 值班

- **过滤**：`AlertIndexRequest` 加 `assigned_to`（精确匹配某人）与 `assigned=unassigned`（仅未指派）；`AlertCenterService::paginate()` 追加对应 `when()` 子句。
- **处理人列表**：`GET /api/ops/alerts/assignees`（`ops.alerts.view`）→ 去重的历史 `assigned_to` 值，供筛选下拉（不暴露完整管理员名册）。
- **认领**：复用既有 `POST /alerts/{alert}/assign`，前端「指派给我」传当前登录管理员名（`assigned_to` 是自由串，不引 FK）。
- 前端：筛选区加指派人下拉（含「未指派」「指派给我」）+ 告警表「指派」列 + 行内「指派给我」按钮。

### C. 通知消息模板自定义

- 新增设置 `message_template`（config 播种 `OPS_ALERT_MESSAGE_TEMPLATE`，默认空=用内置格式）。
- 占位符：`{title} {severity} {source} {status} {time} {message}`（`{time}` = `last_seen_at`）。
- `AlertNotificationService::formatMessage()`：模板非空 → `strtr` 替换后输出；空 → 内置 6 行。**仅影响文本通道**（Telegram / 邮件 / 钉钉 / 飞书）；**Webhook 保持结构化 JSON**（`webhookPayload` 不套模板）。
- 四处同步：`OpsAlertSetting::defaults()`、`AlertCenterService::updateSettings()`（**独立 `array_key_exists` 守卫**，不进全 required 循环，保留旧 payload 兼容）、`AlertSettingsUpdateRequest`（`nullable|string|max:2000`）、前端 `AlertSettings` 类型 + 设置面板 textarea。
- boot-safe：读设置异常 → 回退内置格式。

## 权限 / 安全边界

- 读 `ops.alerts.view`、写 `ops.alerts.manage`；导入挂 `admin.audit:ops.alerts,rule_import` 审计。
- 规则导入只认白名单 key、只改三可调字段、按 min/max 校验，非法逐条跳过并报告。
- 指派 `assigned_to` 自由串（不引 FK）；assignee 下拉仅来自已有指派值；「指派给我」用当前登录名。
- 模板仅 `strtr` 占位符替换 + 原有脱敏/截断，不执行任意表达式；只套文本通道；空=内置；boot-safe 回退。

## 不做

- 规则新增/删除（仍是代码白名单）、每通道独立模板、模板循环/条件语法、指派人 FK 与在线状态、跨账号指派推送。

## 验收

```
docker … php vendor/bin/phpunit --filter 'PhaseThirtyThreeRuleIo|PhaseThirtyThreeAssign|PhaseThirtyThreeTemplate|PhaseEightAlert'
docker … php vendor/bin/phpunit          # 全量回归
docker … php vendor/bin/pint <改动文件>
npm run build
rg -n "v-html|innerHTML" resources/js/pages/ops/AlertCenter.vue
# 手动：规则页导出 JSON→改阈值→导入看 applied；告警「指派给我」后按我筛选；设模板 "[{severity}] {title}" 触发告警看通道文案变化。
```

## 交付物

- 后端：`AlertRuleRegistryService`(export/import) + `AlertRuleController`(export/import) + `AlertRuleImportRequest` + 2 路由；`AlertIndexRequest`/`paginate` assigned 过滤 + `AlertController::assignees` + 路由；`config/ops.php`(message_template) + `OpsAlertSetting::defaults` + `updateSettings` 守卫 + `AlertSettingsUpdateRequest` + `formatMessage` 模板渲染。
- 前端：`opsStage4.ts`（类型+函数）+ `AlertCenter.vue`（规则导入导出 / 指派列筛选 / 模板 textarea）。
- 测试：`PhaseThirtyThreeRuleIoTest` + `PhaseThirtyThreeAssignTest` + `PhaseThirtyThreeTemplateTest`。
