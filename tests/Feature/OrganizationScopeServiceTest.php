<?php

namespace Tests\Feature;

use App\Models\Branch;
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
        $admin = $this->user('admin', $branch);
        $admin->roles()->attach(Role::where('slug', 'pusat')->firstOrFail());
        $pusat = $this->user('pusat', $branch);

        $this->assertFalse($admin->fresh()->canViewAllBranches());
        $this->assertNotContains($otherBranch->id, app(OrganizationScopeService::class)->branchIds($admin));
        $this->assertTrue($pusat->canViewAllBranches());
        $this->assertContains($otherBranch->id, app(OrganizationScopeService::class)->branchIds($pusat));
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
