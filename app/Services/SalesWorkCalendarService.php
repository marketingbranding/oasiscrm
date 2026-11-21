<?php

namespace App\Services;

use Carbon\CarbonImmutable;

class SalesWorkCalendarService
{
    public function __construct(private readonly AppSettingResolver $settings) {}

    /**
     * @return array{is_weekend: bool, is_free_day: bool, start_time: ?string, end_time: ?string, break_start: ?string, break_end: ?string, target_minutes: int, requires_target: bool}
     */
    public function schedule(CarbonImmutable $date, ?int $branchId = null): array
    {
        if ($date->isWeekend()) {
            return $this->freeSchedule(true);
        }

        $isFriday = $date->dayOfWeekIso === 5;

        return [
            'is_weekend' => false,
            'is_free_day' => false,
            'start_time' => $this->settings->string($isFriday ? 'sales_agenda.friday_start_time' : 'sales_agenda.weekday_start_time'),
            'end_time' => $this->settings->string($isFriday ? 'sales_agenda.friday_end_time' : 'sales_agenda.weekday_end_time'),
            'break_start' => $this->settings->string($isFriday ? 'sales_agenda.friday_break_start' : 'sales_agenda.weekday_break_start'),
            'break_end' => $this->settings->string($isFriday ? 'sales_agenda.friday_break_end' : 'sales_agenda.weekday_break_end'),
            'target_minutes' => $this->settings->int('sales_agenda.target_minutes'),
            'requires_target' => true,
        ];
    }

    public function isFreeDay(CarbonImmutable $date, ?int $branchId = null): bool
    {
        return $date->isWeekend();
    }

    public function effectiveMinutes(CarbonImmutable $date, ?string $startTime, ?string $endTime, ?int $branchId = null): int
    {
        if ($startTime === null || $endTime === null) {
            return 0;
        }

        $start = $this->timeToMinutes($startTime);
        $end = $this->timeToMinutes($endTime);
        $duration = max(0, $end - $start);
        if ($duration === 0 || $this->isFreeDay($date, $branchId)) {
            return $duration;
        }

        $schedule = $this->schedule($date, $branchId);
        $breakStart = $this->timeToMinutes($schedule['break_start']);
        $breakEnd = $this->timeToMinutes($schedule['break_end']);

        return max(0, $duration - max(0, min($end, $breakEnd) - max($start, $breakStart)));
    }

    public function forgetSettings(): void
    {
        foreach ([
            'sales_agenda.target_minutes',
            'sales_agenda.weekday_start_time',
            'sales_agenda.weekday_end_time',
            'sales_agenda.weekday_break_start',
            'sales_agenda.weekday_break_end',
            'sales_agenda.friday_start_time',
            'sales_agenda.friday_end_time',
            'sales_agenda.friday_break_start',
            'sales_agenda.friday_break_end',
        ] as $key) {
            $this->settings->forget($key);
        }
    }

    /**
     * @return array{is_weekend: bool, is_free_day: bool, start_time: null, end_time: null, break_start: null, break_end: null, target_minutes: int, requires_target: bool}
     */
    private function freeSchedule(bool $isWeekend): array
    {
        return [
            'is_weekend' => $isWeekend,
            'is_free_day' => true,
            'start_time' => null,
            'end_time' => null,
            'break_start' => null,
            'break_end' => null,
            'target_minutes' => 0,
            'requires_target' => false,
        ];
    }

    private function timeToMinutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', substr($time, 0, 5)));

        return ($hours * 60) + $minutes;
    }
}
