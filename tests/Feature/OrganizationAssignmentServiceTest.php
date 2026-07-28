<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\User;
use App\Services\BranchAssignmentService;
use App\Services\ProjectAssignmentService;
use App\Services\WorkspaceAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OrganizationAssignmentServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_branch_assignment_requires_active_primary_membership_and_preserves_flags(): void
    {
        $first = $this->branch('SLO');
        $second = $this->branch('MGL');
        $user = $this->user('manager', $first);

        app(BranchAssignmentService::class)->assign($user, [
            $first->id => ['can_view' => true, 'can_edit' => true, 'can_sync' => false],
            $second->id => ['can_view' => false, 'can_edit' => false, 'can_sync' => true, 'can_manage_members' => true],
        ], $second->id);

        $user = $user->fresh();
        $this->assertSame($second->id, $user->branch_id);
        $membership = $user->branches()->whereKey($second->id)->firstOrFail()->pivot;
        $this->assertFalse((bool) $membership->can_view);
        $this->assertTrue((bool) $membership->can_sync);
        $this->assertTrue((bool) $membership->can_manage_members);
        $this->assertDatabaseHas('activity_log', ['subject_id' => $user->id, 'event' => 'branch_assignments_changed']);
    }

    public function test_branch_assignment_rejects_inactive_branch_and_keeps_existing_inactive_membership(): void
    {
        $active = $this->branch('SLO');
        $inactive = $this->branch('OLD');
        $user = $this->user('manager', $active);
        $user->branches()->attach($inactive->id, ['can_view' => true]);
        $inactive->update(['is_active' => false]);

        app(BranchAssignmentService::class)->assign($user, [$active->id], $active->id);
        $this->assertDatabaseHas('branch_user', ['user_id' => $user->id, 'branch_id' => $inactive->id]);

        try {
            app(BranchAssignmentService::class)->assign($user, [$inactive->id], $inactive->id);
            $this->fail('Inactive branch assignment should fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('branch_ids', $exception->errors());
        }
    }

    public function test_project_assignment_supports_leadership_roles_and_keeps_one_primary(): void
    {
        $branch = $this->branch('SLO');
        $user = $this->user('supervisor', $branch);
        $first = $this->project($branch, 'Satu');
        $second = $this->project($branch, 'Dua');

        app(ProjectAssignmentService::class)->assign($user, [$first->id, $second->id], $second->id);

        $this->assertCount(2, $user->fresh()->assignedProjects);
        $this->assertSame(1, $user->assignedProjects()->wherePivot('is_primary', true)->count());
        $this->assertTrue($user->primaryAssignedProject()->firstOrFail()->is($second));
        $this->assertDatabaseHas('activity_log', ['subject_id' => $user->id, 'event' => 'project_assignments_changed']);
    }

    public function test_project_assignment_rejects_inactive_or_inaccessible_projects(): void
    {
        $branch = $this->branch('SLO');
        $otherBranch = $this->branch('MGL');
        $user = $this->user('supervisor', $branch);
        $inactive = $this->project($branch, 'Lama', false);
        $inaccessible = $this->project($otherBranch, 'Luar');

        foreach ([[$inactive->id, 'assigned_project_ids'], [$inaccessible->id, 'assigned_project_ids']] as [$projectId, $key]) {
            try {
                app(ProjectAssignmentService::class)->assign($user, [$projectId], $projectId);
                $this->fail('Invalid project assignment should fail.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($key, $exception->errors());
            }
        }
    }

    public function test_workspace_access_honors_assignment_active_state_and_inclusive_date_window(): void
    {
        $branch = $this->branch('SLO');
        $sales = $this->user('sales', $branch);
        $current = $this->project($branch, 'Saat Ini');
        $future = $this->project($branch, 'Mendatang');
        $expired = $this->project($branch, 'Selesai');
        $inactive = $this->project($branch, 'Dinonaktifkan');

        app(ProjectAssignmentService::class)->assign($sales, [
            $current->id => ['assignment_start_date' => today(), 'assignment_end_date' => today()],
            $future->id => ['assignment_start_date' => today()->addDay()],
            $expired->id => ['assignment_end_date' => today()->subDay()],
            $inactive->id => ['is_active' => false],
        ], $current->id);

        $currentAssignment = $sales->assignedProjects()->whereKey($current->id)->firstOrFail()->pivot;
        $this->assertSame(today()->toDateString(), $currentAssignment->assignment_start_date->toDateString());
        $this->assertSame(today()->toDateString(), $currentAssignment->assignment_end_date->toDateString());
        $this->assertSame([$current->id], app(WorkspaceAccessService::class)->accessibleProjectIds($sales));
    }

    private function branch(string $code): Branch
    {
        return Branch::create(['name' => $code, 'code' => $code, 'is_active' => true]);
    }

    private function project(Branch $branch, string $name, bool $active = true): LeadMaster
    {
        return LeadMaster::create(['branch_id' => $branch->id, 'project_name' => $name, 'is_active' => $active]);
    }

    private function user(string $role, Branch $branch): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', $role)->firstOrFail()->id, 'branch_id' => $branch->id]);
    }
}
