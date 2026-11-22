<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\SalesCoordinatorSales;
use App\Models\SalesLead;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\AttentionSummaryNotificationService;
use App\Services\ModuleMaintenanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AttentionSummaryNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_wp23_summary_is_idempotent_per_coordinator_and_date(): void
    {
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SO', 'is_active' => true]);
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Proyek Solo', 'is_active' => true]);
        $coordinator = $this->user('sales_coordinator', $branch);
        $sales = $this->user('sales', $branch);
        $sales->assignedProjects()->attach($project, ['is_primary' => true, 'is_active' => true]);
        SalesCoordinatorSales::create(['coordinator_user_id' => $coordinator->id, 'sales_user_id' => $sales->id, 'is_active' => true]);
        SalesLead::create([
            'branch_id' => $branch->id,
            'project_id' => $project->id,
            'sales_user_id' => $sales->id,
            'lead_date' => '2026-11-20',
            'customer_name' => 'Lead lama',
            'created_by' => $sales->id,
        ]);

        $date = now()->setDate(2026, 11, 23)->setTime(11, 0)->toImmutable();
        $service = app(AttentionSummaryNotificationService::class);

        $this->assertSame(1, $service->sendForDate($date));
        $this->assertSame(0, $service->sendForDate($date));
        $this->assertSame(1, UserNotification::query()->where('type', AttentionSummaryNotificationService::TYPE)->count());
    }

    public function test_wp23_summary_waits_for_cutoff(): void
    {
        $date = now()->setDate(2026, 11, 23)->setTime(9, 59)->toImmutable();

        $this->assertSame(0, app(AttentionSummaryNotificationService::class)->sendForDate($date));
    }

    public function test_wp23_submission_check_batches_sales_query(): void
    {
        $branch = Branch::create(['name' => 'Batch', 'code' => 'BA', 'is_active' => true]);
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Proyek Batch', 'is_active' => true]);
        $coordinator = $this->user('sales_coordinator', $branch);
        $sales = collect(range(1, 3))->map(function (int $index) use ($branch, $project, $coordinator): User {
            $user = $this->user('sales', $branch);
            $user->assignedProjects()->attach($project, ['is_primary' => true, 'is_active' => true]);
            SalesCoordinatorSales::create(['coordinator_user_id' => $coordinator->id, 'sales_user_id' => $user->id, 'is_active' => true]);

            return $user;
        });
        $queryCount = 0;
        DB::listen(function ($query) use (&$queryCount): void {
            if (str_contains(strtolower($query->sql), 'sales_agenda_day_submissions')) {
                $queryCount++;
            }
        });

        app(AttentionSummaryNotificationService::class)->sendForDate(now()->setDate(2026, 11, 23)->setTime(11, 0)->toImmutable());

        $this->assertSame(1, $queryCount);
    }

    public function test_wp23_summary_skips_during_module_maintenance(): void
    {
        $date = now()->setDate(2026, 11, 23)->setTime(11, 0)->toImmutable();
        $branch = Branch::create(['name' => 'Maintenance', 'code' => 'MT', 'is_active' => true]);
        $this->user('sales_coordinator', $branch);
        $superadmin = $this->user('superadmin', $branch);
        app(ModuleMaintenanceService::class)->enable('sales_pocketbook', $superadmin, []);

        $this->assertSame(0, app(AttentionSummaryNotificationService::class)->sendForDate($date));
    }

    public function test_wp23_summary_skips_coordinator_without_team_permission(): void
    {
        $branch = Branch::create(['name' => 'No Access', 'code' => 'NA', 'is_active' => true]);
        $coordinator = $this->user('sales_coordinator', $branch);
        $coordinator->role->permissions()->detach();
        $date = now()->setDate(2026, 11, 23)->setTime(11, 0)->toImmutable();

        $this->assertSame(0, app(AttentionSummaryNotificationService::class)->sendForDate($date));
    }

    public function test_br08_free_day_sends_no_summary(): void
    {
        $date = now()->setDate(2026, 11, 21)->setTime(11, 0)->toImmutable();

        $this->assertSame(0, app(AttentionSummaryNotificationService::class)->sendForDate($date));
        $this->assertDatabaseCount('user_notifications', 0);
    }

    private function user(string $role, Branch $branch): User
    {
        $user = User::factory()->create([
            'role_id' => Role::query()->where('slug', $role)->value('id'),
            'branch_id' => $branch->id,
            'password_changed_at' => now(),
        ]);
        $user->branches()->syncWithoutDetaching([$branch->id => ['can_view' => true, 'can_edit' => false, 'can_sync' => false]]);

        return $user;
    }
}
