# Ops Center 第六阶段发布验收清单

## 目标

本清单用于发布前复核日志中心增强能力，覆盖后台账号准备、权限验证、日志接口调用、失败边界、审计检查和回滚说明。

本阶段不新增日志下载接口，不新增权限点，不通过 WebSocket 推送大日志正文。

## 环境准备

按顺序执行数据库迁移和首个超级管理员创建：

```bash
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan admin:create-super \
  --name="Ops Admin" \
  --email="ops@example.com" \
  --password="change-me-strong-password"
```

- `admin:create-super` 会同步系统权限和内置角色。
- `super_admin` 拥有全部权限，包含 `ops.logs.view`。
- `ops_admin` 也包含 `ops.logs.view`，可用于日常运维验收。
- 示例密码仅用于本地或测试环境；生产环境必须换成强密码并妥善保管。

## 登录与权限验证

优先通过浏览器完成后台登录：

1. 打开 `/admin/login`。
2. 使用已创建的后台管理员登录。
3. 打开 `/admin/ops/logs`。
4. 调用 `GET /api/admin/auth/me`，确认响应中的 `permissions` 包含 `ops.logs.view`。

预期结果：

- 未登录访问 `/api/ops/logs/laravel?tail=50` 返回 401。
- 登录但没有 `ops.logs.view` 的账号访问日志接口返回 403。
- 拥有 `ops.logs.view` 的账号可以访问日志中心页面和日志接口。

## 接口验收清单

以下接口均要求后台登录和 `ops.logs.view` 权限。

```text
GET /api/ops/logs/laravel?tail=50&page=1&per_page=20
GET /api/ops/logs/octane?tail=50&keyword=error
GET /api/ops/logs/redis?tail=50&page=1&per_page=20
GET /api/ops/logs/system/sources
GET /api/ops/logs/system?source=<whitelisted-key>&tail=50
GET /api/ops/logs/docker?container=<container-name-or-id>&tail=50
```

成功响应检查：

- `code` 为 `0`。
- 文件日志响应包含 `source`、`lines`、`entries`、`pagination`、`checked_at`。
- Redis SlowLog 响应包含 `source=redis`、`entries`、`count`、`pagination`。
- System 来源必须来自 `/api/ops/logs/system/sources` 返回的白名单 key。
- Docker 容器标识只允许字母、数字、下划线、点、冒号和短横线。

### 全部分页浏览

```text
GET /api/ops/logs/laravel?mode=full&page=1&per_page=20
GET /api/ops/logs/octane?mode=full&page=2&per_page=50&keyword=error
GET /api/ops/logs/system?mode=full&source=<whitelisted-key>&page=1&per_page=20
GET /api/ops/logs/docker?mode=full&container=<container-name-or-id>&page=1&per_page=20
```

预期结果：

- Laravel、Octane、System 文件日志可以通过分页从第一页浏览到最后一页，不再受 1000 行 tail 窗口截断。
- 在页面切到“全部分页”后，第 1 页显示最新日志，最后 1 页显示更早日志；两页内容应不同。
- 展开一条多行日志后可以收起，翻页后旧页展开状态不会污染新页。
- 快速连续翻页时，以最后一次翻页请求的结果为准，旧请求返回后不应覆盖当前页。
- `mode=tail` 或未传 `mode` 时仍保持最近 Tail 模式，`tail=1001` 返回 `422`。
- Redis SlowLog 只分页 Redis 当前保留的 slowlog 记录，不代表无限历史。
- Docker 全部分页依赖 `docker logs` 输出能力，底层失败时返回安全错误，不泄露宿主机路径。

日志下载验收：

- “导出当前范围”和“导出全部匹配”都应按最新时间到最早时间输出。
- 审计日志只记录来源、筛选条件、模式、数量和结果，不包含日志正文。

## 失败边界验收

按以下条件逐项验证：

```text
GET /api/ops/logs/system?source=../../.env
GET /api/ops/logs/laravel?tail=1001
GET /api/ops/logs/laravel?per_page=101
GET /api/ops/logs/laravel?from=not-a-date
GET /api/ops/logs/laravel?from=2026-07-16%2011:00:00&to=2026-07-16%2010:00:00
```

预期结果：

- 非白名单或路径穿越来源返回 422。
- `tail` 超过 1000 返回 422。
- `per_page` 超过 100 返回 422。
- 非法 `from/to` 返回 422。
- 不可读日志文件返回安全错误，响应不包含宿主机完整敏感路径。

## 审计验收

访问任一日志接口后，在后台审计日志中检查：

- `module` 为 `ops.logs`。
- `action` 对应日志来源：`laravel`、`octane`、`redis`、`system`、`system_sources` 或 `docker`。
- 成功请求 `result=success`，状态码为 200。
- 失败请求应记录真实状态码，例如 422。
- `payload` 只包含来源、筛选条件和分页参数摘要，不包含日志正文、Cookie、Token、密码或密钥。

## 必跑命令

发布前按顺序执行：

```bash
git diff --check
npm run build
rg -n "v-html|innerHTML|insertAdjacentHTML" resources/js/pages/ops/logs resources/js/api/opsStage3.ts
rg -n "show-password|console\\.error\\(error\\)" resources/js
./vendor/bin/sail artisan route:list --path=ops
./vendor/bin/sail artisan test --filter PhaseThreeLogCenterTest
./vendor/bin/sail artisan test --filter LogFileReaderServiceTest
./vendor/bin/sail artisan test --filter LogErrorWatcher
./vendor/bin/sail artisan test
```

PHPUnit 命令必须顺序执行，避免多个进程同时刷新同一个 `testing` 数据库。

## Docker 镜像构建

Sail 镜像的 Dockerfile 依赖同目录下的 `start-container`、`supervisord.conf` 和 `php.ini`。不要从仓库根目录直接执行 `docker build -f docker/8.4/Dockerfile .`，否则构建上下文不包含这些文件，会出现 `COPY ... not found`。

推荐命令：

```bash
docker compose build laravel.test
docker build -f docker/8.4/Dockerfile docker/8.4
```

## 环境限制

- Docker 日志接口依赖当前环境可执行 `docker logs`。
- 如果 Docker 在当前环境不可用，记录为环境限制；只要接口返回安全失败信息，不视为权限或输入验证失败。
- Octane、System 日志文件是否存在取决于部署方式；不存在或不可读时应返回安全错误。

## 回滚说明

- 本阶段没有新增数据库表、字段或权限点。
- 代码回滚后，日志中心发布验收文档随代码回滚。
- 日志读取审计记录已经写入 `admin_audit_logs` 的不会自动删除；可按第五阶段审计清理命令策略处理。
- 回滚前后都应重新执行 `route:list --path=ops` 和日志中心聚焦测试，确认路由和权限链路恢复到预期状态。
