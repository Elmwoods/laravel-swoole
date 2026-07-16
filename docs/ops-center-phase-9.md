# 第九阶段：中优先级运维安全与导出能力

## 范围

- 日志中心新增安全下载接口，继续使用 `ops.logs.view` 权限与日志来源白名单。
- 审计日志新增 CSV 导出接口，继续使用 `admin.audit.view` 权限。
- 后台登录成功记录最近登录 IP、User-Agent 摘要和时间，`/api/admin/auth/me` 返回会话安全摘要。
- Docker、Supervisor、Octane 高风险操作新增固定确认短语 `CONFIRM`。

## 接口

日志下载：

- `GET /api/ops/logs/laravel/download`
- `GET /api/ops/logs/octane/download`
- `GET /api/ops/logs/redis/download`
- `GET /api/ops/logs/system/download`
- `GET /api/ops/logs/docker/download`

审计导出：

- `GET /api/admin/audit-logs/export`

高风险确认：

- Docker `stop/restart`
- Supervisor `stop/restart`
- Octane `stop/restart/reload`
- 请求体必须包含 `confirm_text: "CONFIRM"`。

## 安全边界

- 日志下载只读取系统白名单来源，不支持任意路径。
- 下载和导出均写审计，但审计 payload 不保存文件正文或日志正文。
- CSV 字段会转义 `=`, `+`, `-`, `@` 开头内容，降低公式注入风险。
- User-Agent 摘要会过滤 token、password、authorization、cookie 样式片段。
- 本阶段不引入 2FA/TOTP、设备信任、地理风控或新的权限点。

## 验收命令

```bash
git diff --check
npm run build
./vendor/bin/sail artisan route:list --path=ops
./vendor/bin/sail artisan test --filter PhaseThreeLogCenterTest
./vendor/bin/sail artisan test --filter PhaseFiveSecurityTest
./vendor/bin/sail artisan test --filter AdminSecurityServiceTest
./vendor/bin/sail artisan test
```

静态扫描：

```bash
rg -n "v-html|dangerouslyUseHTMLString|innerHTML|insertAdjacentHTML" resources/js
rg -n "console\\.error\\(error\\)|show-password" resources/js
```
