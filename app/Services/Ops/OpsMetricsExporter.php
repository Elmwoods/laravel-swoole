<?php

namespace App\Services\Ops;

use Throwable;

/**
 * Prometheus 文本指标导出：从现有只读聚合方法取标量，拼 exposition 文本。boot-safe（各源独立 try/catch）。
 *
 * 在告警子系统（raise 告警 -> notify 通知 -> broadcast 广播）中的定位：
 *  - 本类是「广播 broadcast 的对外一支」——把子系统各处的现状（开放告警数、SLA、通道健康、
 *    是否有人在岗、安全总览）压平成 Prometheus 抓取端点能读的文本，供外部监控/看板拉取与告警。
 *  - 与 notify 通知的关系：ops_channel_enabled/healthy 暴露各通知通道是否开启与是否健康，
 *    ops_on_call_present 暴露此刻是否有人值班（=告警发生时是否有人会被 notify），
 *    因此这些指标本身可被外部系统用来「对通知链路做二次告警」（比如无人在岗时触发上报）。
 *  - 全只读、只取标量、绝不写状态。
 *
 * boot-safe：render() 把每个数据源各自包在独立 try/catch 里，任一源出错只丢它那一段指标，
 * 其余指标照常输出——保证监控端点尽量不整体 500，最大化可观测性可用度。
 */
class OpsMetricsExporter
{
    /**
     * 作用：注入三个只读聚合来源，指标全部从它们现成的方法里取，不新增查询逻辑。
     *
     * @param  AlertCenterService  $alerts  提供开放告警数、SLA 汇总、通知通道状态
     * @param  OnCallRotationService  $onCall  提供当前是否有人值班
     * @param  SecurityOverviewService  $security  提供安全总览标量（会话/2FA/IP 规则）
     */
    public function __construct(
        private readonly AlertCenterService $alerts,
        private readonly OnCallRotationService $onCall,
        private readonly SecurityOverviewService $security,
    ) {}

    /**
     * 作用：渲染完整的 Prometheus exposition 文本（多组 HELP/TYPE + 样本行），供抓取端点直接返回。
     *
     * 为什么每段独立 try/catch：各数据源相互独立，某源异常（如某表缺失）不应连累其它指标；
     * catch 里刻意留空，是「静默跳过这一段」的有意设计，而非遗漏。
     *
     * @return string 以换行拼接、并以结尾换行收束的 Prometheus 文本
     */
    public function render(): string
    {
        $lines = [];

        // 告警开放数。
        try {
            $s = $this->alerts->summary();
            $lines[] = '# HELP ops_alerts_open Open alerts by severity.';
            $lines[] = '# TYPE ops_alerts_open gauge';
            // 按严重级各输出一条带 severity 标签的样本，缺键时以 0 兜底。
            foreach (['critical', 'warning', 'info'] as $sev) {
                $lines[] = $this->metric('ops_alerts_open', (int) ($s[$sev] ?? 0), ['severity' => $sev]);
            }
            // 再输出一条无标签的总计，方便外部直接读总量。
            $lines[] = $this->metric('ops_alerts_open_total', (int) ($s['open_total'] ?? 0));
        } catch (Throwable) {
            // 静默跳过本段：告警汇总取不到时不输出这组指标，其余指标不受影响。
        }

        // SLA。
        try {
            $sla = $this->alerts->slaSummary(7); // 固定取最近 7 天窗口的 SLA 汇总
            $lines[] = '# TYPE ops_sla_mtta_seconds gauge';
            $lines[] = $this->metric('ops_sla_mtta_seconds', (int) ($sla['mtta']['avg_seconds'] ?? 0));
            $lines[] = '# TYPE ops_sla_mttr_seconds gauge';
            $lines[] = $this->metric('ops_sla_mttr_seconds', (int) ($sla['mttr']['avg_seconds'] ?? 0));
            $lines[] = '# TYPE ops_sla_open_breaches gauge';
            $lines[] = $this->metric('ops_sla_open_breaches', (int) ($sla['open_breaches'] ?? 0));

            $lines[] = '# TYPE ops_alerts_open_aging gauge';
            // 积压按停留时长分三桶输出，每桶带 bucket 标签。
            foreach (['under_1h', 'one_to_24h', 'over_24h'] as $bucket) {
                $lines[] = $this->metric('ops_alerts_open_aging', (int) ($sla['open_aging'][$bucket] ?? 0), ['bucket' => $bucket]);
            }

            $lines[] = '# HELP ops_sla_compliance_ratio SLA compliance percent (0-100) by kind and severity.';
            $lines[] = '# TYPE ops_sla_compliance_ratio gauge';
            // 按 (类型 ack/resolve) x (严重级) 笛卡尔积逐格输出达标率。
            foreach (['ack', 'resolve'] as $kind) {
                foreach (['critical', 'warning', 'info'] as $sev) {
                    $rate = $sla['compliance'][$kind][$sev]['rate'] ?? null;
                    // rate 为 null（该组合无样本）时跳过，不输出无意义的 0，避免误导下游。
                    if ($rate !== null) {
                        $lines[] = $this->metric('ops_sla_compliance_ratio', (int) $rate, ['kind' => $kind, 'severity' => $sev]);
                    }
                }
            }
        } catch (Throwable) {
            // 静默跳过 SLA 段。
        }

        // 通知通道。
        try {
            $status = $this->alerts->notificationStatus();
            $lines[] = '# TYPE ops_channel_enabled gauge';
            $healthLines = []; // 健康度样本先暂存，待所有通道遍历完再统一追加，保证 TYPE 头与样本相邻不被打散
            foreach ($status as $channel => $item) {
                // 过滤掉非通道条目：非数组、聚合用的 settings 键、以及没有 enabled 字段的项都不是「通道」。
                if (! is_array($item) || in_array($channel, ['settings'], true) || ! isset($item['enabled'])) {
                    continue;
                }
                // 通道启用与否 -> 1/0，带 channel 标签。
                $lines[] = $this->metric('ops_channel_enabled', $item['enabled'] ? 1 : 0, ['channel' => $channel]);
                // 健康度：仅 'healthy' 记 1，其余（含缺失时的 'unknown'）记 0。
                $healthLines[] = $this->metric('ops_channel_healthy', ($item['health'] ?? 'unknown') === 'healthy' ? 1 : 0, ['channel' => $channel]);
            }
            $lines[] = '# TYPE ops_channel_healthy gauge';
            $lines = array_merge($lines, $healthLines); // 把暂存的 healthy 样本接在其 TYPE 头之后
        } catch (Throwable) {
            // 静默跳过通道段。
        }

        // 值班在岗。
        try {
            $lines[] = '# TYPE ops_on_call_present gauge';
            // 当前有人值班 -> 1，否则 0；供外部对「告警时无人接收 notify」的风险做二次告警。
            $lines[] = $this->metric('ops_on_call_present', $this->onCall->currentOnCall() !== null ? 1 : 0);
        } catch (Throwable) {
            // 静默跳过值班段。
        }

        // 安全总览标量。
        try {
            $ov = $this->security->overview();
            $lines[] = '# TYPE ops_security_sessions_active gauge';
            $lines[] = $this->metric('ops_security_sessions_active', (int) ($ov['sessions']['active'] ?? 0));
            $lines[] = '# TYPE ops_security_2fa_coverage_percent gauge';
            $lines[] = $this->metric('ops_security_2fa_coverage_percent', (int) ($ov['two_factor']['coverage_percent'] ?? 0));
            $lines[] = '# TYPE ops_ip_deny_active gauge';
            $lines[] = $this->metric('ops_ip_deny_active', (int) ($ov['ip_rules']['deny_active'] ?? 0));
            $lines[] = '# TYPE ops_ip_auto_ban_active gauge';
            $lines[] = $this->metric('ops_ip_auto_ban_active', (int) ($ov['ip_rules']['auto_ban_active'] ?? 0));
        } catch (Throwable) {
            // 静默跳过安全总览段。
        }

        // 以换行拼接全部行，并补一个结尾换行——Prometheus 文本格式要求最后一行也以换行收束。
        return implode("\n", $lines)."\n";
    }

    /**
     * 作用：把单个指标格式化为一行 Prometheus 样本文本（可带标签）。
     *
     * 为什么要转义标签值：标签值出现在双引号内，反斜杠/双引号/换行会破坏 exposition 语法，
     * 故按 Prometheus 规则转义（换行在此直接替换为空格，避免样本被拆成多行）。
     *
     * @param  string  $name  指标名
     * @param  int  $value  指标数值
     * @param  array<string, string>  $labels  标签键值对（空则输出无标签样本）
     * @return string 形如 name{k="v"} value 或 name value 的单行文本
     */
    private function metric(string $name, int $value, array $labels = []): string
    {
        // 无标签时走简化格式，省去大括号。
        if ($labels === []) {
            return "{$name} {$value}";
        }

        $pairs = [];
        foreach ($labels as $key => $val) {
            // 转义顺序：先转反斜杠本身，再转双引号，换行替换为空格，防止破坏样本行结构。
            $escaped = str_replace(['\\', '"', "\n"], ['\\\\', '\\"', ' '], (string) $val);
            $pairs[] = "{$key}=\"{$escaped}\"";
        }

        // 拼成 name{k1="v1",k2="v2"} value 的标准带标签样本。
        return $name.'{'.implode(',', $pairs)."} {$value}";
    }
}
