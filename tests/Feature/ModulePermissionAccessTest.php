<?php

namespace Tests\Feature;

use App\Http\Middleware\PermissionMiddleware;
use App\Models\Branch;
use App\Models\Changelog;
use App\Models\LeadSource;
use App\Models\Role;
use App\Models\User;
use App\Services\SalesPocketbookOnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Concerns\BuildsExpenseFixtures;
use Tests\TestCase;

class ModulePermissionAccessTest extends TestCase
{
    use BuildsExpenseFixtures, RefreshDatabase;

    public function test_sensitive_module_routes_use_one_exact_permission_slug(): void
    {
        $expected = [
            'sales-pocketbook.export' => 'sales_pocketbook.export',
            'content-calendar.export' => 'work_planner.export',
            'content-calendar.store' => 'work_planner.create',
            'content-calendar.update' => 'work_planner.update',
            'database.index' => 'database.view',
            'database.records.update' => 'database.edit',
            'database.sync' => 'database.sync',
            'konsumen-progress.index' => 'consumer_progress.view',
            'konsumen-progress.sync' => 'consumer_progress.sync',
            'dana-talangan.index' => 'bridge_fund.view',
            'dana-talangan.update' => 'bridge_fund.manage',
            'dana-talangan.export' => 'bridge_fund.export',
            'expenses.index' => 'expenses.view',
            'expenses.store' => 'expenses.create',
            'expenses.update' => 'expenses.update',
            'expenses.cancel' => 'expenses.cancel',
            'expenses.export' => 'expenses.export',
            'expense-categories.index' => 'expenses.manage_categories',
            'lead-sources.index' => 'lead_sources.manage',
            'lead-sources.create' => 'lead_sources.manage',
            'lead-sources.store' => 'lead_sources.manage',
            'lead-sources.show' => 'lead_sources.manage',
            'lead-sources.edit' => 'lead_sources.manage',
            'lead-sources.update' => 'lead_sources.manage',
            'lead-sources.destroy' => 'lead_sources.manage',
            'lead-sources.bulk-destroy' => 'lead_sources.manage',
            'lead-sources.toggle-active' => 'lead_sources.manage',
            'feedback-reports.index' => 'feedback_reports.review',
            'feedback-reports.show' => 'feedback_reports.review',
            'feedback-reports.review' => 'feedback_reports.review',
            'branches.index' => 'branches.manage',
            'projects.index' => 'projects.manage',
            'admin.system-health' => 'system_health.view',
        ];

        foreach ($expected as $routeName => $permission) {
            $middleware = Route::getRoutes()->getByName($routeName)?->gatherMiddleware() ?? [];
            $this->assertContains("permission:{$permission}", $middleware, $routeName);
            $this->assertFalse(collect($middleware)->contains(fn (string $value) => str_starts_with($value, 'permission:') && str_contains($value, ',')), $routeName);
        }
    }

    public function test_hidden_actions_remain_forbidden_by_backend_permissions(): void
    {
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);
        $manager = $this->user('manager', $branch);
        $staff = $this->user('staff', $branch);
        $sales = $this->user('sales_coordinator', $branch);
        $pusat = $this->user('pusat', $branch);

        $this->actingAs($manager)->postJson(route('database.sync'), ['branch_id' => $branch->id])->assertForbidden();
        $this->actingAs($staff)->postJson(route('konsumen-progress.sync'), ['branch_id' => $branch->id])->assertForbidden();
        $this->actingAs($sales)->get(route('sales-pocketbook.export'))->assertForbidden();
        $this->actingAs($sales)->get(route('content-calendar.export'))->assertForbidden();
        $this->actingAs($pusat)->get(route('expense-categories.index'))->assertForbidden();
    }

    public function test_supplemental_pusat_role_does_not_expose_routes_or_navigation_to_sales(): void
    {
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);
        $sales = $this->user('sales', $branch);
        $sales->roles()->attach(Role::query()->where('slug', 'pusat')->firstOrFail());

        $response = $this->actingAs($sales)->get(route('sales-pocketbook.index'))->assertOk();
        $response->assertDontSee(route('database.index'), false)
            ->assertDontSee(route('expenses.index'), false)
            ->assertDontSee(route('admin-users.index'), false);
        $this->actingAs($sales)->get(route('database.index'))->assertForbidden();
        $this->actingAs($sales)->get(route('expenses.index'))->assertForbidden();
    }

    public function test_branch_manager_expenses_and_categories_stay_forbidden(): void
    {
        $branch = $this->expenseBranch('Solo');
        $otherBranch = $this->expenseBranch('Pati');
        $category = $this->expenseCategory();
        $branchManager = $this->expenseUser('branch_manager', $branch);
        $otherCreator = $this->expenseUser('pusat', $otherBranch);
        $hidden = $this->expense(['branch' => $otherBranch, 'category' => $category, 'creator' => $otherCreator, 'description' => 'Biaya cabang lain']);

        $this->actingAs($branchManager)->get(route('expenses.index'))->assertForbidden();
        $this->actingAs($branchManager)->get(route('expenses.show', $hidden))->assertForbidden();
        $this->actingAs($branchManager)->get(route('expense-categories.index'))->assertForbidden();
    }

    public function test_admin_expense_routes_stay_forbidden(): void
    {
        $branch = $this->expenseBranch('Admin Expense Branch');
        $admin = $this->expenseUser('admin', $branch);

        foreach (['expenses.index', 'expenses.create', 'expenses.export', 'expense-categories.index'] as $routeName) {
            $this->actingAs($admin)->get(route($routeName))->assertForbidden();
        }
    }

    public function test_lead_source_show_keeps_redirect_behavior_and_controller_guard(): void
    {
        $source = LeadSource::create(['name' => 'Instagram', 'is_active' => true]);
        $admin = $this->user('admin');
        $superadmin = $this->user('superadmin');

        $this->withoutMiddleware(PermissionMiddleware::class);
        $this->actingAs($admin)->get(route('lead-sources.show', $source))->assertForbidden();
        $this->actingAs($superadmin)->get(route('lead-sources.show', $source))
            ->assertRedirect(route('lead-sources.edit', $source));
    }

    public function test_permission_access_changelog_is_idempotent_and_visible(): void
    {
        $title = 'Akses modul berdasarkan izin pengguna';
        $pusat = $this->user('pusat');

        $this->assertSame(1, Changelog::query()->whereNull('version')->where('title', $title)->count());
        $this->actingAs($pusat)->get(route('changelogs.index'))->assertOk()->assertSee($title);
    }

    private function user(string $role, ?Branch $branch = null): User
    {
        $user = User::factory()->create([
            'role_id' => Role::query()->where('slug', $role)->value('id'),
            'branch_id' => $branch?->id,
            'password_changed_at' => now(),
        ]);

        if ($role === 'sales') {
            app(SalesPocketbookOnboardingService::class)->skip($user);
        }

        return $user;
    }
}
