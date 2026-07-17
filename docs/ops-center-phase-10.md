# Ops Center 第十阶段：发布自检可视化与历史台账

## 范围

- 新增发布自检后台页面 `/admin/ops/release-check`。
- 新增权限点 `ops.release.view`，默认授予 `super_admin` 和 `ops_admin`，不授予 `audit_viewer`。
- 完整自检只在用户点击“运行自检”时触发；页面概览接口不执行完整检查。
- 每次完整自检写入 `ops_release_checks`，历史永久保留，不新增自动清理任务。

## 接口

```text
GET  /api/ops/release-check/overview
POST /api/ops/release-check/run
GET  /api/ops/release-check/history
GET  /api/ops/release-check/history/{record}
```

`POST /api/ops/release-check/run` 会写入 `ops.release / run` 审计。审计 payload 不保存自检正文，历史表只保存脱敏后的结构化检查结果。

## 安全边界

- 自检历史不保存环境变量、日志正文、异常堆栈或密钥。
- 异常摘要和检查结果会过滤 password、token、secret、cookie、authorization、private_key、api_key 等敏感字段。
- 前端只使用文本插值和表格渲染检查信息，不使用 HTML 注入。
- 本阶段不实现定时巡检、通知推送、历史清理或趋势图。

## 验收命令

```bash
git diff --check
npm run build
./vendor/bin/sail artisan route:list --path=ops
./vendor/bin/sail artisan test --filter PhaseTenReleaseCheckTest
./vendor/bin/sail artisan test --filter OpsReleaseCheckHistoryServiceTest
./vendor/bin/sail artisan test --filter OpsReleaseCheckTest
./vendor/bin/sail artisan test --filter PhaseFiveSecurityTest
./vendor/bin/sail artisan test
```

静态扫描：

```bash
rg -n "v-html|dangerouslyUseHTMLString|innerHTML|insertAdjacentHTML" resources/js/pages/ops/ReleaseCheck.vue resources/js/api/opsRelease.ts
```
