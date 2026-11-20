<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ContentItem;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\User;
use App\Services\AppSettingResolver;
use App\Services\OrganizationScopeService;
use App\Services\ReportingHierarchyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrganizationScopeServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_team_scope_includes_direct_reports_and_descendants_without_cross_branch_leakage(): void
    {
        $branch = $this->branch('SLO');
        $otherBranch = $this->branch('MGL');
        $viewer = $this->user('supervisor', $branch);
        $direct = $this->user('sales_coordinator', $branch);
        $descendant = $this->user('sales', $branch);
        $crossBranch = $this->user('sales', $otherBranch);
        $hierarchy = app(ReportingHierarchyService::class);

        $hierarchy->assignSupervisor($direct, $viewer);
        $hierarchy->assignSupervisor($descendant, $direct);
        // Simulate a legacy hierarchy row that predates assignment validation.
        $crossBranch->forceFill(['supervisor_user_id' => $viewer->id])->save();

        $scope = app(OrganizationScopeService::class);
        $this->assertEqualsCanonicalizing([$direct->id, $descendant->id], $scope->teamIds($viewer));
        $this->assertContains($direct->id, $scope->visibleUserIds($viewer, 'sales_pocketbook'));
        $this->assertNotContains($crossBranch->id, $scope->visibleUserIds($viewer, 'sales_pocketbook'));
    }

    public function test_br51_flag_on_in_graph_mode_includes_recursive_reporting_descendants(): void
    {
        // BR-51: hierarchical monitoring follows effective reporting graph descendants.
        $branch = $this->branch('HIE');
        $viewer = $this->user('supervisor', $branch);
        $direct = $this->user('sales_coordinator', $branch);
        $remote = $this->user('sales', $branch);
        app(ReportingHierarchyService::class)->assignSupervisor($direct, $viewer);
        app(ReportingHierarchyService::class)->assignSupervisor($remote, $direct);
        $this->enableHierarchicalMonitoring();
        config(['organization.graph_mode' => 'graph', 'organization.graph_user_ids' => '']);

        $this->assertEqualsCanonicalizing(
            [$direct->id, $remote->id],
            app(OrganizationScopeService::class)->visibleUserIds($viewer, 'sales_pocketbook'),
        );
    }

    public function test_br52_pusat_scope_only_widens_existing_branch_permission(): void
    {
        // BR-52: pusat widens branch scope for stated roles, not own/assigned scope.
        $branch = $this->branch('PUS');
        $otherBranch = $this->branch('POT');
        $viewer = $this->user('supervisor', $branch);
        $viewer->forceFill(['scope_level' => 'pusat'])->saveQuietly();
        $this->enableHierarchicalMonitoring();

        $scope = app(OrganizationScopeService::class);
        $this->assertContains($otherBranch->id, $scope->branchIds($viewer, 'sales_pocketbook'));
        $this->assertNotContains($otherBranch->id, $scope->branchIds($viewer, 'work_planner'));
    }

    public function test_br51_flag_on_filters_inactive_and_cross_branch_descendants(): void
    {
        // BR-51: inactive and out-of-workspace descendants never enter monitoring scope.
        $branch = $this->branch('ACT');
        $otherBranch = $this->branch('OUT');
        $viewer = $this->user('supervisor', $branch);
        $inactive = $this->user('sales_coordinator', $branch);
        $crossBranch = $this->user('sales_coordinator', $otherBranch);
        $inactive->forceFill(['is_active' => false, 'supervisor_user_id' => $viewer->id])->saveQuietly();
        $crossBranch->forceFill(['supervisor_user_id' => $viewer->id])->saveQuietly();
        $this->enableHierarchicalMonitoring();

        $visible = app(OrganizationScopeService::class)->visibleUserIds($viewer, 'sales_pocketbook');

        $this->assertNotContains($inactive->id, $visible);
        $this->assertNotContains($crossBranch->id, $visible);
    }

    public function test_br51_flag_on_coordinator_scope_keeps_team_mapping_isolated(): void
    {
        // BR-51: Coordinator A cannot see Coordinator B's active Sales members.
        $branch = $this->branch('COA');
        $coordinatorA = $this->user('sales_coordinator', $branch);
        $coordinatorB = $this->user('sales_coordinator', $branch);
        $salesA = $this->user('sales', $branch);
        $salesB = $this->user('sales', $branch);
        DB::table('sales_coordinator_sales')->insert([
            ['coordinator_user_id' => $coordinatorA->id, 'sales_user_id' => $salesA->id, 'is_active' => true, 'started_at' => today(), 'created_at' => now(), 'updated_at' => now()],
            ['coordinator_user_id' => $coordinatorB->id, 'sales_user_id' => $salesB->id, 'is_active' => true, 'started_at' => today(), 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->enableHierarchicalMonitoring();

        $visible = app(OrganizationScopeService::class)->visibleUserIds($coordinatorA, 'sales_pocketbook');

        $this->assertSame([$salesA->id], $visible);
        $this->assertNotContains($salesB->id, $visible);
    }

    public function test_br52_pusat_scope_does_not_expand_own_or_assigned_projects(): void
    {
        // BR-52: pusat only widens branch permission, never own or assigned permission.
        $branch = $this->branch('OWN');
        $otherBranch = $this->branch('ASN');
        $viewer = $this->user('supervisor', $branch);
        $viewer->forceFill(['scope_level' => 'pusat'])->saveQuietly();
        $assignedProject = LeadMaster::create(['branch_id' => $otherBranch->id, 'project_name' => 'Assigned Other', 'is_active' => true]);
        $unassignedProject = LeadMaster::create(['branch_id' => $otherBranch->id, 'project_name' => 'Unassigned Other', 'is_active' => true]);
        DB::table('project_user')->insert(['user_id' => $viewer->id, 'project_id' => $assignedProject->id, 'is_active' => true]);
        $this->enableHierarchicalMonitoring();

        $scope = app(OrganizationScopeService::class);

        $this->assertNotContains($assignedProject->id, $scope->projectIds($viewer, 'work_planner'));
        $this->assertNotContains($unassignedProject->id, $scope->projectIds($viewer, 'work_planner'));
    }

    public function test_br51_workspace_intersection_rejects_assigned_user_outside_workspace_branch(): void
    {
        // BR-51: project assignment cannot bypass workspace branch intersection.
        $branch = $this->branch('WSP');
        $otherBranch = $this->branch('WOT');
        $viewer = $this->user('supervisor', $branch);
        $descendant = $this->user('sales', $otherBranch);
        $descendant->forceFill(['supervisor_user_id' => $viewer->id])->saveQuietly();
        $project = LeadMaster::create(['branch_id' => $otherBranch->id, 'project_name' => 'Outside Workspace', 'is_active' => true]);
        DB::table('project_user')->insert(['user_id' => $descendant->id, 'project_id' => $project->id, 'is_active' => true]);
        $this->enableHierarchicalMonitoring();

        $this->assertNotContains($descendant->id, app(OrganizationScopeService::class)->visibleUserIds($viewer, 'sales_pocketbook'));
    }

    public function test_br51_coordinator_team_ids_respect_all_workspace_branches(): void
    {
        // BR-51: Coordinator team scope remains bounded across multi-branch workspace.
        $primary = $this->branch('CPA');
        $secondary = $this->branch('CPB');
        $outside = $this->branch('CPO');
        $coordinator = $this->user('sales_coordinator', $primary);
        $coordinator->branches()->attach($secondary->id, ['is_current' => true, 'can_view' => true]);
        $allowed = $this->user('sales', $secondary);
        $leak = $this->user('sales', $outside);
        DB::table('sales_coordinator_sales')->insert([
            ['coordinator_user_id' => $coordinator->id, 'sales_user_id' => $allowed->id, 'is_active' => true, 'started_at' => today(), 'created_at' => now(), 'updated_at' => now()],
            ['coordinator_user_id' => $coordinator->id, 'sales_user_id' => $leak->id, 'is_active' => true, 'started_at' => today(), 'created_at' => now(), 'updated_at' => now()],
        ]);

        $teamIds = app(OrganizationScopeService::class)->teamIds($coordinator, 'sales_pocketbook');

        $this->assertContains($allowed->id, $teamIds);
        $this->assertNotContains($leak->id, $teamIds);
    }

    public function test_br51_assigned_descendant_requires_workspace_branch_intersection(): void
    {
        // BR-51: assigned/project scope cannot leak a descendant across workspace branch boundary.
        $branch = $this->branch('DPA');
        $otherBranch = $this->branch('DPB');
        $viewer = $this->user('manager', $branch);
        $descendant = $this->user('sales', $otherBranch);
        $descendant->forceFill(['supervisor_user_id' => $viewer->id])->saveQuietly();
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Workspace Assigned', 'is_active' => true]);
        DB::table('project_user')->insert(['user_id' => $descendant->id, 'project_id' => $project->id, 'is_active' => true]);
        $this->enableHierarchicalMonitoring();

        $this->assertNotContains($descendant->id, app(OrganizationScopeService::class)->visibleUserIds($viewer, 'sales_pocketbook'));
    }

    public function test_br51_coordinator_explicit_outside_branch_returns_empty_team(): void
    {
        // BR-51: explicit branch context outside workspace fails closed.
        $branch = $this->branch('CTX');
        $outside = $this->branch('CTO');
        $coordinator = $this->user('sales_coordinator', $branch);
        $sales = $this->user('sales', $outside);
        DB::table('sales_coordinator_sales')->insert([
            'coordinator_user_id' => $coordinator->id, 'sales_user_id' => $sales->id, 'is_active' => true,
            'started_at' => today(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame([], app(OrganizationScopeService::class)->teamIds($coordinator, 'sales_pocketbook', $outside->id));
    }

    public function test_br51_coordinator_own_only_scope_returns_only_self(): void
    {
        // BR-51: own-only permission never expands to full team.
        $branch = $this->branch('OWN');
        $coordinator = $this->user('sales_coordinator', $branch);
        $sales = $this->user('sales', $branch);
        DB::table('sales_coordinator_sales')->insert([
            'coordinator_user_id' => $coordinator->id, 'sales_user_id' => $sales->id, 'is_active' => true,
            'started_at' => today(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->enableHierarchicalMonitoring();
        $coordinator->setRelation('role', tap($coordinator->role)->setRelation('permissions', collect([
            (object) ['slug' => 'sales_pocketbook.view_own'],
        ])));

        $this->assertSame([$coordinator->id], app(OrganizationScopeService::class)->visibleUserIds($coordinator, 'sales_pocketbook'));
    }

    public function test_br51_coordinator_unregistered_action_fails_closed(): void
    {
        // BR-51: no registered permission or scope yields no Coordinator team data.
        $branch = $this->branch('CFA');
        $coordinator = $this->user('sales_coordinator', $branch);
        $sales = $this->user('sales', $branch);
        DB::table('sales_coordinator_sales')->insert([
            'coordinator_user_id' => $coordinator->id, 'sales_user_id' => $sales->id, 'is_active' => true,
            'started_at' => today(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->enableHierarchicalMonitoring();

        $this->assertSame([], app(OrganizationScopeService::class)->visibleUserIds($coordinator, 'sales_pocketbook', 'unregistered_action'));
    }

    public function test_br53_extension_point_grants_no_access(): void
    {
        // BR-53: special access remains unavailable until WP-2.2.
        $viewer = $this->user('manager', $this->branch('B53'));

        $this->assertFalse(app(OrganizationScopeService::class)->hasSpecialMonitoringGrant($viewer, 'sales_pocketbook', null));
    }

    public function test_supplemental_pusat_role_does_not_grant_global_branch_access(): void
    {
        $branch = $this->branch('SLO');
        $otherBranch = $this->branch('MGL');
        $admin = $this->user('supervisor', $branch);
        $admin->roles()->attach(Role::where('slug', 'pusat')->firstOrFail());
        $pusat = $this->user('pusat', $branch);

        $this->assertFalse($admin->fresh()->canViewAllBranches());
        $this->assertNotContains($otherBranch->id, app(OrganizationScopeService::class)->branchIds($admin));
        $this->assertTrue($pusat->canViewAllBranches());
        $this->assertContains($otherBranch->id, app(OrganizationScopeService::class)->branchIds($pusat));
    }

    public function test_assigned_project_scopes_revoke_branch_access_consistently(): void
    {
        $primary = $this->branch('RVA');
        $additional = $this->branch('RVB');
        $project = LeadMaster::create(['branch_id' => $additional->id, 'project_name' => 'Proyek Assigned', 'is_active' => true]);
        $user = $this->user('supervisor', $primary);
        $user->branches()->attach($additional->id);
        DB::table('project_user')->insert([
            'user_id' => $user->id, 'project_id' => $project->id, 'is_active' => true,
        ]);

        $scope = app(OrganizationScopeService::class);
        $this->assertContains($additional->id, $scope->branchIds($user, 'work_planner'));
        $this->assertContains($project->id, $scope->projectIds($user, 'work_planner'));

        $user->branches()->updateExistingPivot($additional->id, ['can_view' => false]);
        app()->forgetInstance(OrganizationScopeService::class);

        $scope = app(OrganizationScopeService::class);
        $this->assertNotContains($additional->id, $scope->branchIds($user, 'work_planner'), 'Revoked branch view must drop branch.');
        $this->assertNotContains($project->id, $scope->projectIds($user, 'work_planner'), 'Revoked branch view must strip assigned project.');
    }

    public function test_projectless_item_remains_visible_and_blocked_labels_hidden(): void
    {
        $branch = $this->branch('PLB');
        $assignedProject = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Proyek Diizinkan', 'is_active' => true]);
        LeadMaster::create(['branch_id' => $branch->id, 'project_name' => '  Proyek   Terlarang  ', 'is_active' => true]);
        $user = $this->user('supervisor', $branch);
        DB::table('project_user')->insert([
            'user_id' => $user->id, 'project_id' => $assignedProject->id, 'is_active' => true,
        ]);
        $creator = $this->user('staff', $branch);

        $projectless = ContentItem::create([
            'branch_id' => $branch->id, 'item_type' => 'task', 'visibility' => 'team',
            'title' => 'Tanpa Proyek', 'status' => 'todo', 'created_by' => $creator->id,
        ]);
        $blockedLabel = ContentItem::create([
            'branch_id' => $branch->id, 'item_type' => 'task', 'visibility' => 'team',
            'project_name' => ' proyek terlarang ', 'title' => 'Proyek Terlarang', 'status' => 'todo',
            'created_by' => $creator->id,
        ]);
        $allowed = ContentItem::create([
            'branch_id' => $branch->id, 'item_type' => 'task', 'visibility' => 'team',
            'project_name' => $assignedProject->project_name, 'title' => 'Proyek Diizinkan', 'status' => 'todo',
            'created_by' => $creator->id,
        ]);

        $visibleIds = app(ContentItem::class)->visibleTo($user)->pluck('id')->all();
        $this->assertContains($projectless->id, $visibleIds);
        $this->assertContains($allowed->id, $visibleIds);
        $this->assertNotContains($blockedLabel->id, $visibleIds);
    }

    public function test_model_scope_and_policy_agree_on_free_text_project_items(): void
    {
        $branch = $this->branch('AGR');
        $assignedProject = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Proyek A', 'is_active' => true]);
        $blockedProject = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Proyek B', 'is_active' => true]);
        $user = $this->user('supervisor', $branch);
        DB::table('project_user')->insert([
            'user_id' => $user->id, 'project_id' => $assignedProject->id, 'is_active' => true,
        ]);
        $creator = $this->user('staff', $branch);
        $own = ContentItem::create([
            'branch_id' => $branch->id, 'item_type' => 'task', 'visibility' => 'team',
            'project_name' => $blockedProject->project_name, 'title' => 'Own', 'status' => 'todo',
            'created_by' => $user->id,
        ]);
        $assigneeItem = ContentItem::create([
            'branch_id' => $branch->id, 'item_type' => 'task', 'visibility' => 'team',
            'project_name' => $blockedProject->project_name, 'title' => 'Assignee', 'status' => 'todo',
            'created_by' => $creator->id,
        ]);
        $assigneeItem->assignees()->attach($user);
        $allowed = ContentItem::create([
            'branch_id' => $branch->id, 'item_type' => 'task', 'visibility' => 'team',
            'project_name' => $assignedProject->project_name, 'title' => 'Allowed', 'status' => 'todo',
            'created_by' => $creator->id,
        ]);
        $blocked = ContentItem::create([
            'branch_id' => $branch->id, 'item_type' => 'task', 'visibility' => 'team',
            'project_name' => $blockedProject->project_name, 'title' => 'Blocked', 'status' => 'todo',
            'created_by' => $creator->id,
        ]);

        $visibleIds = app(ContentItem::class)->visibleTo($user)->pluck('id')->all();

        $this->assertContains($own->id, $visibleIds);
        $this->assertTrue($user->can('view', $own));
        $this->assertContains($assigneeItem->id, $visibleIds);
        $this->assertTrue($user->can('view', $assigneeItem));
        $this->assertContains($allowed->id, $visibleIds);
        $this->assertTrue($user->can('view', $allowed));
        $this->assertNotContains($blocked->id, $visibleIds);
    }

    public function test_organization_assignment_changelog_is_idempotent_and_rendered(): void
    {
        $title = 'Penugasan Organisasi yang Lebih Aman';
        $this->assertSame(1, DB::table('changelogs')->whereNull('version')->where('title', $title)->count());

        $superadmin = $this->user('superadmin', $this->branch('SLO'));
        $superadmin->forceFill(['password_changed_at' => now()])->save();

        $this->actingAs($superadmin)->get(route('changelogs.index'))->assertOk()->assertSeeText($title);
    }

    private function enableHierarchicalMonitoring(): void
    {
        DB::table('app_settings')->updateOrInsert(
            ['key' => 'org_hierarchical_monitoring'],
            ['value' => json_encode(true, JSON_THROW_ON_ERROR), 'group' => 'feature_flags', 'updated_at' => now(), 'created_at' => now()],
        );
        app(AppSettingResolver::class)->forget('org_hierarchical_monitoring');
    }

    private function branch(string $code): Branch
    {
        return Branch::create(['name' => $code, 'code' => $code, 'is_active' => true]);
    }

    private function user(string $role, Branch $branch): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', $role)->firstOrFail()->id, 'branch_id' => $branch->id]);
    }
}
