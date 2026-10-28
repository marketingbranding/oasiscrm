<?php

namespace Tests\Feature;

use App\Enums\SalesLeadStatus;
use App\Models\ContentItem;
use App\Models\SalesCoordinatorSales;
use App\Models\SalesDailyPlan;
use App\Models\SalesLead;
use App\Models\SalesLeadTarget;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\SalesCoordinatorWeekDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesCoordinatorWeekDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_a_complete_weekly_coordinator_sales_workspace_idempotently(): void
    {
        Carbon::setTestNow('2026-10-28 10:00:00');

        $this->seed(SalesCoordinatorWeekDemoSeeder::class);

        $coordinator = User::query()->where('email', 'demo.koordinator@oasis.local')->firstOrFail();
        $salesIds = User::query()
            ->where('email', 'like', 'demo.sales.%@oasis.local')
            ->pluck('id');

        $this->assertSame('sales_coordinator', $coordinator->role->slug);
        $this->assertCount(4, $salesIds);
        $this->assertSame(4, SalesCoordinatorSales::query()
            ->where('coordinator_user_id', $coordinator->id)
            ->current()
            ->count());
        $this->assertSame(28, SalesLead::query()->where('customer_name', 'like', '[SC-DEMO]%')->count());
        $this->assertSame(0, SalesDailyPlan::query()->whereIn('sales_user_id', $salesIds)->count());
        $this->assertSame(28, ContentItem::query()
            ->where('title', 'like', '[SC-DEMO]%')
            ->where('agenda_type', ContentItem::SALES_AGENDA_TYPE)
            ->count());
        $this->assertSame(4, SalesLeadTarget::query()->whereIn('sales_user_id', $salesIds)->count());

        $statuses = SalesLead::query()
            ->where('customer_name', 'like', '[SC-DEMO]%')
            ->pluck('current_status')
            ->map(fn (SalesLeadStatus|string $status): string => $status instanceof SalesLeadStatus ? $status->value : $status)
            ->unique()
            ->values()
            ->all();

        $this->assertEqualsCanonicalizing(
            array_map(fn (SalesLeadStatus $status): string => $status->value, SalesLeadStatus::PRIMARY_PRECEDENCE),
            $statuses,
        );
        $this->assertGreaterThan(0, SalesLead::query()->where('customer_name', 'like', '[SC-DEMO]%')->whereNotNull('freelance_converted_at')->count());

        $this->seed(SalesCoordinatorWeekDemoSeeder::class);

        $this->assertSame(4, User::query()->where('email', 'like', 'demo.sales.%@oasis.local')->count());
        $this->assertSame(28, SalesLead::query()->where('customer_name', 'like', '[SC-DEMO]%')->count());
        $this->assertSame(28, ContentItem::query()->where('title', 'like', '[SC-DEMO]%')->count());
    }
}
