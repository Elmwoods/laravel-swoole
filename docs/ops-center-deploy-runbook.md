# Ops Center 部署 Runbook

## ⚠️ 部署后必做：reload Octane worker

**任何改动 PHP 类 / 常量 / 配置的部署，收尾必须执行：**

```bash
./vendor/bin/sail artisan config:clear
./vendor/bin/sail artisan octane:reload
```

**为什么**：Octane(Swoole) 把已编译的字节码常驻 worker 内存，不像 php-fpm 每请求重载。只更新了代码文件但不 `octane:reload`，worker 仍执行**旧字节码**。当新旧代码不一致（例如某个类常量/方法被删或改名），旧 worker 会抛致命错误（`Undefined constant ...` / `Call to undefined method ...`），直到 worker 被 reload 才恢复。`config:clear` 同理清掉缓存的配置，避免读到旧值。

## 事故复盘：2026-07-21 的「自动巡检失败」critical

- **现象**：告警中心推 `critical / source=inspection / 自动巡检失败`。
- **根因**：phase 14 把 `OpsAlertSetting::DEFAULT_SEVERITY_CHANNELS` 常量移除（改为动态生成），但部署后未 `octane:reload`，旧 worker 仍引用该常量 → `AlertCenterService::evaluate()` 抛 `Undefined constant App\Models\OpsAlertSetting::DEFAULT_SEVERITY_CHANNELS`。
- **传导**：每分钟 `ops:alerts:evaluate` + 每 15 分钟 `ops:inspections:run --type=light` 的「告警中心 → Alert Evaluation」子检查调 `evaluate()` → 抛错 → 巡检判 fail → `raiseInspectionAlert()` 推 critical。
- **恢复**：worker reload 后代码一致，评估恢复正常；后续巡检 pass → `resolveInspectionAlert()` 自动关闭该告警。**属一次性瞬时故障，非持续问题。**
- **加固（已做）**：巡检的 Alert Evaluation 检查现在对**单次/少量瞬时**评估失败降级为 `warn`（不推 critical、并自动关闭历史告警），只有**连续失败达阈值**（`config('ops.alerts')` 旁的 `ops.inspections.alert_eval_fail_threshold`，默认 3，`OPS_INSPECTION_ALERT_EVAL_FAIL_THRESHOLD`）才升级为 `fail`/critical。见 `app/Services/Ops/OpsInspectionService.php::runAlertEvaluation`。

## 收到 `source=inspection` 告警时如何定位失败子检查

```bash
# 最近一条失败巡检的每检查明细（checks JSON）
./vendor/bin/sail artisan tinker --execute='
  $i = App\Models\OpsInspection::where("status","fail")->latest("id")->first();
  echo $i?->failure_message, PHP_EOL;
  foreach ((array)$i?->checks as $c) if (($c["status"]??"")==="fail") echo $c["group"]."/".$c["name"].": ".$c["message"].PHP_EOL;
'
# 若失败子检查是 "Alert Evaluation"，真实底层异常在 ops_alert_evaluations：
./vendor/bin/sail artisan tinker --execute='
  foreach (App\Models\OpsAlertEvaluation::where("status","failure")->latest("id")->limit(5)->get() as $e)
    echo $e->created_at." ".$e->message.PHP_EOL;
'
```

前端等价入口：告警中心 → 巡检历史，或 `GET /api/ops/inspections/history/{id}`（含 `checks` 明细，权限 `ops.inspections.view`）。手动全量巡检：`sail artisan ops:inspections:run --type=full`。

## 登录 IP 准入（phase 24）与可信代理

后台登录 IP 白/黑名单按 `request->ip()` 判定。仓库**默认不信任任何代理**，Octane/Swoole 在
nginx 之后时 `request->ip()` 是**代理 IP**，会让名单失效或误判。要按真实客户端 IP 生效，
必须把可信代理注入为**真实环境变量**（不是仅写 `.env`——`bootstrap/app.php` 在构建期读取）：

```
OPS_TRUSTED_PROXIES=10.0.0.0/8,172.16.0.0/12   # 或单个 '*' 信任全部（仅在入口可信时）
```

生效后 `X-Forwarded-For` 被信任，`request->ip()` 全局回归真实客户端（审计/限流/会话注册一并受益）。

**Break-glass（把自己 IP 锁在外面时自救）**：从服务器 CLI 恢复，无需登录后台——

```
./vendor/bin/sail artisan admin:ip-access --status     # 查看启用/模式/规则计数
./vendor/bin/sail artisan admin:ip-access --disable    # 紧急关闭准入（放行全部）
./vendor/bin/sail artisan admin:ip-access --flush      # 清空所有 IP 规则（含自动封禁）
```

## 滥用来源自动封禁（phase 25）

`admin:ip-auto-ban` 每 5 分钟按 IP 统计失败登录暴增，超阈值自动写带过期的临时 deny 规则。

- **代理后未配 `OPS_TRUSTED_PROXIES` 前不要开自动封禁**——否则 `request->ip()` 是代理 IP，
  可能封掉代理导致所有人无法登录（同 phase-24 前置条件）。
- 上线前预演：`./vendor/bin/sail artisan admin:ip-auto-ban --dry-run`（只统计不封禁）。
- 启用开关在「安全管理 → 登录准入」页；阈值/窗口/时长走 env（`OPS_AUTO_BAN_*`）。
- 误封自救：`admin:ip-access --flush`（清空全部，含自动封禁）或在登录准入页删除对应规则。

## 其它部署检查

- `npm run build`（前端资产）
- `./vendor/bin/sail artisan migrate --force`（有新迁移时；phase 24 新增 `admin_ip_rules`、`admin_security_settings`）
- `./vendor/bin/sail artisan ops:release-check` 或发布自检页确认基线（表/超管/权限/路由/文档齐全）
