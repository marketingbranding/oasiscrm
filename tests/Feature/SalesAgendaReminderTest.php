<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\SalesAgendaReminderService;
use App\Services\SalesDailyPlanService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesAgendaReminderTest extends TestCase
{
    use RefreshDatabase;

    public function test_four_in_app_reminders_are_sent_once_at_their_schedule_points(): void
    {
        $sales = $this->sales();
        $date = CarbonImmutable::parse('2026-10-26', config('app.timezone'));
        $planService = app(SalesDailyPlanService::class);
        $planService->saveDraft($sales, $date, [
            ['activity' => 'Agenda seharian', 'duration_minutes' => 420],
        ]);
        $planService->submit($sales, $date);
        $reminders = app(SalesAgendaReminderService::class);

        $this->assertSame(1, $reminders->sendAt($date->setTime(8, 45)));
        $this->assertSame(0, $reminders->sendAt($date->setTime(8, 45)));
        $this->assertSame(1, $reminders->sendAt($date->setTime(9, 0)));
        $this->assertSame(1, $reminders->sendAt($date->setTime(16, 45)));
        $this->assertSame(1, $reminders->sendAt($date->setTime(17, 15)));

        $this->assertSame(4, UserNotification::query()->count());
        $this->assertEqualsCanonicalizing([
            'sales_agenda_reminder.upcoming',
            'sales_agenda_reminder.starting',
            'sales_agenda_reminder.ending_soon',
            'sales_agenda_reminder.overdue',
        ], UserNotification::query()->pluck('type')->all());
    }

    private function sales(): User
    {
        $role = Role::firstOrCreate(['slug' => 'sales'], ['name' => 'Sales']);
        $branch = Branch::firstOrCreate(['code' => 'REM'], ['name' => 'Reminder', 'is_active' => true]);

        return User::factory()->create([
            'name' => 'Solo Sales',
            'role_id' => $role->id,
            'branch_id' => $branch->id,
            'password_changed_at' => now(),
        ]);
    }
}
