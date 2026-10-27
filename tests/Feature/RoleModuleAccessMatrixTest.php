<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\FeedbackReport;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Policies\FeedbackReportPolicy;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleModuleAccessMatrixTest extends TestCase
{
    use RefreshDatabase;

    private const EXPENSE_PERMISSIONS = [
        'expenses.view',
        'expenses.create',
        'expenses.update',
        'expenses.cancel',
        'expenses.export',
        'expenses.manage_categories',
        'expenses.view_assigned',
        'expenses.manage_assigned',
        'expenses.export_assigned',
        'expenses.view_branch',
        'expenses.manage_branch',
        'expenses.export_branch',
        'expenses.view_all',
        'expenses.manage_all',
        'expenses.export_all',
        'expenses.configure',
        'expenses.delete_permanently',
    ];

    private const SALES_MUTATION_PERMISSIONS = [
        'sales_pocketbook.manage_own',
        'sales_pocketbook.manage_team',
        'sales_pocketbook.manage_assigned',
        'sales_pocketbook.manage_branch',
        'sales_pocketbook.manage_all',
    ];

    public function test_explicit_permissions_are_registered_and_only_superadmin_has_new_global_capabilities(): void
    {
        $this->assertContains('lead_sources.manage', collect(PermissionCatalog::permissions())->pluck('slug')->all());
        $this->assertContains('feedback_reports.review', collect(PermissionCatalog::permissions())->pluck('slug')->all());

        foreach (['sales', 'sales_coordinator', 'supervisor', 'admin', 'branch_manager', 'pusat'] as $roleSlug) {
            $user = $this->user($roleSlug);

            $this->assertFalse($user->hasPermission('lead_sources.manage'), $roleSlug);
            $this->assertFalse($user->hasPermission('feedback_reports.review'), $roleSlug);
        }

        $this->assertTrue($this->user('superadmin')->hasPermission('lead_sources.manage'));
        $this->assertTrue($this->user('superadmin')->hasPermission('feedback_reports.review'));
    }

    public function test_admin_and_branch_manager_have_monitoring_only_buku_saku_without_pengeluaran_access(): void
    {
        foreach (['admin', 'branch_manager'] as $roleSlug) {
            $user = $this->user($roleSlug);

            foreach (self::EXPENSE_PERMISSIONS as $permission) {
                $this->assertFalse($user->hasPermission($permission), "{$roleSlug}: {$permission}");
            }

            foreach (self::SALES_MUTATION_PERMISSIONS as $permission) {
                $this->assertFalse($user->hasPermission($permission), "{$roleSlug}: {$permission}");
            }

            $this->assertTrue($user->hasPermission('sales_pocketbook.view_branch'), $roleSlug);
            $this->assertTrue($user->hasPermission('sales_pocketbook.export_branch'), $roleSlug);
        }
    }

    public function test_lead_source_routes_reject_an_ordinary_crm_user_but_allow_superadmin(): void
    {
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);
        $admin = $this->user('admin', $branch);

        $this->actingAs($admin)->get(route('lead-sources.index'))->assertForbidden();
        $this->actingAs($admin)->post(route('lead-sources.store'), ['name' => 'Instagram'])->assertForbidden();

        $this->actingAs($this->user('superadmin'))->get(route('lead-sources.index'))->assertOk();
    }

    public function test_feedback_review_requires_registered_permission_and_branch_scope(): void
    {
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);
        $otherBranch = Branch::create(['name' => 'Pati', 'code' => 'PTI', 'is_active' => true]);
        $role = Role::create(['name' => 'Feedback Reviewer', 'slug' => 'feedback_reviewer', 'is_superadmin' => false]);
        $role->permissions()->attach(
            Permission::where('slug', 'feedback_reports.review')->firstOrFail(),
        );
        $reviewer = $this->user('feedback_reviewer', $branch);
        $reporter = $this->user('sales', $branch);
        $sameBranchReport = FeedbackReport::create($this->reportPayload($branch, $reporter));
        $otherBranchReport = FeedbackReport::create($this->reportPayload($otherBranch, $reporter));
        $policy = new FeedbackReportPolicy;

        $this->assertTrue($policy->review($reviewer, $sameBranchReport));
        $this->assertFalse($policy->review($reviewer, $otherBranchReport));
    }

    public function test_supplemental_pusat_role_does_not_grant_feedback_review(): void
    {
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);
        $user = $this->user('admin', $branch);
        $user->roles()->attach(Role::where('slug', 'pusat')->firstOrFail());
        $report = FeedbackReport::create($this->reportPayload($branch, $user));

        $this->assertTrue($user->fresh()->hasRole('pusat'));
        $this->assertFalse($user->fresh()->hasPermission('feedback_reports.review'));
        $this->assertFalse((new FeedbackReportPolicy)->review($user->fresh(), $report));
        $this->actingAs($user)->get(route('feedback-reports.show', $report))->assertForbidden();
    }

    private function user(string $roleSlug, ?Branch $branch = null): User
    {
        return User::factory()->create([
            'role_id' => Role::where('slug', $roleSlug)->firstOrFail()->id,
            'branch_id' => $branch?->id,
            'password_changed_at' => now(),
        ]);
    }

    private function reportPayload(Branch $branch, User $reporter): array
    {
        return [
            'user_id' => $reporter->id,
            'branch_id' => $branch->id,
            'type' => 'bug',
            'module' => 'Database',
            'title' => 'Laporan',
            'description' => 'Masalah',
            'status' => 'pending',
            'priority' => 'medium',
        ];
    }
}
