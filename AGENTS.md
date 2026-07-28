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
# 部署收尾必做（Octane 常驻字节码，改类/常量/配置后不 reload 会触发 Undefined constant 等致命）：
./vendor/bin/sail artisan config:clear && ./vendor/bin/sail artisan octane:reload
```
> 部署与事故排查见 `docs/ops-center-deploy-runbook.md`。

## 交付要求

- 必须说明本次修改的文件路径。
- 必须说明已执行的测试命令和结果。
- 必须说明未能执行的测试及原因。
- 必须说明 review 中发现并修复的安全隐患或残余风险。

## 当前阶段

已交付（详见 `docs/ops-center-phase-*.md`）：

- 一~三阶段：Octane/Redis/Queue/Supervisor/Docker/系统资源基础监控 + 日志中心（分页、搜索、tail、权限隔离）。
- 四~八阶段：告警中心（规则配置、评估、生命周期、Telegram/邮件通知）+ 后台 RBAC/审计。
- 九~十一阶段：日志/审计导出、高风险二次确认、发布自检可视化、强制 2FA/TOTP。
- 十二阶段：自动巡检闭环（定时巡检 + 失败通知 + 前端 + 历史清理）。
- 十三阶段：巡检/告警趋势可观测性 + 审计筛选/导出增强。
- 十四阶段：告警通知通道扩展（Webhook + 钉钉 + 飞书，配置驱动）。
- 十五阶段：系统指标长期持久化 + 多天趋势（分钟级采集、按天聚合、保留清理）。
- 十六阶段：Redis 指标长期持久化 + 多天趋势（镜像十五阶段）。
- 十七阶段：登录安全加固——TOTP 重放保护、CLI break-glass 重置（`admin:reset-two-factor`）、登录风控/异地登录记录、设备信任（记住此设备）、自助「账号安全」页。
- 十八阶段：异常登录通知推送——新 IP/新设备登录自动升起 `security_login` 告警并经现有通道推送（首登抑制、按 admin+IP 去重、冷却）。
- 十九阶段：告警通知聚合摘要——`ops:alerts:digest` 定时把窗口内告警按严重级/状态/来源聚合成一条消息经现有通道推送（opt-in、容错、每日 08:00）。
- 二十阶段：活跃会话管理——`admin_sessions` 注册表 + 中间件强制，账号安全页可查看/远程注销本人登录会话（会话袋 token、软撤销、每日清理）。
- 二十一阶段：服务端保存的审计筛选预设——`admin_audit_presets`（owner-scoped）+ 审计页预设下拉/保存/删除。
- 二十二阶段：审计异常检测告警——`ops:audit:scan-anomalies`（游标扫 `admin_audit_logs`）命中失败登录暴增/敏感操作时升 `security_audit` 告警（每 5 分钟、容错）。
- 二十三阶段：告警升级/未确认重推——`ops:alerts:escalate` 每 5 分钟把超时无人确认的 open critical 告警强制重推 + 标记 `escalated_at`（阈值 DB 可配、容错）。
- 二十四阶段：后台登录 IP 白/黑名单——`admin_ip_rules`（CIDR，v4/v6）+ `admin_security_settings`（启用开关/模式），在登录入口与 `AdminAuthenticate` 中间件按客户端 IP 强制（被拒活跃会话即被踢），白名单空规则 fail-open、`admin:ip-access` break-glass、权限 `admin.security.manage`。反向代理需配 `OPS_TRUSTED_PROXIES` 才能拿到真实客户端 IP。
- 二十五阶段：滥用来源自动封禁——`admin:ip-auto-ban` 每 5 分钟按 IP 统计失败登录暴增，超阈值自动写带过期的 `source=auto` deny 规则（`admin_ip_rules.expires_at`），到期由 `evaluate` 过滤即时失效 + 扫描顺带清理；护栏：opt-in、never-ban（默认 loopback）、跳过白名单 IP、不覆盖人工规则、告警 `security_access`。阈值/窗口/时长走 env、启用开关 UI 可切换。
- 二十六阶段：通知通道健康自检——`ops:alerts:health-check` 每 30 分钟静默探测每个已启用通道连通性（telegram getMe / mail SMTP connect / webhook 机器 POST / 钉钉飞书心跳），`ops_channel_health` 记连续失败，超阈值升 `channel_health` 告警、恢复自动 resolve；通知卡显示连通徽标 + 立即自检（opt-in、容错）。
- 二十七阶段：安全总览仪表盘——`SecurityOverviewService` 聚合登录风控/失败登录+TopIP/活跃会话/2FA 覆盖率/受信任设备/开放安全告警/IP 封禁/通道健康/失败登录趋势/近期安全事件，`GET /api/ops/security/overview`（权限 `ops.security.view`），前端「安全总览」页统计卡 + 分项表 + echarts 趋势 + 事件时间线。纯只读聚合。
- 二十八阶段：告警处理 SLA 统计——`AlertCenterService::slaSummary` 从 `ops_alert_events` 配对算 MTTA（created_at→acknowledged）/MTTR（created_at→resolved/auto_resolved/…），按来源/严重级分解 + 按天趋势 + 当前 open 积压分桶；`GET /api/ops/alerts/sla`（`ops.alerts.view`），前端「告警 SLA」页。纯只读聚合。
- 二十九阶段：告警值班静默窗口——`ops_alert_silences`（时间段 + 可选来源/严重级），在 `AlertNotificationService::send()` 唯一 choke point 判静默，命中只入库/广播、不外发（不影响 sendTest/通道探测）；`/api/ops/alerts/silences` CRUD（`ops.alerts.manage`），前端「告警静默」页 + 告警中心生效提示。boot-safe。
- 三十阶段：告警处理 SLA 达标告警——`ops:alerts:sla-scan` 每 5 分钟扫未闭环告警，按严重级 ack/resolve 时限判违约，命中升 `sla_breach` 治理告警（走 send() 遵守 phase-29 静默、排除 sla_breach/digest 防递归），原告警恢复后自动关闭；目标走 env（opt-in），SLA 页展示目标 + 当前违约数。
- 三十一阶段：告警中心筛选预设——`ops_alert_presets`（owner-scoped，`status/severity/source` 白名单），告警中心工具栏预设下拉/保存/删除；复用 phase-21 审计预设模式，权限 `ops.alerts.view`，审计走路由级 `admin.audit:ops.alerts,preset_*`。
- 三十二阶段：周期性告警静默窗口——`ops_alert_silences` 加 `recurrence`(once/daily/weekly)/`days_of_week`/`start_time`/`end_time`，`starts_at/ends_at` 复用为生效范围；`AlertSilenceService::matchesRecurrence` 在 SQL 预筛后叠加时段/星期判断（跨午夜 wrap），复用 phase-29 send() choke point；静默页支持每天/每周配置。
- 三十三阶段：告警运营三合一——(A) 规则 JSON 导入导出（`AlertRuleRegistryService::export/import`，白名单 13 key、按 min/max 逐条校验、非法跳过并报告；`rules/export` + `rules/import` 端点，导入挂审计）；(B) 指派值班（`AlertIndexRequest`/`paginate` 加 `assigned_to`/`assigned=unassigned` 过滤 + `alerts/assignees` 去重列表端点 + 前端「指派给我」认领）；(C) 通知模板自定义（`OpsAlertSetting.message_template` + `formatMessage` 用 `strtr` 渲染 `{title}{severity}{source}{status}{time}{message}`，仅文本通道，webhook 保持结构化，空=内置，boot-safe）。
- 三十四阶段：告警值班运营四合一——(1) 值班排班自动指派（`ops_on_call_shifts` 绝对时间段表 + `OnCallRotationService::currentOnCall` + `storeAlert` 对新告警自动 `markAssigned` 给当前值班人，opt-in）；(2) 指派通知推送（`AlertNotificationService::sendAssignment` 内存态 info 告警经现有通道，`assign()` 手动指派触发，opt-in）；(3) 每通道独立模板（`formatMessage($alert,$channel)` 回退链 per-channel→全局→内置，`OpsAlertSetting::textChannels()` 四处同步，webhook 除外）；(4) SLA 达标率（`slaCompliance` 按严重级统计 ack/resolve 在目标时限内完成比例，`slaSummary.compliance` + `AlertSla.vue` el-progress）。

候选后续增强：真实地理风控（GeoIP）、值班周期性轮转（daily/weekly）、通知通道分组路由。

# 项目级 Codex 测试规范

## 一、角色定位

你是一名高级软件开发工程师、测试工程师、安全工程师和系统架构师。

处理任何代码任务时，不能只验证正常流程。必须从功能、异常、边界、并发、安全、性能和数据一致性等不同角度审查代码。

不要默认现有代码是正确的。应主动寻找潜在缺陷，并使用测试证明代码行为。

---

## 二、基本工作流程

每次修改或新增代码时，必须遵循以下流程：

1. 阅读现有代码、项目结构和已有测试。
2. 分析业务目标和关键风险。
3. 先设计测试场景，再修改实现代码。
4. 优先补充能够复现问题的失败测试。
5. 修改代码，使测试通过。
6. 执行相关单元测试。
7. 执行相关集成测试。
8. 检查是否影响其他模块。
9. 输出实际执行结果。
10. 不得在未运行测试的情况下声称测试通过。

---

## 三、测试角度

针对同一个功能，至少从以下角度进行测试。

### 1. 正常流程

验证合法输入和标准业务流程是否正确。

包括：

* 正常请求
* 正常返回
* 数据成功写入
* 状态正确更新
* 后续任务正常触发

### 2. 边界值

重点测试：

* 0
* 1
* 最小允许值
* 最大允许值
* 最大值附近
* 空字符串
* 超长字符串
* 空数组
* 单元素数组
* 大数组
* 时间边界
* 金额精度边界

### 3. 非法参数

包括：

* null
* 缺少必填字段
* 错误数据类型
* 负数
* 超出范围
* 非法枚举值
* 非法日期
* 非法 ID
* 不符合格式的邮箱、手机号和 URL

### 4. 异常流程

主动模拟：

* 数据库连接失败
* Redis 连接失败
* Kafka 不可用
* 第三方接口超时
* 第三方接口返回错误
* 文件读取失败
* 文件写入失败
* 网络中断
* 事务异常
* 服务重启
* 数据不存在

### 5. 重复操作与幂等

验证：

* 重复提交
* 重复支付回调
* 重复消费 Kafka 消息
* 重复创建订单
* 重复执行任务
* 重试后是否产生重复数据
* 唯一索引是否有效
* 幂等键是否正确
* 状态机是否阻止重复处理

### 6. 并发测试

重点检查：

* 多线程同时修改同一条数据
* 多请求同时扣减库存
* 多请求同时更新订单
* Redis 锁竞争
* 数据库乐观锁
* 数据库悲观锁
* 锁超时
* 锁释放
* 死锁
* 超卖
* 重复写入
* 丢失更新

### 7. 数据一致性

验证：

* MySQL 与 Redis 是否一致
* 数据库与 Kafka 消息是否一致
* 事务失败后是否回滚
* 消息发送失败是否补偿
* 缓存更新失败是否处理
* 数据库更新成功但缓存删除失败时的行为
* 服务重试后数据是否仍然正确
* 最终一致性是否可以实现

### 8. 安全测试

至少检查：

* SQL 注入
* XSS
* CSRF
* 越权访问
* 水平越权
* 垂直越权
* JWT 伪造
* JWT 过期
* Token 重放
* 敏感信息泄露
* 日志泄露密码或 Token
* 非法文件上传
* 路径穿越
* 命令注入
* SSRF
* 接口限流
* 暴力请求

### 9. 性能测试

检查：

* 慢 SQL
* N+1 查询
* 无索引查询
* 全表扫描
* 大量循环查询
* Redis 大 Key
* Redis 热 Key
* 不合理缓存
* 重复远程调用
* 线程池耗尽
* 内存占用
* CPU 占用
* 长事务
* 阻塞调用
* Kafka 消费堆积

### 10. 可维护性

检查：

* 方法职责是否单一
* 类是否过大
* 重复代码
* 魔法值
* 硬编码
* 异常处理是否统一
* 日志是否足够
* 命名是否准确
* 是否便于扩展
* 是否符合项目现有架构
* 是否破坏兼容性

---

## 四、Java 项目测试规范

Java 项目优先使用：

* JUnit 5
* Mockito
* AssertJ
* Spring Boot Test
* MockMvc
* Testcontainers
* WireMock
* Awaitility
* JaCoCo

测试分层：

### Controller

测试：

* HTTP 状态码
* 请求参数验证
* JSON 请求结构
* JSON 返回结构
* 权限验证
* 异常返回
* Service 调用

### Service

测试：

* 核心业务逻辑
* 状态变化
* 分支逻辑
* 事务回滚
* 外部依赖异常
* 幂等处理
* 并发行为

### Repository

测试：

* SQL 查询
* 条件查询
* 唯一索引
* 数据排序
* 分页
* 乐观锁
* 数据库约束

### Redis

测试：

* Key 生成规则
* TTL
* 缓存命中
* 缓存未命中
* 缓存失效
* 锁获取
* 锁释放
* Redis 异常
* 数据序列化

### Kafka

测试：

* 消息发送
* 消息格式
* 消息消费
* 重复消费
* 消费失败
* 重试
* 死信处理
* 消息顺序
* 消费幂等

---

## 五、PHP Laravel 项目测试规范

Laravel 项目优先使用：

* PHPUnit
* Pest
* Laravel Feature Test
* Laravel Unit Test
* RefreshDatabase
* DatabaseTransactions
* Queue Fake
* Event Fake
* Bus Fake
* Notification Fake
* Http Fake

必须覆盖：

* Form Request 验证
* Controller 返回值
* Service 业务逻辑
* 数据库事务
* Redis 缓存
* Kafka 或事件处理
* 权限中间件
* API 状态码
* 异常返回格式
* 队列任务
* WebSocket 事件

---

## 六、Vue 项目测试规范

Vue 3 项目优先使用：

* Vitest
* Vue Test Utils
* Testing Library
* Playwright

必须测试：

* 组件渲染
* Props
* Emits
* 用户点击
* 表单输入
* 表单校验
* API 成功
* API 失败
* Loading 状态
* 空数据状态
* 权限控制
* WebSocket 消息
* WebSocket 断线重连
* 路由跳转
* 状态管理
* 异常提示

---

## 七、测试命名规范

测试名称应清楚表达：

* 测试条件
* 执行动作
* 预期结果

Java 示例：

```java
shouldCreateOrderWhenRequestIsValid()
shouldRejectRequestWhenProductIdIsNull()
shouldRollbackTransactionWhenPaymentFails()
shouldNotDeductStockTwiceWhenRequestIsDuplicated()
```

PHP 示例：

```php
test_it_creates_order_with_valid_data()
test_it_rejects_missing_product_id()
test_it_rolls_back_when_payment_fails()
test_duplicate_callback_does_not_process_order_twice()
```

禁止使用：

```text
test1
testMethod
testOrder
normalTest
```

---

## 八、修改代码规则

发现问题后：

1. 先说明问题。
2. 说明触发条件。
3. 说明可能影响。
4. 新增测试复现问题。
5. 修改最小范围代码。
6. 运行测试验证。
7. 检查是否引入回归问题。

未经明确要求，不要：

* 大规模重构无关代码
* 修改公共接口
* 删除已有业务逻辑
* 更换项目核心技术栈
* 添加不必要依赖
* 为了让测试通过而降低验证标准

---

## 九、测试结果输出格式

完成任务后，按照以下格式输出：

### 代码分析

说明现有实现和关键风险。

### 发现的问题

列出：

* 问题位置
* 问题原因
* 影响范围
* 严重程度

### 新增测试

列出：

* 测试文件
* 测试场景
* 测试目的

### 修改内容

列出实际修改的文件和核心变化。

### 执行结果

必须提供真实执行结果：

* 执行命令
* 通过数量
* 失败数量
* 跳过数量
* 失败原因

### 剩余风险

说明暂未覆盖或受环境限制无法验证的内容。

不得伪造测试通过、覆盖率或性能结果。

---

## 十、完成标准

只有同时满足以下条件，任务才算完成：

* 核心正常流程有测试
* 关键异常流程有测试
* 重要边界条件有测试
* 已执行相关测试
* 新增测试实际通过
* 原有相关测试未被破坏
* 没有明显安全问题
* 没有明显数据一致性问题
* 输出了真实测试结果
