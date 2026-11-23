<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Models\Holiday;
use App\Models\Role;
use App\Models\SalesAgendaDaySubmission;
use App\Models\User;
use App\Services\AppSettingResolver;
use App\Services\SalesAgendaSubmissionAttentionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SalesAgendaSubmissionAttentionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_wp23_missing_submission_requires_cutoff_and_uses_receipt_table(): void
    {
        $branch = $this->branch();
        $sales = $this->sales($branch);
        $service = app(SalesAgendaSubmissionAttentionService::class);
        $now = now()->setDate(2026, 11, 23)->setTime(9, 59);

        $this->assertCount(0, $service->missingFor($sales, $now->toImmutable()));

        $now = $now->setTime(10, 0);
        $this->assertCount(1, $service->missingFor($sales, $now->toImmutable()));

        SalesAgendaDaySubmission::create([
            'owner_user_id' => $sales->id,
            'scheduled_date' => $now->toDateString(),
            'target_minutes' => 480,
            'normal_minutes_at_submission' => 480,
            'submitted_at' => $now,
        ]);

        $this->assertCount(0, $service->missingFor($sales, $now->toImmutable()));
    }

    public function test_br08_weekend_and_br09_branch_holiday_do_not_mark_missing_submission(): void
    {
        $branch = $this->branch();
        $sales = $this->sales($branch);
        $service = app(SalesAgendaSubmissionAttentionService::class);

        $this->assertCount(0, $service->missingFor($sales, now()->setDate(2026, 11, 21)->setTime(12, 0)->toImmutable()));

        Holiday::create(['date' => '2026-11-23', 'name' => 'Libur cabang', 'branch_id' => $branch->id]);
        $this->assertCount(0, $service->missingFor($sales, now()->setDate(2026, 11, 23)->setTime(12, 0)->toImmutable()));
    }

    public function test_wp23_cutoff_default_and_changed_value_are_settings_backed(): void
    {
        $branch = $this->branch();
        $sales = $this->sales($branch);
        $service = app(SalesAgendaSubmissionAttentionService::class);
        $resolver = app(AppSettingResolver::class);
        $now = now()->setDate(2026, 11, 23)->setTime(10, 0)->toImmutable();

        $this->assertTrue($service->isPastSubmissionCutoff($now, $branch->id));

        DB::table('app_settings')->insert([
            'key' => 'sales_monitoring.daily_plan_cutoff_time',
            'value' => json_encode('11:00', JSON_THROW_ON_ERROR),
            'group' => 'sales_monitoring',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $resolver->forget('sales_monitoring.daily_plan_cutoff_time');

        $this->assertFalse($service->isPastSubmissionCutoff($now, $branch->id));
        $this->assertCount(0, $service->missingFor($sales, $now));
    }

    private function branch(): Branch
    {
        return Branch::create(['name' => 'Solo', 'code' => 'SO', 'is_active' => true]);
    }

    private function sales(Branch $branch): User
    {
        $sales = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'sales')->value('id'),
            'branch_id' => $branch->id,
            'password_changed_at' => now(),
        ]);
        $sales->branches()->syncWithoutDetaching([$branch->id => ['can_view' => true, 'can_edit' => true, 'can_sync' => false]]);

        return $sales;
    }
}
