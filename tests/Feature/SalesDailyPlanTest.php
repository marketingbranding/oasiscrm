<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\SalesCoordinatorSales;
use App\Models\SalesDailyPlan;
use App\Models\SalesDailyPlanItem;
use App\Models\User;
use App\Services\SalesDailyPlanService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\TestCase;

class SalesDailyPlanTest extends TestCase
{
    use RefreshDatabase;

    public function test_duration_first_items_are_allocated_around_the_lunch_break(): void
    {
        [, , $sales] = $this->context();
        $plan = app(SalesDailyPlanService::class)->saveDraft($sales, CarbonImmutable::parse('2026-10-26'), [
            ['activity' => 'Prospek pagi', 'detail' => 'Hubungi daftar baru', 'duration_minutes' => 180],
            ['activity' => 'Follow-up', 'detail' => 'Kirim materi', 'duration_minutes' => 60],
            ['activity' => 'Administrasi', 'detail' => 'Perbarui catatan', 'duration_minutes' => 180],
        ]);

        $items = $plan->items()->orderBy('position')->get();
        $this->assertSame(['09:00', '13:00', '14:00'], $items->map(fn ($item) => substr($item->start_time, 0, 5))->all());
        $this->assertSame(['12:00', '14:00', '17:00'], $items->map(fn ($item) => substr($item->end_time, 0, 5))->all());
        $this->assertSame(420, $plan->totalDurationMinutes());
    }

    public function test_sales_can_save_a_short_draft_but_submit_requires_exactly_420_minutes(): void
    {
        [, , $sales] = $this->context();
        $service = app(SalesDailyPlanService::class);
        $service->saveDraft($sales, CarbonImmutable::parse('2026-10-26'), [
            ['activity' => 'Prospek', 'duration_minutes' => 60],
        ]);

        $this->expectException(ValidationException::class);
        $service->submit($sales, CarbonImmutable::parse('2026-10-26'));
    }

    public function test_submitted_plan_cannot_be_changed_by_sales_but_sales_can_complete_an_item(): void
    {
        [, , $sales] = $this->context();
        $service = app(SalesDailyPlanService::class);
        $service->saveDraft($sales, CarbonImmutable::parse('2026-10-26'), [
            ['activity' => 'Prospek', 'duration_minutes' => 420],
        ]);
        $plan = $service->submit($sales, CarbonImmutable::parse('2026-10-26'));
        $item = $plan->items()->firstOrFail();

        $service->completeItem($sales, $item, 'Hasil follow-up');
        $this->assertSame('Hasil follow-up', $item->fresh()->activity_result);
        $this->assertSame('completed', $item->fresh()->status);

        $this->expectException(AccessDeniedHttpException::class);
        $service->saveDraft($sales, CarbonImmutable::parse('2026-10-26'), [
            ['activity' => 'Tidak boleh', 'duration_minutes' => 420],
        ]);
    }

    public function test_coordinator_can_reorder_a_submitted_plan_without_changing_durations(): void
    {
        [$branch, , $sales] = $this->context();
        $coordinator = $this->user('sales_coordinator', $branch, 'Koordinator');
        SalesCoordinatorSales::create([
            'coordinator_user_id' => $coordinator->id,
            'sales_user_id' => $sales->id,
            'is_active' => true,
        ]);
        $service = app(SalesDailyPlanService::class);
        $service->saveDraft($sales, CarbonImmutable::parse('2026-10-26'), [
            ['activity' => 'Pertama', 'duration_minutes' => 180],
            ['activity' => 'Kedua', 'duration_minutes' => 240],
        ]);
        $plan = $service->submit($sales, CarbonImmutable::parse('2026-10-26'));
        $items = $plan->items()->orderBy('position')->get();

        $revised = $service->revise($coordinator, $plan, [
            ['id' => $items[1]->id, 'activity' => 'Kedua', 'duration_minutes' => 240],
            ['id' => $items[0]->id, 'activity' => 'Pertama', 'duration_minutes' => 180],
        ]);

        $revisedItems = $revised->items()->orderBy('position')->get();
        $this->assertSame(['Kedua', 'Pertama'], $revisedItems->pluck('activity')->all());
        $this->assertSame([240, 180], $revisedItems->pluck('duration_minutes')->all());
        $this->assertSame(['09:00', '14:00'], $revisedItems->map(fn ($item) => substr($item->start_time, 0, 5))->all());
        $this->assertDatabaseHas('sales_daily_plan_revisions', [
            'sales_daily_plan_id' => $plan->id,
            'actor_user_id' => $coordinator->id,
            'action' => 'revised',
        ]);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $sales->id,
            'type' => 'sales_daily_plan_revised',
        ]);
    }

    public function test_daily_plan_rejects_weekends_and_more_than_420_minutes(): void
    {
        [, , $sales] = $this->context();
        $service = app(SalesDailyPlanService::class);

        try {
            $service->saveDraft($sales, CarbonImmutable::parse('2026-10-25'), [
                ['activity' => 'Minggu', 'duration_minutes' => 60],
            ]);
            $this->fail('Weekend plans must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('work_date', $exception->errors());
        }

        $this->expectException(ValidationException::class);
        $service->saveDraft($sales, CarbonImmutable::parse('2026-10-26'), [
            ['activity' => 'Terlalu lama', 'duration_minutes' => 421],
        ]);
    }

    public function test_sales_can_reach_the_daily_plan_store_route(): void
    {
        [, , $sales] = $this->context();
        $sales->forceFill(['email_verified_at' => now()])->save();

        $response = $this->actingAs($sales)->post(route('sales-daily-plans.store'), [
            'work_date' => '2026-10-26',
            'activities' => [
                ['activity' => 'Prospek', 'duration_minutes' => 420],
            ],
        ]);

        $response->assertRedirect();
        $this->assertTrue(SalesDailyPlan::query()
            ->where('sales_user_id', $sales->id)
            ->whereDate('work_date', '2026-10-26')
            ->where('status', 'draft')
            ->exists());
    }

    public function test_daily_plan_item_state_tracks_upcoming_ongoing_completed_and_overdue_items(): void
    {
        [, , $sales] = $this->context();
        $plan = SalesDailyPlan::create([
            'sales_user_id' => $sales->id,
            'work_date' => '2026-10-26',
            'status' => 'submitted',
        ]);
        $item = SalesDailyPlanItem::create([
            'sales_daily_plan_id' => $plan->id,
            'position' => 0,
            'activity' => 'Prospek',
            'duration_minutes' => 60,
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
            'status' => 'planned',
        ]);
        $service = app(SalesDailyPlanService::class);

        $this->assertSame('upcoming', $service->itemState($item, CarbonImmutable::parse('2026-10-26 08:59')));
        $this->assertSame('ongoing', $service->itemState($item, CarbonImmutable::parse('2026-10-26 09:30')));
        $this->assertSame('overdue', $service->itemState($item, CarbonImmutable::parse('2026-10-26 10:01')));

        $item->update(['status' => 'completed']);

        $this->assertSame('completed', $service->itemState($item->fresh(), CarbonImmutable::parse('2026-10-26 09:30')));
    }

    public function test_submitted_daily_plan_paper_groups_items_by_state(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-26 09:30:00', config('app.timezone')));
        try {
            [, , $sales] = $this->context();
            $sales->forceFill(['email_verified_at' => now()])->save();
            $plan = SalesDailyPlan::create([
                'sales_user_id' => $sales->id,
                'work_date' => '2026-10-26',
                'status' => 'submitted',
            ]);
            SalesDailyPlanItem::create([
                'sales_daily_plan_id' => $plan->id,
                'position' => 0,
                'activity' => 'Prospek',
                'duration_minutes' => 60,
                'start_time' => '09:00:00',
                'end_time' => '10:00:00',
                'status' => 'planned',
            ]);
            SalesDailyPlanItem::create([
                'sales_daily_plan_id' => $plan->id,
                'position' => 1,
                'activity' => 'Follow-up',
                'duration_minutes' => 60,
                'start_time' => '10:00:00',
                'end_time' => '11:00:00',
                'status' => 'planned',
            ]);
            SalesDailyPlanItem::create([
                'sales_daily_plan_id' => $plan->id,
                'position' => 2,
                'activity' => 'Administrasi',
                'duration_minutes' => 60,
                'start_time' => '08:00:00',
                'end_time' => '09:00:00',
                'status' => 'completed',
            ]);

            $this->actingAs($sales)->get(route('sales-agendas.index', ['work_date' => '2026-10-26']))
                ->assertOk()
                ->assertSee('Agenda yang sedang berjalan')
                ->assertSee('Akan datang')
                ->assertSee('Selesai');
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    private function context(): array
    {
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);
        $project = LeadMaster::create([
            'branch_id' => $branch->id,
            'project_name' => 'Solo Project',
            'is_active' => true,
        ]);
        $sales = $this->user('sales', $branch, 'Solo Sales');
        $sales->assignedProjects()->attach($project, ['is_primary' => true]);

        return [$branch, $project, $sales];
    }

    private function user(string $roleSlug, ?Branch $branch = null, ?string $name = null): User
    {
        $role = Role::firstOrCreate([
            'slug' => $roleSlug,
        ], [
            'name' => ucfirst($roleSlug),
            'is_superadmin' => $roleSlug === 'superadmin',
        ]);

        return User::factory()->create([
            'name' => $name ?? ucfirst($roleSlug),
            'role_id' => $role->id,
            'branch_id' => $branch?->id,
            'password_changed_at' => now(),
        ]);
    }
}
