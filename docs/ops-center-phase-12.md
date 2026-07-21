# Ops Center 第十二阶段：自动巡检闭环（调度 / 失败通知 / 前端 / 历史清理）

## 范围

- 定时自动巡检聚合发布自检、告警评估、日志错误扫描与队列健康四组检查，双层脱敏后写入 `ops_inspections`。
- 调度：`ops:inspections:run --type=light` 每 15 分钟执行一次；手动运行 API 为 `full`（含发布自检）。
- 失败通知闭环：巡检结果为 `fail` 时自动升起一条 `source=inspection` 的 critical 告警，复用现有 Telegram/邮件通道与重复通知冷却；巡检恢复（`pass`/`warn`）时自动关闭该告警。
- 前端新增 `/admin/ops/inspection` 页面，接入 `ops.inspections.view` 权限与侧边栏。
- 历史清理：`ops:inspections:prune` 每日 03:10 清理过期巡检记录，默认保留 14 天。

## 接口

```text
GET  /api/ops/inspections/summary
GET  /api/ops/inspections/history
GET  /api/ops/inspections/history/{record}
POST /api/ops/inspections/run
```

- 全部路由挂 `admin.permission:ops.inspections.view`。
- `POST /run` 额外写 `ops.inspections / run` 审计，返回脱敏后的完整检查详情。
- 列表接口只返回结构化摘要，不含 `checks`；详情接口才返回脱敏后的 `checks`。

## 命令与调度

```text
ops:inspections:run {--type=light|full}      # 每 15 分钟（light）
ops:inspections:prune {--days=14} {--dry-run} # 每日 03:10
```

## 安全边界

- 巡检 `checks`、`failure_message` 经 `OpsInspectionService` 键名过滤 + 正文正则双层脱敏；告警升级时 `AlertCenterService` 再做一次文本脱敏，防止 password/token/secret/cookie/authorization/private_key/api_key 泄漏到告警或通知。
- 失败告警使用固定指纹 `source=inspection`，重复失败只增加 `hit_count`，首次或超过冷却时间才重复推送，避免刷屏。
- 通知只在 `fail` 时触发；`warn`（命中告警、失败任务、worker 缺失等常见项）不推送。
- 前端只使用文本插值与表格/抽屉渲染，不使用 HTML 注入。
- 巡检历史按保留天数自动清理，`--dry-run` 可预览删除数量；不删除告警、审计等其他数据。

## 验收命令

```bash
git diff --check
npm run build
./vendor/bin/sail artisan route:list --path=ops/inspections
./vendor/bin/sail artisan schedule:list
./vendor/bin/sail artisan test --filter PhaseTwelveInspectionTest
./vendor/bin/sail artisan test --filter OpsInspectionServiceTest
./vendor/bin/sail artisan test --filter OpsInspectionPruneServiceTest
./vendor/bin/sail artisan test --filter OpsInspectionPruneCommandTest
./vendor/bin/sail artisan test --filter PhaseFourAlertCenterTest
./vendor/bin/sail artisan test
```

手动验证失败通知闭环：

```bash
# 让某个子检查失败（例如停掉 queue worker），运行完整巡检后确认告警中心出现 inspection 告警并触发通知
./vendor/bin/sail artisan ops:inspections:run --type=full
./vendor/bin/sail artisan ops:inspections:prune --dry-run
```

静态扫描：

```bash
rg -n "v-html|dangerouslyUseHTMLString|innerHTML|insertAdjacentHTML" resources/js/pages/ops/Inspection.vue resources/js/api/opsInspection.ts
```
