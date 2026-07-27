# Ops Center Phase 32 — 周期性告警静默窗口（每天/每周重复）

## 范围

phase 29 的静默只支持一次性绝对时间段。本阶段扩展：支持 **daily（每天某时段）/ weekly（每周某几天某时段）**
重复的维护窗口（如每晚 02:00–04:00 跑批、每周日凌晨备份），命中期间照样只压外发、仍入库。复用 phase-29 的
`send()` choke point，无新机制。

## 关键设计

- **`starts_at`/`ends_at` 复用为「生效范围」**（所有类型必填）：`once` 即该绝对窗口；`daily`/`weekly` 是重复模式的
  生效起止（如「每晚 2–4 点，未来两周有效」）。好处：`isSilenced`/`activeSilences` 的 SQL 预筛
  `is_active && starts_at<=now && ends_at>=now` **不变**，只在匹配循环里加一层时段/星期判断，无需改已建列。
- **匹配**：`matchesRecurrence($silence,$now)`：
  - `once` → true（绝对窗口已由 SQL 预筛）。
  - `daily` → `timeInWindow($now, start_time, end_time)`。
  - `weekly` → `in_array($now->dayOfWeek, days_of_week)` 且 `timeInWindow`（Carbon dayOfWeek 0=周日…6=周六）。
  - `timeInWindow`：当前 `H:i` 字典序比较；`start<=end` 常规，否则跨午夜 wrap（`t>=start || t<=end`）。
- boot-safe 不变；**不影响** `sendTest`/通道探测（不走 `send()`）。

## 数据表 / 校验

- `ops_alert_silences` 加 `recurrence` enum(once/daily/weekly)、`days_of_week`(json)、`start_time`/`end_time`(HH:MM)。
- `AlertSilenceStoreRequest`：`recurrence` in(once/daily/weekly)；`start_time`/`end_time` `date_format:H:i` + `required_if:recurrence,daily|weekly`；`days_of_week` 数组 0–6 + `required_if:recurrence,weekly`。`starts_at/ends_at` 仍必填（生效范围）。

## 前端

「告警静默」页新增 dialog 加「重复方式」`el-radio-group`（一次性/每天/每周）：daily/weekly 显示「每天时段」两个
`el-time-picker`（HH:mm），weekly 再显示星期多选。datetimerange 对周期性标为「生效范围」。表格按 recurrence
渲染时间段（每天 / 每周X HH:MM–HH:MM）。

## 安全边界 / 不做

- 复用 phase-29 choke point：只压外发、告警仍入库/广播/可见。
- weekly 跨午夜的「溢出到次日」部分按当前 dow 判定（不追踪窗口起始日）——建议 weekly 不跨午夜。
- **不做**：cron 表达式、按月/节假日、时区可配（用应用时区 `now()`）、「下次生效时间」预测。

## 验收

```
docker … php vendor/bin/phpunit --filter 'PhaseThirtyTwoRecurringSilence|PhaseTwentyNineAlertSilence'
docker … php vendor/bin/phpunit          # 全量回归
npm run build
rg -n "v-html|innerHTML" resources/js/pages/ops/AlertSilence.vue
# 手动：建「每天 02:00–04:00」静默(source=disk) → 该时段 disk 告警不外发、仍入库；非该时段正常外发；每周选周日同理。
```
