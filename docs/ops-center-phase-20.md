# Ops Center Phase 20 — 活跃会话管理

> 查看本人活跃登录会话 · 远程注销某一个 / 注销其他会话

## 范围

phase 17 只能「全局注销」（bump `session_version` 使**所有**会话失效）。本阶段加**每会话注册表** + 自助「活跃会话」面：列出本人活跃会话（当前会话标记、设备/IP/最近活动），可逐个远程注销或一键「注销其他会话」。延续 phase-17 账号安全主题，复用其自助接口 + 前端卡片模式。

## 设计要点

- **会话标识用会话袋 token，不用 session id**：登录时生成随机 `admin_session_token`（40 字符）放进会话袋，注册表只存其 `sha256`。token 随会话数据走（不受 session id 重生成影响，测试与生产都稳定），且服务端存储、客户端不可伪造。
- **只存哈希 + 最小 PII**：注册表存 `session_token_hash` + IP + UA 摘要 + 设备标签，DB 泄漏无法反推。
- **注销强制点在中间件**：`AdminAuthenticate` 按当前 token 的 hash 查注册表——`revoked_at` 命中 → 与既有 `session_version` 失效同样的 `logout()/invalidate()/regenerateToken()` + 401；查无行（部署前旧会话）→ 懒注册，不误踢。
- **软撤销**：revoke 只置 `revoked_at`（不删行，避免被懒注册复活）；被撤销会话下次请求即被登出（生产 redis 下 `invalidate()` 顺带清 store）。

## 数据 / 服务

- **表** `admin_sessions`：`admin_user_id`、`session_token_hash`(unique)、`label`、`ip_address`、`user_agent`、`last_activity_at`(idx)、`revoked_at`、`timestamps`。模型 `app/Models/AdminSession.php`。
- **服务** `app/Services/Admin/AdminSessionRegistryService.php`：`register`（登录，生成 token + 建行）、`ensureActive`（中间件：撤销→false / 无 token→懒注册 / 正常→刷新活跃）、`list`（本人活跃，标 current，不含 hash）、`revoke(admin,id)`、`revokeOthers(admin,request)`、`forget(request)`（登出清理）。
- **钩子**：`AdminAuthenticate` 注入服务，在 `sessionVersionMatches` 后加 `ensureActive` 强制；`AdminAuthController::completeLogin` 注册、`logout` 清理。

## 接口（`/api/admin/auth/*`，`admin.auth`）

| 方法 | 路径 | 说明 |
|---|---|---|
| GET | `/sessions` | 本人活跃会话列表（current 标记） |
| POST | `/sessions/{session}/revoke` | 撤销本人一台会话 + 审计 `session_revoke` |
| POST | `/sessions/revoke-others` | 撤销除当前外所有会话 + 审计 `session_revoke_others` |

## 前端

`resources/js/pages/admin/AccountSecurity.vue` 加第 4 张卡片「活跃会话」（`el-table`：设备/IP/UA/最近活动，当前会话打「当前会话」`el-tag` 且不可注销，其余行「注销」，顶部「注销其他会话」）。api 在 `resources/js/api/accountSecurity.ts`（`getActiveSessions/revokeSession/revokeOtherSessions`）。纯文本渲染、无 `v-html`。

## 清理

`app/Services/Admin/AdminSessionPruneService.php`（默认 30 天、7–3650，按 `last_activity_at < cutoff` 或 null）+ 命令 `admin:sessions:prune {--days=} {--dry-run}` + `routes/console.php` 每日 `03:50`。

## 安全边界 / 不做

- 自助接口只操作本人会话，不跨账号；注册表只存 token hash + 最小 PII。
- 撤销走注册表软标记 + 中间件强制（不依赖 `Session::getHandler()->destroy()`，测试 array 驱动下也可靠）。
- **不做**：跨设备实时踢下线推送、并发会话上限、展示真实 session id、GeoIP。

## 验收

```bash
docker … php vendor/bin/phpunit --filter 'ActiveSession|PruneAdminSessions'
docker … php vendor/bin/phpunit          # 全量回归
docker … php vendor/bin/pint <改动文件>
npm run build
rg -n "v-html|innerHTML" resources/js/pages/admin/AccountSecurity.vue

# 手动：两处登录同一账号 → 账号安全页「活跃会话」列两条（一条 current）→ 注销另一条 → 其下次操作被登出；
#       admin:sessions:prune --dry-run
```
