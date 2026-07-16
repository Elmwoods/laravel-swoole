# Ops Center 发布体验与生产自检

## 自检命令

发布前或发布后执行：

```bash
./vendor/bin/sail artisan ops:release-check
```

机器可读输出：

```bash
./vendor/bin/sail artisan ops:release-check --json
```

严格模式用于 CI 或发布阻断：

```bash
./vendor/bin/sail artisan ops:release-check --strict
```

`--strict` 仅在存在 `fail` 检查项时返回非 0；`warn` 用于提示生产建议，不阻断默认发布。

## 推荐发布顺序

1. 拉取代码并安装依赖。
2. 执行迁移：`./vendor/bin/sail artisan migrate`。
3. 创建或确认超级管理员：`./vendor/bin/sail artisan admin:create-super`。
4. 执行发布自检：`./vendor/bin/sail artisan ops:release-check --strict`。
5. 执行前端构建：`npm run build`。
6. 顺序执行测试，避免多个 PHPUnit 进程同时刷新同一个 `testing` 数据库。
7. 重启 Octane/Swoole、队列 worker 和调度进程。
8. 发布后再次执行 `ops:release-check`，并人工验证后台登录、日志中心、告警中心。

## 失败项处理

- `APP_DEBUG` 在 production 为 true：设置 `APP_DEBUG=false` 并清理配置缓存。
- 数据库表缺失：执行 `migrate`，确认 migration 文件随版本发布。
- 缺少超级管理员：执行 `admin:create-super`，不要在代码或 Seeder 中写默认密码。
- 日志白名单为空：确认 `config/ops.php` 中的 `logs.system_sources`。
- 告警规则同步失败：检查 `ops_alert_rules` 表和数据库连接。

## 回滚说明

- 代码回滚不会自动删除已新增数据表和字段。
- 回滚前确认 Octane/Swoole、队列 worker 已停止接收旧代码请求。
- 回滚后重新执行 `route:list --path=ops` 和 `ops:release-check`，确认路由、后台权限和告警规则恢复到预期状态。
- 审计清理、日志下载、告警评估等操作产生的历史数据不会因代码回滚自动撤销。
