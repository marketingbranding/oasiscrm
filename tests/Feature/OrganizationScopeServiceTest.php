<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ContentItem;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\User;
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

    private function branch(string $code): Branch
    {
        return Branch::create(['name' => $code, 'code' => $code, 'is_active' => true]);
    }

    private function user(string $role, Branch $branch): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', $role)->firstOrFail()->id, 'branch_id' => $branch->id]);
    }
}
