<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\LeadMaster;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SalesLead;
use App\Models\User;
use App\Services\AppSettingResolver;
use App\Services\OptimisticLockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SalesMonitoringReadOnlyMutationTest extends TestCase
{
    use RefreshDatabase;

    public function test_br51_flag_off_preserves_manager_lead_update_compatibility(): void
    {
        $branch = Branch::create(['name' => 'Cabang BR51 Mati', 'code' => 'BR5D', 'is_active' => true]);
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Proyek BR51 Mati', 'is_active' => true]);
        $sales = $this->user('sales', 'Sales BR51 Mati', $branch);
        $sales->assignedProjects()->syncWithoutDetaching([$project->id => [
            'is_primary' => true,
            'is_active' => true,
            'assignment_start_date' => today()->subDay()->toDateString(),
            'assignment_end_date' => today()->addDay()->toDateString(),
        ]]);
        $lead = SalesLead::create([
            'branch_id' => $branch->id,
            'project_id' => $project->id,
            'sales_user_id' => $sales->id,
            'lead_date' => today()->toDateString(),
            'customer_name' => 'Lead BR51 Mati',
            'phone' => '081234567892',
            'source' => 'Referensi',
            'platform' => 'Instagram',
            'campaign_name' => 'Canvassing',
            'created_by' => $sales->id,
        ]);
        $manager = $this->user('manager', 'Manager BR51 Mati', $branch);

        $this->actingAs($manager)
            ->put(route('sales-leads.update', $lead), [
                'branch_id' => $branch->id,
                'project_id' => $project->id,
                'sales_user_id' => $sales->id,
                'lead_date' => today()->toDateString(),
                'customer_name' => 'Lead BR51 Mati Diperbarui',
                'phone' => '081234567892',
                'source' => 'Referensi',
                'platform' => 'Instagram',
                'campaign_name' => 'Canvassing',
                'current_status' => 'no_response',
                'expected_updated_at' => app(OptimisticLockService::class)->token($lead),
            ])
            ->assertRedirect();

        $this->assertSame('Lead BR51 Mati Diperbarui', $lead->fresh()->customer_name);
    }

    public function test_br51_flag_on_blocks_stale_manage_own_completion_for_monitoring_roles(): void
    {
        $this->enableHierarchicalMonitoring();
        $branch = Branch::create(['name' => 'Cabang BR51 Selesai', 'code' => 'BR5C', 'is_active' => true]);
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Proyek BR51 Selesai', 'is_active' => true]);
        $sales = $this->user('sales', 'Sales BR51 Selesai', $branch);
        $sales->assignedProjects()->syncWithoutDetaching([$project->id => [
            'is_primary' => true,
            'is_active' => true,
            'assignment_start_date' => today()->subDay()->toDateString(),
            'assignment_end_date' => today()->addDay()->toDateString(),
        ]]);
        $lead = SalesLead::create([
            'branch_id' => $branch->id,
            'project_id' => $project->id,
            'sales_user_id' => $sales->id,
            'lead_date' => today()->toDateString(),
            'customer_name' => 'Lead BR51 Selesai',
            'phone' => '081234567891',
            'source' => 'Referensi',
            'platform' => 'Instagram',
            'campaign_name' => 'Canvassing',
            'created_by' => $sales->id,
        ]);
        $permission = Permission::query()->where('slug', 'sales_pocketbook.manage_own')->firstOrFail();

        foreach (['manager', 'ass_manager'] as $role) {
            $operator = $this->user($role, ucfirst($role).' BR51 Selesai', $branch);
            $operator->role->permissions()->syncWithoutDetaching([$permission->id]);
            $operator->refresh();

            $this->actingAs($operator)
                ->patch(route('sales-leads.complete', $lead), [
                    'expected_updated_at' => app(OptimisticLockService::class)->token($lead),
                ])
                ->assertForbidden();
            $this->assertNull($lead->fresh()->completed_at, $role);

            $this->actingAs($operator)
                ->patchJson(route('sales-leads.complete', $lead), [
                    'expected_updated_at' => app(OptimisticLockService::class)->token($lead),
                ])
                ->assertForbidden();
            $this->assertNull($lead->fresh()->completed_at, $role.' JSON');
        }
    }

    public function test_br51_flag_on_makes_supervisor_branch_manager_manager_and_ass_manager_read_only_for_sales_team_lead_updates(): void
    {
        $this->enableHierarchicalMonitoring();
        $branch = Branch::create(['name' => 'Cabang BR51', 'code' => 'BR51', 'is_active' => true]);
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Proyek BR51', 'is_active' => true]);
        $sales = $this->user('sales', 'Sales BR51', $branch);
        $sales->assignedProjects()->syncWithoutDetaching([$project->id => [
            'is_primary' => true,
            'is_active' => true,
            'assignment_start_date' => today()->subDay()->toDateString(),
            'assignment_end_date' => today()->addDay()->toDateString(),
        ]]);
        $lead = SalesLead::create([
            'branch_id' => $branch->id,
            'project_id' => $project->id,
            'sales_user_id' => $sales->id,
            'lead_date' => today()->toDateString(),
            'customer_name' => 'Lead BR51',
            'phone' => '081234567890',
            'source' => 'Referensi',
            'platform' => 'Instagram',
            'campaign_name' => 'Canvassing',
            'created_by' => $sales->id,
        ]);
        $payload = [
            'branch_id' => $branch->id,
            'project_id' => $project->id,
            'sales_user_id' => $sales->id,
            'lead_date' => today()->toDateString(),
            'customer_name' => 'Lead BR51 Diubah',
            'phone' => '081234567890',
            'source' => 'Referensi',
            'platform' => 'Instagram',
            'campaign_name' => 'Canvassing',
            'current_status' => 'no_response',
            'expected_updated_at' => app(OptimisticLockService::class)->token($lead),
        ];

        foreach (['supervisor', 'branch_manager', 'manager', 'ass_manager'] as $role) {
            $operator = $this->user($role, ucfirst($role).' BR51', $branch);
            $operator->refresh();
            $before = $lead->fresh()->customer_name;

            $this->actingAs($operator)
                ->put(route('sales-leads.update', $lead), $payload)
                ->assertForbidden();
            $this->assertSame($before, $lead->fresh()->customer_name, $role);

            $this->actingAs($operator)
                ->putJson(route('sales-leads.update', $lead), $payload)
                ->assertForbidden();
            $this->assertSame($before, $lead->fresh()->customer_name, $role.' JSON');
        }
    }

    private function enableHierarchicalMonitoring(): void
    {
        DB::table('app_settings')->updateOrInsert(['key' => 'org_hierarchical_monitoring'], [
            'value' => json_encode(true, JSON_THROW_ON_ERROR),
            'group' => 'feature_flags',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        app(AppSettingResolver::class)->forget('org_hierarchical_monitoring');
    }

    private function user(string $role, string $name, Branch $branch): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'role_id' => Role::query()->where('slug', $role)->value('id'),
            'branch_id' => $branch->id,
            'password_changed_at' => now(),
        ]);
        $user->branches()->syncWithoutDetaching([$branch->id => [
            'can_view' => true,
            'can_edit' => true,
            'can_sync' => true,
        ]]);

        return $user;
    }
}
