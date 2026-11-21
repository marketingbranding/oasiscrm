<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Holiday;
use App\Models\Role;
use App\Models\User;
use App\Services\SalesAgendaReceiptService;
use App\Services\SalesWorkCalendarService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesWorkCalendarHolidayTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $roleSlug): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $roleSlug)->value('id'),
            'password_changed_at' => now(),
            'account_status' => 'active',
        ]);
    }

    public function test_br08_national_holiday_is_free_for_every_branch(): void
    {
        Holiday::create([
            'date' => '2026-11-23',
            'name' => 'Hari libur nasional',
        ]);

        $schedule = app(SalesWorkCalendarService::class)->schedule(CarbonImmutable::parse('2026-11-23'), 99);

        $this->assertFalse($schedule['is_weekend']);
        $this->assertTrue($schedule['is_free_day']);
        $this->assertFalse($schedule['requires_target']);
        $this->assertSame(0, $schedule['target_minutes']);
    }

    public function test_br09_branch_holiday_applies_only_to_matching_branch(): void
    {
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SO', 'is_active' => true]);
        Holiday::create([
            'date' => '2026-11-24',
            'name' => 'Libur khusus cabang Solo',
            'branch_id' => $branch->id,
        ]);

        $calendar = app(SalesWorkCalendarService::class);
        $holidaySchedule = $calendar->schedule(CarbonImmutable::parse('2026-11-24'), $branch->id);
        $workingSchedule = $calendar->schedule(CarbonImmutable::parse('2026-11-24'), $branch->id + 1);

        $this->assertTrue($holidaySchedule['is_free_day']);
        $this->assertFalse($holidaySchedule['requires_target']);
        $this->assertFalse($workingSchedule['is_free_day']);
        $this->assertTrue($workingSchedule['requires_target']);
    }

    public function test_br09_superadmin_can_manage_national_and_branch_holidays(): void
    {
        $superadmin = $this->user('superadmin');
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SO', 'is_active' => true]);

        $this->actingAs($superadmin)
            ->get(route('admin.holidays.index'))
            ->assertOk()
            ->assertSee('Hari Libur');

        $this->actingAs($superadmin)
            ->post(route('admin.holidays.store'), [
                'date' => '2026-11-25',
                'name' => 'Cuti bersama',
            ])
            ->assertRedirect(route('admin.holidays.index'));

        $this->actingAs($superadmin)
            ->post(route('admin.holidays.store'), [
                'date' => '2026-11-26',
                'name' => 'Libur cabang Solo',
                'branch_id' => $branch->id,
            ])
            ->assertRedirect(route('admin.holidays.index'));

        $this->assertTrue(Holiday::query()->whereDate('date', '2026-11-25')->whereNull('branch_id')->exists());
        $this->assertTrue(Holiday::query()->whereDate('date', '2026-11-26')->where('branch_id', $branch->id)->exists());
    }

    public function test_br09_unauthorized_user_cannot_manage_holiday_settings_by_direct_url(): void
    {
        $staff = $this->user('staff');

        $this->actingAs($staff)
            ->get(route('admin.holidays.index'))
            ->assertForbidden();
    }

    public function test_br08_holiday_receipt_ui_uses_holiday_label_not_weekend_label(): void
    {
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SO', 'is_active' => true]);
        $sales = $this->user('sales');
        $sales->update(['branch_id' => $branch->id]);
        Holiday::create(['date' => '2026-11-27', 'name' => 'Libur nasional']);

        $this->actingAs($sales)
            ->get(route('sales-agendas.index', ['work_date' => '2026-11-27', 'tour' => 1]))
            ->assertOk()
            ->assertSee('HARI LIBUR')
            ->assertDontSee('AKHIR PEKAN');
    }

    public function test_br08_holiday_receipt_summary_has_no_target_but_keeps_agenda_count(): void
    {
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SO', 'is_active' => true]);
        $sales = $this->user('sales');
        $sales->update(['branch_id' => $branch->id]);
        Holiday::create(['date' => '2026-11-27', 'name' => 'Libur nasional']);

        $summary = app(SalesAgendaReceiptService::class)
            ->summarize($sales, CarbonImmutable::parse('2026-11-27'));

        $this->assertTrue($summary['is_free_day']);
        $this->assertFalse($summary['requires_target']);
        $this->assertSame(0, $summary['target_minutes']);
    }
}
