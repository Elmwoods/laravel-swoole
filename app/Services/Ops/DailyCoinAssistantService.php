<?php

namespace App\Services\Ops;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class DailyCoinAssistantService
{
    private const HISTORY_LIMIT = 30;

    public function summary(): array
    {
        $today = $this->today();
        $state = $this->state();
        $record = $state['records'][$today] ?? $this->pendingRecord($today);
        $reminderSent = in_array($today, $state['reminders'], true);

        return [
            'today' => $record,
            'reminder' => [
                'due' => ! $reminderSent && $record['status'] === 'pending',
                'sent_today' => $reminderSent,
            ],
            'history' => $this->history($state),
            'safety' => [
                'private_token_automation_allowed' => false,
            ],
            'checked_at' => now()->toDateTimeString(),
        ];
    }

    public function markReminder(): array
    {
        $today = $this->today();
        $state = $this->state();
        $state['reminders'] = array_values(array_unique([...$state['reminders'], $today]));
        $state['records'][$today] ??= $this->pendingRecord($today);
        $this->save($state);

        return $this->summary();
    }

    public function confirm(array $payload): array
    {
        $today = $this->today();
        $state = $this->state();
        $state['records'][$today] = [
            'date' => $today,
            'status' => $payload['status'],
            'coins' => $payload['status'] === 'claimed' ? ($payload['coins'] ?? null) : null,
            'note' => $payload['note'] ?? null,
            'updated_at' => now()->toDateTimeString(),
        ];
        $this->save($state);

        return $this->summary();
    }

    private function state(): array
    {
        return Cache::get($this->cacheKey(), [
            'records' => [],
            'reminders' => [],
        ]);
    }

    private function save(array $state): void
    {
        Cache::put($this->cacheKey(), $state, now()->addDays(60));
    }

    private function history(array $state): array
    {
        return collect($state['records'] ?? [])
            ->sortKeysDesc()
            ->take(self::HISTORY_LIMIT)
            ->values()
            ->all();
    }

    private function pendingRecord(string $date): array
    {
        return [
            'date' => $date,
            'status' => 'pending',
            'coins' => null,
            'note' => null,
            'updated_at' => null,
        ];
    }

    private function today(): string
    {
        return Carbon::today()->toDateString();
    }

    private function cacheKey(): string
    {
        return 'ops:daily-coin-assistant';
    }
}
