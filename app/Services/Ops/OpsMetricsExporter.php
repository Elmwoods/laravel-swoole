<?php

namespace App\Services\Ops;

use Throwable;

/**
 * Prometheus 文本指标导出：从现有只读聚合方法取标量，拼 exposition 文本。boot-safe（各源独立 try/catch）。
 */
class OpsMetricsExporter
{
    public function __construct(
        private readonly AlertCenterService $alerts,
        private readonly OnCallRotationService $onCall,
        private readonly SecurityOverviewService $security,
    ) {}

    public function render(): string
    {
        $lines = [];

        // 告警开放数。
        try {
            $s = $this->alerts->summary();
            $lines[] = '# HELP ops_alerts_open Open alerts by severity.';
            $lines[] = '# TYPE ops_alerts_open gauge';
            foreach (['critical', 'warning', 'info'] as $sev) {
                $lines[] = $this->metric('ops_alerts_open', (int) ($s[$sev] ?? 0), ['severity' => $sev]);
            }
            $lines[] = $this->metric('ops_alerts_open_total', (int) ($s['open_total'] ?? 0));
        } catch (Throwable) {
        }

        // SLA。
        try {
            $sla = $this->alerts->slaSummary(7);
            $lines[] = '# TYPE ops_sla_mtta_seconds gauge';
            $lines[] = $this->metric('ops_sla_mtta_seconds', (int) ($sla['mtta']['avg_seconds'] ?? 0));
            $lines[] = '# TYPE ops_sla_mttr_seconds gauge';
            $lines[] = $this->metric('ops_sla_mttr_seconds', (int) ($sla['mttr']['avg_seconds'] ?? 0));
            $lines[] = '# TYPE ops_sla_open_breaches gauge';
            $lines[] = $this->metric('ops_sla_open_breaches', (int) ($sla['open_breaches'] ?? 0));

            $lines[] = '# TYPE ops_alerts_open_aging gauge';
            foreach (['under_1h', 'one_to_24h', 'over_24h'] as $bucket) {
                $lines[] = $this->metric('ops_alerts_open_aging', (int) ($sla['open_aging'][$bucket] ?? 0), ['bucket' => $bucket]);
            }

            $lines[] = '# HELP ops_sla_compliance_ratio SLA compliance percent (0-100) by kind and severity.';
            $lines[] = '# TYPE ops_sla_compliance_ratio gauge';
            foreach (['ack', 'resolve'] as $kind) {
                foreach (['critical', 'warning', 'info'] as $sev) {
                    $rate = $sla['compliance'][$kind][$sev]['rate'] ?? null;
                    if ($rate !== null) {
                        $lines[] = $this->metric('ops_sla_compliance_ratio', (int) $rate, ['kind' => $kind, 'severity' => $sev]);
                    }
                }
            }
        } catch (Throwable) {
        }

        // 通知通道。
        try {
            $status = $this->alerts->notificationStatus();
            $lines[] = '# TYPE ops_channel_enabled gauge';
            $healthLines = [];
            foreach ($status as $channel => $item) {
                if (! is_array($item) || in_array($channel, ['settings'], true) || ! isset($item['enabled'])) {
                    continue;
                }
                $lines[] = $this->metric('ops_channel_enabled', $item['enabled'] ? 1 : 0, ['channel' => $channel]);
                $healthLines[] = $this->metric('ops_channel_healthy', ($item['health'] ?? 'unknown') === 'healthy' ? 1 : 0, ['channel' => $channel]);
            }
            $lines[] = '# TYPE ops_channel_healthy gauge';
            $lines = array_merge($lines, $healthLines);
        } catch (Throwable) {
        }

        // 值班在岗。
        try {
            $lines[] = '# TYPE ops_on_call_present gauge';
            $lines[] = $this->metric('ops_on_call_present', $this->onCall->currentOnCall() !== null ? 1 : 0);
        } catch (Throwable) {
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
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  array<string, string>  $labels
     */
    private function metric(string $name, int $value, array $labels = []): string
    {
        if ($labels === []) {
            return "{$name} {$value}";
        }

        $pairs = [];
        foreach ($labels as $key => $val) {
            $escaped = str_replace(['\\', '"', "\n"], ['\\\\', '\\"', ' '], (string) $val);
            $pairs[] = "{$key}=\"{$escaped}\"";
        }

        return $name.'{'.implode(',', $pairs)."} {$value}";
    }
}
