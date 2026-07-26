<?php

namespace App\Services\Ops;

use App\Models\AdminUser;
use App\Models\OpsAlert;
use App\Models\OpsAlertSilence;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Throwable;

/**
 * 告警值班静默窗口：维护窗口内命中的告警只入库/广播、不外发到通道。
 *
 * 判断在 AlertNotificationService::send() 的 choke point 调用，boot-safe（表缺失/出错→不静默，绝不阻断告警）。
 */
class AlertSilenceService
{
    private const SEVERITIES = ['critical', 'warning', 'info'];

    /**
     * 该告警此刻是否被某个生效中的静默命中。
     */
    public function isSilenced(OpsAlert $alert): bool
    {
        try {
            if (! Schema::hasTable('ops_alert_silences')) {
                return false;
            }

            $now = now();

            $silences = OpsAlertSilence::query()
                ->where('is_active', true)
                ->where('starts_at', '<=', $now)
                ->where('ends_at', '>=', $now)
                ->get(['sources', 'severities']);

            foreach ($silences as $silence) {
                if ($this->matches($silence, (string) $alert->source, (string) $alert->severity)) {
                    return true;
                }
            }

            return false;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * 当前生效中的静默（供前端 banner / 页面高亮）。
     *
     * @return array<int, array<string, mixed>>
     */
    public function activeSilences(): array
    {
        $now = now();

        return OpsAlertSilence::query()
            ->where('is_active', true)
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>=', $now)
            ->orderByDesc('starts_at')
            ->get()
            ->map(fn (OpsAlertSilence $s): array => $this->serialize($s))
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(): array
    {
        return OpsAlertSilence::query()
            ->orderByDesc('starts_at')
            ->get()
            ->map(fn (OpsAlertSilence $s): array => $this->serialize($s))
            ->all();
    }

    public function create(array $data, ?AdminUser $actor = null): OpsAlertSilence
    {
        $startsAt = $data['starts_at'] ?? null;
        $endsAt = $data['ends_at'] ?? null;

        if ($startsAt === null || $endsAt === null || strtotime((string) $endsAt) <= strtotime((string) $startsAt)) {
            throw new InvalidArgumentException('结束时间必须晚于开始时间。');
        }

        return OpsAlertSilence::query()->create([
            'label' => isset($data['label']) && trim((string) $data['label']) !== '' ? trim((string) $data['label']) : null,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'sources' => $this->cleanStrings($data['sources'] ?? []),
            'severities' => array_values(array_intersect($this->cleanStrings($data['severities'] ?? []), self::SEVERITIES)),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'created_by' => $actor?->id,
        ]);
    }

    public function toggle(int $id, bool $active): bool
    {
        return OpsAlertSilence::query()->whereKey($id)->update(['is_active' => $active]) > 0;
    }

    public function delete(int $id): bool
    {
        return OpsAlertSilence::query()->whereKey($id)->delete() > 0;
    }

    private function matches(OpsAlertSilence $silence, string $source, string $severity): bool
    {
        $sources = (array) ($silence->sources ?? []);
        $severities = (array) ($silence->severities ?? []);

        $sourceOk = $sources === [] || in_array($source, $sources, true);
        $severityOk = $severities === [] || in_array($severity, $severities, true);

        return $sourceOk && $severityOk;
    }

    /**
     * @return array<int, string>
     */
    private function cleanStrings(mixed $value): array
    {
        return collect((array) $value)
            ->map(fn ($v): string => trim((string) $v))
            ->filter(fn (string $v): bool => $v !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(OpsAlertSilence $silence): array
    {
        return [
            'id' => $silence->id,
            'label' => $silence->label,
            'starts_at' => optional($silence->starts_at)->toDateTimeString(),
            'ends_at' => optional($silence->ends_at)->toDateTimeString(),
            'sources' => (array) ($silence->sources ?? []),
            'severities' => (array) ($silence->severities ?? []),
            'is_active' => (bool) $silence->is_active,
            'created_at' => optional($silence->created_at)->toDateTimeString(),
        ];
    }
}
