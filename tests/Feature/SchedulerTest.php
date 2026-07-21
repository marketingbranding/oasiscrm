<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class SchedulerTest extends TestCase
{
    public function test_presence_cleanup_is_scheduled_hourly_without_overlap(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'oasis:presence-cleanup'));

        $this->assertNotNull($event);
        $this->assertSame('0 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_notification_cleanup_is_scheduled_weekly_without_overlap(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'oasis:notifications-cleanup'));

        $this->assertNotNull($event);
        $this->assertSame('0 0 * * 0', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }
}
