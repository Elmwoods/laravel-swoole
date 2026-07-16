# Ops Center 第六阶段：日志中心增强与权限隔离收口

## 实现范围

- 日志中心继续使用 `/api/ops/logs/*` 路径。
- 所有日志查看接口要求后台登录、`ops.logs.view` 权限，并记录 `ops.logs` 审计。
- Laravel、Octane、System、Docker 日志统一返回分页日志事件结构。
- Redis SlowLog 支持分页、关键词和时间范围筛选。
- 日志查询支持 `page`、`per_page`、`keyword`、`level`、`from`、`to`、`tail`。
- `tail` 限制为 10 到 1000 行，`per_page` 限制为 5 到 100，`keyword` 限制为 120 个字符。
- `from` 和 `to` 必须为 `YYYY-MM-DD HH:mm:ss`，且结束时间不能早于开始时间。
- System 日志只允许读取 `ops.logs.system_sources` 配置中的白名单来源。
- 日志文件不存在或不可读时返回安全错误，不向前端泄露宿主机完整路径。
- 审计 payload 只记录查询条件摘要，不保存日志正文。
- 前端日志正文使用文本插值渲染，不使用 HTML 注入。

## 文件路径

- 日志查询 DTO：`/Users/ggbond/PHPProjects/swoole/app/DTO/Ops/Log/LogQueryDTO.php`
- 日志查询验证：`/Users/ggbond/PHPProjects/swoole/app/Http/Requests/Admin/Ops/LogQueryRequest.php`
- 日志控制器：`/Users/ggbond/PHPProjects/swoole/app/Http/Controllers/Admin/Ops/Log/LogController.php`
- 文件日志读取服务：`/Users/ggbond/PHPProjects/swoole/app/Services/Ops/Log/LogFileReaderService.php`
- Docker 日志服务：`/Users/ggbond/PHPProjects/swoole/app/Services/Ops/Log/DockerLogService.php`
- Redis 慢日志服务：`/Users/ggbond/PHPProjects/swoole/app/Services/Ops/Log/RedisLogService.php`
- API 路由：`/Users/ggbond/PHPProjects/swoole/routes/api.php`
- 前端日志 API：`/Users/ggbond/PHPProjects/swoole/resources/js/api/opsStage3.ts`
- 前端日志页面：`/Users/ggbond/PHPProjects/swoole/resources/js/pages/ops/logs/Logs.vue`
- Feature 测试：`/Users/ggbond/PHPProjects/swoole/tests/Feature/Ops/PhaseThreeLogCenterTest.php`
- Unit 测试：`/Users/ggbond/PHPProjects/swoole/tests/Unit/Ops/LogFileReaderServiceTest.php`

## 日志来源白名单

- `laravel`：`storage/logs/laravel.log`
- `octane`：Octane/Swoole 运行日志配置路径
- `redis`：Redis `SLOWLOG GET`
- `system`：仅允许 `ops.logs.system_sources` 中定义的 key
- `docker`：仅允许容器名称或 ID 使用字母、数字、下划线、点、冒号和短横线

不支持任意文件路径读取，不接受绝对路径、相对路径穿越或用户传入的宿主机路径。

## 验收命令

发布验收步骤和接口验证清单见：

- `/Users/ggbond/PHPProjects/swoole/docs/ops-center-phase-6-release.md`

命令需要顺序执行，避免多个 PHPUnit 进程同时刷新同一个 `testing` 数据库。

```bash
git diff --check
npm run build
./vendor/bin/sail artisan route:list --path=ops
./vendor/bin/sail artisan test --filter PhaseThreeLogCenterTest
./vendor/bin/sail artisan test --filter LogFileReaderServiceTest
./vendor/bin/sail artisan test
```

静态检查：

```bash
rg -n "v-html|innerHTML|insertAdjacentHTML" resources/js/pages/ops/logs resources/js/api/opsStage3.ts
rg -n "show-password|console\\.error\\(error\\)|password:|password_confirmation:" resources/js
```

## 风险边界

- 本阶段不新增日志下载接口。
- 本阶段不将大日志正文推送到 WebSocket。
- 审计日志不保存日志正文，只保存日志来源、筛选条件、结果和状态码。
- Docker 日志读取仍依赖宿主机或容器内 `docker logs` 可用性，不可用时返回安全失败信息。
- 大日志读取仍通过 tail 行数上限控制，避免一次性读取完整大文件拖慢 Octane Worker。
