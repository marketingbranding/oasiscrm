<?php

namespace Tests\Unit;

use App\Models\AppSetting;
use App\Services\SalesWorkCalendarService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesWorkCalendarServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_br01_and_br02_use_receipt_working_hours_defaults(): void
    {
        $calendar = app(SalesWorkCalendarService::class);

        $thursday = $calendar->schedule(CarbonImmutable::parse('2026-11-19'));
        $friday = $calendar->schedule(CarbonImmutable::parse('2026-11-20'));

        $this->assertSame('08:00', $thursday['start_time']);
        $this->assertSame('17:00', $thursday['end_time']);
        $this->assertSame('12:00', $thursday['break_start']);
        $this->assertSame('13:00', $thursday['break_end']);
        $this->assertSame(480, $thursday['target_minutes']);
        $this->assertSame('07:30', $friday['start_time']);
        $this->assertSame('11:30', $friday['break_start']);
    }

    public function test_br01_and_br02_use_changed_registered_settings(): void
    {
        AppSetting::query()->insert([
            ['key' => 'sales_agenda.target_minutes', 'value' => json_encode(450), 'group' => 'sales_agenda'],
            ['key' => 'sales_agenda.weekday_start_time', 'value' => json_encode('08:30'), 'group' => 'sales_agenda'],
            ['key' => 'sales_agenda.weekday_break_start', 'value' => json_encode('12:30'), 'group' => 'sales_agenda'],
            ['key' => 'sales_agenda.weekday_break_end', 'value' => json_encode('13:30'), 'group' => 'sales_agenda'],
            ['key' => 'sales_agenda.friday_start_time', 'value' => json_encode('07:00'), 'group' => 'sales_agenda'],
        ]);

        $calendar = app(SalesWorkCalendarService::class);
        $calendar->forgetSettings();

        $thursday = $calendar->schedule(CarbonImmutable::parse('2026-11-19'));
        $friday = $calendar->schedule(CarbonImmutable::parse('2026-11-20'));

        $this->assertSame('08:30', $thursday['start_time']);
        $this->assertSame('12:30', $thursday['break_start']);
        $this->assertSame('13:30', $thursday['break_end']);
        $this->assertSame(450, $thursday['target_minutes']);
        $this->assertSame('07:00', $friday['start_time']);
    }

    public function test_br03_excludes_break_minutes_from_effective_duration(): void
    {
        $calendar = app(SalesWorkCalendarService::class);

        $this->assertSame(0, $calendar->effectiveMinutes(CarbonImmutable::parse('2026-11-19'), '12:00', '13:00'));
        $this->assertSame(30, $calendar->effectiveMinutes(CarbonImmutable::parse('2026-11-20'), '11:30', '13:30'));
    }

    public function test_br08_weekend_has_no_target(): void
    {
        $schedule = app(SalesWorkCalendarService::class)->schedule(CarbonImmutable::parse('2026-11-21'));

        $this->assertTrue($schedule['is_weekend']);
        $this->assertTrue($schedule['is_free_day']);
        $this->assertSame(0, $schedule['target_minutes']);
    }
}
