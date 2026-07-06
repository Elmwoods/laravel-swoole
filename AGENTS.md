# Ops Center Agent Guide

## 项目定位

本项目是 Laravel 13 + Octane(Swoole) + Vue3 + Vite + Sail 的企业级运维后台。Agent 在本仓库内工作时，应优先保持现有 Service + Controller + Request + Vue Composition API 架构。

## 工作规范

- 后端业务逻辑放在 `app/Services/Ops`。
- HTTP 入参验证放在 `app/Http/Requests/Admin/Ops`。
- Controller 只做薄层编排和统一响应。
- 前端页面放在 `resources/js/pages/ops`。
- 前端 API 类型和请求封装放在 `resources/js/api`。
- 所有新增运维页面必须接入 `resources/js/layouts/AdminLayout.vue` 的侧边栏。
- 涉及 Docker、Supervisor、进程管理的输入必须先做白名单验证。
- 禁止把用户输入直接拼接到 shell 命令中。
- WebSocket payload 必须保持小体积；日志大内容通过 HTTP 分页或 tail 接口拉取。
- 每次修改或新增需求交付前，必须完成代码 review、可执行测试和风险说明。
- 如果本机缺少 PHP 或 Docker 环境，必须至少执行前端构建与静态扫描，并明确列出 Sail 内测试命令。

## 验证命令

```bash
npm run build
./vendor/bin/sail artisan route:list --path=ops
./vendor/bin/sail artisan test
```

## 交付要求

- 必须说明本次修改的文件路径。
- 必须说明已执行的测试命令和结果。
- 必须说明未能执行的测试及原因。
- 必须说明 review 中发现并修复的安全隐患或残余风险。

## 当前阶段

- 第一阶段：Octane、Redis、Queue、Supervisor 已完成基础监控。
- 第二阶段：Docker、CPU/Memory、Disk、Network 已完成页面与 API。
- 第三阶段：日志中心需要继续做分页、搜索、tail 和权限隔离。
