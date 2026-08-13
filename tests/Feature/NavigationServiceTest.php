<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\NavigationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class NavigationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_navigation_contains_only_allowlisted_workspaces(): void
    {
        $navigation = $this->navigationFor('sales', 'sales-pocketbook.index');

        $this->assertSame(['activities', 'sales'], array_column($navigation, 'key'));
        $this->assertSame(['Work Planner', 'Buku Saku Sales'], $this->labels($navigation));
        $this->assertNotContains('Dashboard', $this->labels($navigation));
        $this->assertNotContains('Database', $this->labels($navigation));
        $this->assertNotContains('Pengeluaran', $this->labels($navigation));
        $this->assertNotContains('Dana Talangan', $this->labels($navigation));
        $this->assertNotContains('User', $this->labels($navigation));
    }

    public function test_operational_roles_receive_only_groups_granted_by_primary_role_permissions(): void
    {
        $coordinator = $this->navigationFor('sales_coordinator');
        $branchManager = $this->navigationFor('branch_manager');
        $pusat = $this->navigationFor('pusat');

        $this->assertSame(['Dashboard', 'Work Planner', 'Buku Saku Sales', 'Changelog'], $this->labels($coordinator));
        $this->assertContains('Database', $this->labels($branchManager));
        $this->assertContains('Pengeluaran', $this->labels($branchManager));
        $this->assertContains('User', $this->labels($branchManager));
        $this->assertNotContains('Cabang', $this->labels($branchManager));
        $this->assertNotContains('Proyek', $this->labels($branchManager));
        $this->assertContains('Review Laporan', $this->labels($pusat));
        $this->assertNotContains('System Health', $this->labels($pusat));
    }

    public function test_superadmin_receives_every_safe_registered_destination_without_empty_groups(): void
    {
        $navigation = $this->navigationFor('superadmin');

        $this->assertSame(
            ['dashboard', 'activities', 'sales', 'operations', 'finance', 'reports', 'administration'],
            array_column($navigation, 'key'),
        );
        $this->assertContains('Cabang', $this->labels($navigation));
        $this->assertContains('Proyek', $this->labels($navigation));
        $this->assertContains('System Health', $this->labels($navigation));
        $this->assertContains('Maintenance', $this->labels($navigation));
        $this->assertContains('Design System', $this->labels($navigation));
        $this->assertNotContains('Lead Source', $this->labels($navigation));
        $this->assertNotContains([], array_column($navigation, 'children'));
    }

    public function test_supplemental_role_does_not_add_navigation_access(): void
    {
        $user = $this->user('sales');
        $user->roles()->attach(Role::query()->where('slug', 'superadmin')->firstOrFail());

        $navigation = app(NavigationService::class)->forUser($user->fresh('role.permissions'));

        $this->assertSame(['activities', 'sales'], array_column($navigation, 'key'));
        $this->assertNotContains('Administrasi', array_column($navigation, 'label'));
        $this->assertNotContains('Maintenance', $this->labels($navigation));
    }

    public function test_sales_fee_report_requires_primary_admin_and_sales_pocketbook_scope(): void
    {
        Route::get('/test-sales-fee-reports', fn () => null)->name('sales-fee-reports.index');

        $adminRole = Role::query()->where('slug', 'admin')->firstOrFail();
        $adminRole->permissions()->attach(Permission::query()->where('slug', 'sales_pocketbook.view_own')->firstOrFail());
        $admin = $this->user('admin');
        $supplementalAdmin = $this->user('sales_coordinator');
        $supplementalAdmin->roles()->attach(Role::query()->where('slug', 'admin')->firstOrFail());

        $adminNavigation = app(NavigationService::class)->forUser($admin, 'sales-fee-reports.index');
        $supplementalNavigation = app(NavigationService::class)->forUser($supplementalAdmin->fresh('role.permissions'));
        $reports = collect($adminNavigation)->firstWhere('key', 'reports');
        $feeReport = collect($reports['children'])->firstWhere('label', 'Laporan Fee Sales');

        $this->assertSame('sales-fee-reports.index', $feeReport['route']);
        $this->assertSame('report', $feeReport['icon']);
        $this->assertSame('reports', $feeReport['accent']);
        $this->assertSame(['sales-fee-reports.*'], $feeReport['active_patterns']);
        $this->assertTrue($feeReport['active']);
        $this->assertTrue($reports['active']);
        $this->assertNotContains('Review Laporan', $this->labels($adminNavigation));
        $this->assertNotContains('Laporan Fee Sales', $this->labels($supplementalNavigation));
    }

    public function test_current_route_marks_child_and_parent_active(): void
    {
        $navigation = $this->navigationFor('superadmin', 'kavlings.index');
        $administration = collect($navigation)->firstWhere('key', 'administration');
        $project = collect($administration['children'])->firstWhere('label', 'Proyek');

        $this->assertTrue($administration['active']);
        $this->assertTrue($project['active']);
        $this->assertContains('kavlings.*', $project['active_patterns']);
    }

    public function test_design_system_marks_administration_and_destination_active(): void
    {
        $navigation = $this->navigationFor('superadmin', 'admin.design-system');
        $administration = collect($navigation)->firstWhere('key', 'administration');
        $designSystem = collect($administration['children'])->firstWhere('label', 'Design System');

        $this->assertTrue($administration['active']);
        $this->assertTrue($designSystem['active']);
        $this->assertSame('admin.design-system', $designSystem['route']);
    }

    private function navigationFor(string $role, ?string $routeName = null): array
    {
        return app(NavigationService::class)->forUser($this->user($role), $routeName);
    }

    private function user(string $role): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $role)->value('id'),
            'password_changed_at' => now(),
        ])->load('role.permissions');
    }

    private function labels(array $navigation): array
    {
        return collect($navigation)->flatMap(fn (array $group) => array_column($group['children'], 'label'))->all();
    }
}
