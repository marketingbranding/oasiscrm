<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Models\Branch;
use App\Models\ContentItem;
use App\Models\LeadMaster;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SalesCoordinatorSales;
use App\Models\SalesLead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class RbacPolicyFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_lead_ownership_uses_sales_user_id_not_created_by(): void
    {
        [$branch, $project] = $this->workspace();
        $sales = $this->user('sales', $branch);
        $otherSales = $this->user('sales', $branch);
        $this->assign($sales, $project);
        $this->assign($otherSales, $project);
        $lead = $this->lead($sales, $project, $otherSales);

        $this->assertTrue(Gate::forUser($sales)->allows('view', $lead));
        $this->assertFalse(Gate::forUser($otherSales)->allows('view', $lead));
    }

    public function test_sales_agenda_ownership_uses_owner_user_id_not_created_by(): void
    {
        [$branch, $project] = $this->workspace();
        $sales = $this->user('sales', $branch);
        $otherSales = $this->user('sales', $branch);
        $this->assign($sales, $project);
        $this->assign($otherSales, $project);
        $agenda = $this->agenda($sales, $project, $otherSales);

        $this->assertTrue(Gate::forUser($sales)->allows('view', $agenda));
        $this->assertFalse(Gate::forUser($otherSales)->allows('view', $agenda));
    }

    public function test_coordinator_team_authority_uses_active_canonical_mapping(): void
    {
        [$branch, $project] = $this->workspace();
        $coordinator = $this->user('sales_coordinator', $branch);
        $otherCoordinator = $this->user('sales_coordinator', $branch);
        $mappedSales = $this->user('sales', $branch);
        $unrelatedSales = $this->user('sales', $branch);
        foreach ([$mappedSales, $unrelatedSales] as $sales) {
            $this->assign($sales, $project);
        }
        SalesCoordinatorSales::create(['coordinator_user_id' => $coordinator->id, 'sales_user_id' => $mappedSales->id]);
        SalesCoordinatorSales::create(['coordinator_user_id' => $otherCoordinator->id, 'sales_user_id' => $unrelatedSales->id]);

        $mappedLead = $this->lead($mappedSales, $project);
        $unrelatedLead = $this->lead($unrelatedSales, $project);
        $mappedAgenda = $this->agenda($mappedSales, $project);
        $unrelatedAgenda = $this->agenda($unrelatedSales, $project);

        $this->assertTrue(Gate::forUser($coordinator)->allows('view', $mappedLead));
        $this->assertFalse(Gate::forUser($coordinator)->allows('view', $unrelatedLead));
        $this->assertTrue(Gate::forUser($coordinator)->allows('view', $mappedAgenda));
        $this->assertFalse(Gate::forUser($coordinator)->allows('view', $unrelatedAgenda));
    }

    public function test_ambiguous_current_coordinator_mapping_is_denied(): void
    {
        [$branch, $project] = $this->workspace();
        $coordinator = $this->user('sales_coordinator', $branch);
        $sales = $this->user('sales', $branch);
        $this->assign($sales, $project);
        SalesCoordinatorSales::create(['coordinator_user_id' => $coordinator->id, 'sales_user_id' => $sales->id]);
        SalesCoordinatorSales::create(['coordinator_user_id' => $coordinator->id, 'sales_user_id' => $sales->id]);

        $this->assertFalse(Gate::forUser($coordinator)->allows('view', $this->lead($sales, $project)));
    }

    public function test_expired_and_inactive_coordinator_mappings_are_ignored(): void
    {
        [$branch, $project] = $this->workspace();
        $coordinator = $this->user('sales_coordinator', $branch);
        $expiredSales = $this->user('sales', $branch);
        $inactiveSales = $this->user('sales', $branch, false);
        $this->assign($expiredSales, $project);
        $this->assign($inactiveSales, $project);
        SalesCoordinatorSales::create([
            'coordinator_user_id' => $coordinator->id,
            'sales_user_id' => $expiredSales->id,
            'ended_at' => today()->subDay(),
        ]);
        SalesCoordinatorSales::create([
            'coordinator_user_id' => $coordinator->id,
            'sales_user_id' => $inactiveSales->id,
        ]);

        $this->assertFalse(Gate::forUser($coordinator)->allows('view', $this->lead($expiredSales, $project)));
        $this->assertFalse(Gate::forUser($coordinator)->allows('view', $this->lead($inactiveSales, $project)));
    }

    public function test_supervisor_user_id_alone_does_not_create_coordinator_team_authority(): void
    {
        [$branch, $project] = $this->workspace();
        $coordinator = $this->user('sales_coordinator', $branch);
        $sales = $this->user('sales', $branch, true, $coordinator);
        $this->assign($sales, $project);

        $this->assertFalse(Gate::forUser($coordinator)->allows('view', $this->lead($sales, $project)));
    }

    public function test_branch_monitoring_roles_are_branch_scoped(): void
    {
        [$branch, $project] = $this->workspace();
        [$otherBranch, $otherProject] = $this->workspace('Other');
        $sales = $this->user('sales', $branch);
        $otherSales = $this->user('sales', $otherBranch);
        $this->assign($sales, $project);
        $this->assign($otherSales, $otherProject);
        $localLead = $this->lead($sales, $project);
        $foreignLead = $this->lead($otherSales, $otherProject);

        foreach (['supervisor', 'branch_manager', 'manager', 'sas', 'sam'] as $role) {
            $viewer = $this->user($role, $branch);
            $this->assertTrue(Gate::forUser($viewer)->allows('view', $localLead), $role.' should see local branch data');
            $this->assertFalse(Gate::forUser($viewer)->allows('view', $foreignLead), $role.' should not see another branch');
        }
    }

    public function test_sas_and_sam_have_identical_phase_one_permissions_without_management(): void
    {
        $sas = Role::query()->where('slug', 'sas')->firstOrFail();
        $sam = Role::query()->where('slug', 'sam')->firstOrFail();
        $sasPermissions = $sas->permissions()->pluck('slug')->sort()->values()->all();
        $samPermissions = $sam->permissions()->pluck('slug')->sort()->values()->all();

        $this->assertSame($sasPermissions, $samPermissions);
        $this->assertSame([
            'sales_daily_plans.view_branch',
            'sales_pocketbook.export',
            'sales_pocketbook.export_branch',
            'sales_pocketbook.view_branch',
            'sales_targets.view_branch',
        ], $sasPermissions);
        $this->assertFalse($sas->permissions()->whereIn('slug', ['users.assign_roles', 'roles.manage', 'permissions.manage'])->exists());
        $this->assertSame(1, DB::table('changelogs')->whereNull('version')->where('title', 'Fondasi Kebijakan RBAC CRM')->count());
    }

    public function test_pusat_is_explicit_and_supplemental_roles_do_not_escalate(): void
    {
        $pusat = Role::query()->where('slug', 'pusat')->firstOrFail();
        $user = $this->user('sales');
        $user->roles()->attach($pusat);

        $this->assertFalse($user->hasPermission('users.view'));
        $this->assertFalse($user->hasPermission('sales_pocketbook.view_all'));
        $this->assertNotSame($user->role_id, $pusat->id);
        $this->assertTrue($this->user('pusat')->hasPermission('sales_pocketbook.view_all'));
    }

    public function test_superadmin_gets_registered_permissions_but_not_unknown_permissions(): void
    {
        $superadmin = $this->user('superadmin');
        $registered = Permission::query()->pluck('slug');

        foreach ($registered as $permission) {
            $this->assertTrue($superadmin->hasPermission($permission), $permission);
        }
        $this->assertFalse($superadmin->hasPermission('rbac.unknown'));
    }

    public function test_suspended_and_inactive_users_cannot_use_policies(): void
    {
        [$branch, $project] = $this->workspace();
        $sales = $this->user('sales', $branch);
        $this->assign($sales, $project);
        $lead = $this->lead($sales, $project);

        DB::table('users')->where('id', $sales->id)->update([
            'account_status' => AccountStatus::Suspended->value,
            'is_active' => false,
        ]);
        $sales->refresh();
        $this->assertFalse($sales->isAccountActive());
        $this->assertFalse($sales->is_active);
        $this->assertFalse(Gate::forUser($sales)->allows('view', $lead), 'suspended user must be denied');

        DB::table('users')->where('id', $sales->id)->update([
            'account_status' => AccountStatus::Inactive->value,
            'is_active' => false,
        ]);
        $sales->refresh();
        $this->assertFalse(Gate::forUser($sales)->allows('view', $lead), 'inactive user must be denied');
    }

    public function test_sales_assignment_window_controls_own_lead_visibility(): void
    {
        [$branch, $project] = $this->workspace();
        $sales = $this->user('sales', $branch);
        $this->assign($sales, $project, ['assignment_end_date' => today()->subDay()]);

        $this->assertFalse(Gate::forUser($sales)->allows('view', $this->lead($sales, $project)));
    }

    public function test_forged_branch_and_project_records_are_rejected(): void
    {
        [$branch, $project] = $this->workspace();
        [$otherBranch, $otherProject] = $this->workspace('Other');
        $sales = $this->user('sales', $branch);
        $this->assign($sales, $project);
        $this->assign($sales, $otherProject);

        $foreignBranchLead = $this->lead($sales, $otherProject);
        $this->assertFalse(Gate::forUser($sales)->allows('view', $foreignBranchLead));

        $unassignedProject = LeadMaster::create([
            'branch_id' => $branch->id,
            'project_name' => 'Unassigned',
            'is_active' => true,
        ]);
        $this->assertFalse(Gate::forUser($sales)->allows('view', $this->lead($sales, $unassignedProject)));
    }

    private function workspace(string $prefix = 'Main'): array
    {
        $branch = Branch::create(['name' => $prefix.' Branch', 'code' => 'B'.str_pad((string) (Branch::query()->count() + 1), 3, '0', STR_PAD_LEFT), 'is_active' => true]);
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => $prefix.' Project', 'is_active' => true]);

        return [$branch, $project];
    }

    private function user(string $role, ?Branch $branch = null, bool $active = true, ?User $supervisor = null): User
    {
        $user = User::factory()->create([
            'role_id' => Role::query()->where('slug', $role)->value('id'),
            'branch_id' => $branch?->id,
            'supervisor_user_id' => $supervisor?->id,
            'account_status' => $active ? AccountStatus::Active : AccountStatus::Inactive,
            'is_active' => $active,
            'email_verified_at' => now(),
            'password_changed_at' => now(),
        ]);

        if ($branch) {
            $user->branches()->syncWithoutDetaching([$branch->id => ['can_view' => true, 'can_edit' => true, 'can_sync' => true]]);
        }

        return $user;
    }

    private function assign(User $user, LeadMaster $project, array $overrides = []): void
    {
        $user->assignedProjects()->syncWithoutDetaching([$project->id => $overrides + [
            'is_primary' => true,
            'is_active' => true,
            'assignment_start_date' => today()->subDay()->toDateString(),
            'assignment_end_date' => null,
        ]]);
    }

    private function lead(User $owner, LeadMaster $project, ?User $creator = null): SalesLead
    {
        return SalesLead::create([
            'branch_id' => $project->branch_id,
            'project_id' => $project->id,
            'sales_user_id' => $owner->id,
            'lead_date' => today(),
            'customer_name' => 'Lead '.$owner->id.' '.$project->id,
            'created_by' => $creator?->id ?? $owner->id,
        ]);
    }

    private function agenda(User $owner, LeadMaster $project, ?User $creator = null): ContentItem
    {
        return ContentItem::create([
            'branch_id' => $project->branch_id,
            'sales_project_id' => $project->id,
            'project_name' => $project->project_name,
            'item_type' => 'agenda',
            'agenda_type' => ContentItem::SALES_AGENDA_TYPE,
            'visibility' => 'personal',
            'title' => 'Agenda '.$owner->id.' '.$project->id,
            'scheduled_date' => today(),
            'status' => 'planned',
            'owner_user_id' => $owner->id,
            'created_by' => $creator?->id ?? $owner->id,
        ]);
    }
}
