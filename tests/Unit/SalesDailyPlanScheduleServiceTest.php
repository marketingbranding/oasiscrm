<?php

namespace Tests\Unit;

use App\Models\ContentItem;
use App\Models\SalesDailyPlan;
use App\Services\SalesDailyPlanScheduleService;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Tests\TestCase;

class SalesDailyPlanScheduleServiceTest extends TestCase
{
    public function test_it_starts_at_nine_and_skips_lunch_without_splitting_an_agenda(): void
    {
        $result = app(SalesDailyPlanScheduleService::class)->calculate($this->plan(), new Collection([
            $this->agenda(1, 90, 1),
        ]));

        $this->assertSame('09:00:00', $result['items'][0]['start_time']);
        $this->assertSame('10:30:00', $result['items'][0]['end_time']);

        $result = app(SalesDailyPlanScheduleService::class)->calculate($this->plan(), new Collection([
            $this->agenda(1, 150, 1),
            $this->agenda(2, 90, 2),
        ]));

        $this->assertSame('09:00:00', $result['items'][0]['start_time']);
        $this->assertSame('11:30:00', $result['items'][0]['end_time']);
        $this->assertSame('11:30:00', $result['items'][1]['start_time']);
        $this->assertSame('14:00:00', $result['items'][1]['end_time']);
    }

    public function test_full_workday_ends_at_five_and_submit_requires_exactly_four_hundred_twenty(): void
    {
        $service = app(SalesDailyPlanScheduleService::class);
        $result = $service->calculate($this->plan(), new Collection([$this->agenda(1, 420, 1)]));

        $this->assertSame('17:00:00', $result['items'][0]['end_time']);
        $this->assertSame(0, $result['remaining_minutes']);
        $service->assertSubmitAllowed($result);

        $short = $service->calculate($this->plan(), new Collection([$this->agenda(1, 419, 1)]));
        $this->expectException(InvalidArgumentException::class);
        $service->assertSubmitAllowed($short);
    }

    public function test_overflow_and_weekends_are_rejected(): void
    {
        $service = app(SalesDailyPlanScheduleService::class);
        $this->expectException(InvalidArgumentException::class);
        $service->calculate($this->plan(), new Collection([$this->agenda(1, 421, 1)]));
    }

    private function plan(): SalesDailyPlan
    {
        $plan = new SalesDailyPlan(['plan_date' => '2026-10-20']);
        $plan->setRelation('agendas', collect());

        return $plan;
    }

    private function agenda(int $id, int $duration, int $order): ContentItem
    {
        return (new ContentItem(['duration_minutes' => $duration, 'sort_order' => $order]))->forceFill(['id' => $id]);
    }
}
