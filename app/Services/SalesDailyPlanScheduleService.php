<?php

namespace App\Services;

use App\Models\SalesDailyPlan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class SalesDailyPlanScheduleService
{
    public const START_MINUTE = 9 * 60;

    public const LUNCH_START_MINUTE = 12 * 60;

    public const LUNCH_END_MINUTE = 13 * 60;

    public const END_MINUTE = 17 * 60;

    /**
     * @return array{items: array<int, array{id:int, start_time:string, end_time:string, duration_minutes:int}>, total_minutes:int, remaining_minutes:int, end_time:?string}
     */
    public function calculate(SalesDailyPlan $plan, ?Collection $agendas = null): array
    {
        if (! $plan->plan_date || ! $plan->plan_date->isWeekday()) {
            throw new InvalidArgumentException('Daily Plan hanya dapat dibuat pada hari kerja Senin sampai Jumat.');
        }

        $items = ($agendas ?? $plan->agendas()->get())->sortBy([
            ['sort_order', 'asc'],
            ['id', 'asc'],
        ])->values();
        $total = 0;
        $scheduled = [];

        foreach ($items as $agenda) {
            $duration = (int) $agenda->duration_minutes;
            if ($duration < 1) {
                throw new InvalidArgumentException('Durasi setiap Agenda Daily Plan harus lebih dari 0 menit.');
            }

            $startOffset = $total;
            $endOffset = $total + $duration;
            if ($endOffset > SalesDailyPlan::REQUIRED_WORK_MINUTES) {
                throw new InvalidArgumentException('Jadwal Daily Plan tidak boleh melewati pukul 17:00.');
            }

            $scheduled[] = [
                'id' => (int) $agenda->id,
                'start_time' => $this->wallTime($startOffset, true),
                'end_time' => $this->wallTime($endOffset, false),
                'duration_minutes' => $duration,
            ];
            $total = $endOffset;
        }

        return [
            'items' => $scheduled,
            'total_minutes' => $total,
            'remaining_minutes' => SalesDailyPlan::REQUIRED_WORK_MINUTES - $total,
            'end_time' => $total > 0 ? $this->wallTime($total, false) : null,
        ];
    }

    public function assertSubmitAllowed(array $schedule): void
    {
        if ((int) $schedule['total_minutes'] !== SalesDailyPlan::REQUIRED_WORK_MINUTES) {
            throw new InvalidArgumentException('Daily Plan hanya dapat dikirim jika total efektif tepat 420 menit.');
        }
    }

    public function assertDraftAllowed(array $schedule): void
    {
        if ((int) $schedule['total_minutes'] > SalesDailyPlan::REQUIRED_WORK_MINUTES) {
            throw new InvalidArgumentException('Total efektif Daily Plan tidak boleh melebihi 420 menit.');
        }
    }

    private function wallTime(int $effectiveOffset, bool $isStart): string
    {
        $minutes = self::START_MINUTE + $effectiveOffset;
        if ($effectiveOffset > (self::LUNCH_START_MINUTE - self::START_MINUTE)
            || ($isStart && $effectiveOffset === (self::LUNCH_START_MINUTE - self::START_MINUTE))) {
            $minutes += self::LUNCH_END_MINUTE - self::LUNCH_START_MINUTE;
        }

        return CarbonImmutable::createFromTime(0, 0)->addMinutes($minutes)->format('H:i:s');
    }
}
