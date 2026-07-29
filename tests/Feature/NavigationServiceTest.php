<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\NavigationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
