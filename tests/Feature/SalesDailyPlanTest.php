<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Role;
use App\Models\SalesDailyPlan;
use App\Models\SalesDailyPlanItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SalesDailyPlanTest extends TestCase
{
    use RefreshDatabase;

    public function test_historical_daily_plan_models_remain_available_for_existing_records(): void
    {
        $branch = Branch::create(['name' => 'Histori', 'code' => 'HST', 'is_active' => true]);
        $sales = User::factory()->create([
            'name' => 'Sales Histori',
            'role_id' => Role::query()->where('slug', 'sales')->value('id'),
            'branch_id' => $branch->id,
            'password_changed_at' => now(),
        ]);
        $plan = SalesDailyPlan::create([
            'branch_id' => $branch->id,
            'sales_user_id' => $sales->id,
            'work_date' => '2026-10-26',
            'status' => 'submitted',
        ]);
        $item = SalesDailyPlanItem::create([
            'sales_daily_plan_id' => $plan->id,
            'position' => 0,
            'activity' => 'Agenda historis',
            'duration_minutes' => 60,
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
        ]);

        $this->assertSame('Agenda historis', $plan->fresh()->items()->firstOrFail()->activity);
        $this->assertSame($plan->id, $item->fresh()->plan->id);
    }

    public function test_daily_plan_endpoints_are_no_longer_registered(): void
    {
        $this->assertFalse(Route::has('sales-daily-plans.index'));
        $this->assertFalse(Route::has('sales-daily-plans.store'));
        $this->assertFalse(Route::has('sales-daily-plan-items.evidence.store'));
    }
}
