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

## 前端可用性收口

- 老监控组件统一使用脱敏请求错误摘要，不在控制台输出完整 Axios error、Cookie、token 或响应正文。
- Redis 实时图表补齐加载、空态、错误态、手动刷新和定时器清理，避免页面离开后继续轮询。
- 系统概览与系统趋势补齐失败提示和重试入口，Echo 订阅释放保持与订阅 channel 一致。
- Docker 日志弹窗继续安全文本渲染，WebSocket 只处理小体积更新，完整日志通过 HTTP 拉取并限制前端保留字符数。
- 日志中心新增 `最近 Tail` / `全部分页` 查看范围切换，全部分页模式关闭自动刷新，通过分页控件浏览完整匹配结果。
- 日志导出区分当前范围与全部匹配，`mode=full` 可导出完整匹配结果。
- 本轮只做可用性与安全展示收口，不新增发布自检后台页、2FA、自动巡检或新告警通道。

## 功能缺口检查结论

- 后台登录、RBAC、审计、日志下载、审计导出、高风险二次确认、告警规则配置和发布自检已形成基线。
- 当前仍适合作为后续独立阶段的增强项：发布自检可视化页面、2FA/TOTP、自动巡检任务、审计导出页面筛选预设、告警通知通道扩展。
- 上述增强项不阻塞当前运维后台可用性收口，避免与本轮前端显示修复混入同一批变更。

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
