<?php

namespace App\Services;

use App\Models\ContentItem;
use App\Models\SalesDailyPlan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class SalesDailyPlanScheduleService
{
    public const MONDAY_THURSDAY_START_MINUTE = 9 * 60;

    public const MONDAY_THURSDAY_BREAK_START_MINUTE = 12 * 60;

    public const MONDAY_THURSDAY_BREAK_END_MINUTE = 13 * 60;

    public const FRIDAY_START_MINUTE = 8 * 60 + 30;

    public const FRIDAY_BREAK_START_MINUTE = 11 * 60 + 30;

    public const FRIDAY_BREAK_END_MINUTE = 13 * 60;

    public const END_MINUTE = 17 * 60;

    /**
     * @return array{items: array<int, array{id:int, start_time:string, end_time:string, duration_minutes:int}>, total_minutes:int, remaining_minutes:?int, end_time:?string, is_weekend:bool}
     */
    public function calculate(SalesDailyPlan $plan, ?Collection $agendas = null): array
    {
        $planDate = $plan->plan_date ?? $plan->work_date;
        if (! $planDate) {
            throw new InvalidArgumentException('Daily Plan harus memiliki tanggal.');
        }
        $date = CarbonImmutable::parse($planDate->toDateString(), config('app.timezone'));

        $items = ($agendas ?? $plan->agendas()->get())->sortBy([
            ['sort_order', 'asc'],
            ['id', 'asc'],
        ])->values();
        $isWeekend = $plan->isWeekend() || $date->isWeekend();

        $schedule = $isWeekend
            ? $this->calculateWeekend($date, $items)
            : $this->calculateWorkday($date, $items);
        $total = (int) $items->sum(fn (ContentItem $agenda): int => (int) $agenda->duration_minutes);

        return [
            'items' => $schedule,
            'total_minutes' => $total,
            'remaining_minutes' => $isWeekend ? null : SalesDailyPlan::REQUIRED_WORK_MINUTES - $total,
            'end_time' => $schedule !== [] ? $schedule[array_key_last($schedule)]['end_time'] : null,
            'is_weekend' => $isWeekend,
        ];
    }

    public function assertSubmitAllowed(array $schedule): void
    {
        if (($schedule['is_weekend'] ?? false) === true) {
            if ($schedule['items'] === []) {
                throw new InvalidArgumentException('Rencana akhir pekan harus memiliki minimal satu agenda.');
            }

            return;
        }

        if ((int) $schedule['total_minutes'] !== SalesDailyPlan::REQUIRED_WORK_MINUTES) {
            throw new InvalidArgumentException('Daily Plan hanya dapat dikirim jika total efektif tepat 420 menit.');
        }
    }

    public function assertDraftAllowed(array $schedule): void
    {
        if (($schedule['is_weekend'] ?? false) === true) {
            return;
        }

        if ((int) $schedule['total_minutes'] > SalesDailyPlan::REQUIRED_WORK_MINUTES) {
            throw new InvalidArgumentException('Total efektif Daily Plan tidak boleh melebihi 420 menit.');
        }
    }

    /**
     * @return array<int, array{id:int, start_time:string, end_time:string, duration_minutes:int}>
     */
    private function calculateWorkday(CarbonImmutable $date, Collection $agendas): array
    {
        $friday = $date->isFriday();
        $cursor = $this->atMinute($date, $friday ? self::FRIDAY_START_MINUTE : self::MONDAY_THURSDAY_START_MINUTE);
        $breakStart = $this->atMinute($date, $friday ? self::FRIDAY_BREAK_START_MINUTE : self::MONDAY_THURSDAY_BREAK_START_MINUTE);
        $breakEnd = $this->atMinute($date, $friday ? self::FRIDAY_BREAK_END_MINUTE : self::MONDAY_THURSDAY_BREAK_END_MINUTE);
        $workEnd = $this->atMinute($date, self::END_MINUTE);
        $schedule = [];

        foreach ($agendas as $agenda) {
            $duration = $this->duration($agenda);
            if ($cursor->gte($breakStart) && $cursor->lt($breakEnd)) {
                $cursor = $breakEnd;
            }
            if ($cursor->lt($breakStart) && $cursor->addMinutes($duration)->gt($breakStart)) {
                throw new InvalidArgumentException('Durasi Agenda tidak boleh masuk jam istirahat.');
            }

            $start = $cursor;
            $end = $start->addMinutes($duration);
            if ($end->gt($workEnd)) {
                throw new InvalidArgumentException('Jadwal Daily Plan tidak boleh melewati pukul 17:00.');
            }

            $schedule[] = $this->scheduled($agenda, $start, $end, $duration);
            $cursor = $end;
        }

        return $schedule;
    }

    /**
     * @return array<int, array{id:int, start_time:string, end_time:string, duration_minutes:int}>
     */
    private function calculateWeekend(CarbonImmutable $date, Collection $agendas): array
    {
        $cursor = $this->atMinute($date, self::MONDAY_THURSDAY_START_MINUTE);
        $dayEnd = $date->addDay()->startOfDay();
        $schedule = [];

        foreach ($agendas as $agenda) {
            $duration = $this->duration($agenda);
            $start = filled($agenda->start_time)
                ? $this->parseTime($date, (string) $agenda->start_time)
                : $cursor;
            $end = $start->addMinutes($duration);

            if ($start->lt($cursor)) {
                throw new InvalidArgumentException('Agenda akhir pekan tidak boleh bertumpuk atau mundur dari agenda sebelumnya.');
            }
            if ($end->gt($dayEnd)) {
                throw new InvalidArgumentException('Agenda akhir pekan harus selesai pada tanggal yang sama.');
            }

            $schedule[] = $this->scheduled($agenda, $start, $end, $duration);
            $cursor = $end;
        }

        return $schedule;
    }

    private function duration(ContentItem $agenda): int
    {
        $duration = (int) $agenda->duration_minutes;
        if ($duration < 1) {
            throw new InvalidArgumentException('Durasi setiap Agenda Daily Plan harus lebih dari 0 menit.');
        }

        return $duration;
    }

    /**
     * @return array{id:int, start_time:string, end_time:string, duration_minutes:int}
     */
    private function scheduled(ContentItem $agenda, CarbonImmutable $start, CarbonImmutable $end, int $duration): array
    {
        return [
            'id' => (int) $agenda->id,
            'start_time' => $start->format('H:i:s'),
            'end_time' => $end->format('H:i:s'),
            'duration_minutes' => $duration,
        ];
    }

    private function atMinute(CarbonImmutable $date, int $minute): CarbonImmutable
    {
        return $date->startOfDay()->addMinutes($minute);
    }

    private function parseTime(CarbonImmutable $date, string $time): CarbonImmutable
    {
        $normalized = strlen($time) === 5 ? $time.':00' : $time;

        try {
            return CarbonImmutable::createFromFormat('Y-m-d H:i:s', $date->toDateString().' '.$normalized, config('app.timezone'));
        } catch (\Throwable) {
            throw new InvalidArgumentException('Jam mulai Agenda akhir pekan tidak valid.');
        }
    }
}
