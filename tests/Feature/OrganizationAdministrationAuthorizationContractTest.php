<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Models\Branch;
use App\Models\LeadMaster;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\NavigationService;
use App\Services\SystemAdministrationAuthorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class OrganizationAdministrationAuthorizationContractTest extends TestCase
{
    use RefreshDatabase;

    private const PRIMARY_ROLES = [
        'sales',
        'sales_coordinator',
        'supervisor',
        'manager',
        'branch_manager',
        'sas',
        'sam',
        'pusat',
        'admin',
        'staff',
        'superadmin',
    ];

    public function test_non_superadmin_primary_roles_are_denied_system_administration_routes(): void
    {
        $branch = $this->branch('SLO');
        $project = $this->project($branch);

        foreach (array_diff(self::PRIMARY_ROLES, ['superadmin']) as $roleSlug) {
            $actor = $this->user($roleSlug, $branch);

            $this->assertForbiddenFor($actor, route('branches.index'), $roleSlug);
            $this->assertForbiddenFor($actor, route('projects.index'), $roleSlug);
            $this->assertForbiddenFor($actor, route('kavlings.index', $project), $roleSlug);
            $this->assertForbiddenFor($actor, route('roles.index'), $roleSlug);
        }
    }

    public function test_primary_superadmin_can_enter_system_and_scoped_administration_destinations(): void
    {
        $branch = $this->branch('SLO');
        $project = $this->project($branch);
        $actor = $this->user('superadmin', $branch);

        $this->actingAs($actor)->get(route('branches.index'))->assertOk();
        $this->actingAs($actor)->get(route('projects.index'))->assertOk();
        $this->actingAs($actor)->get(route('kavlings.index', $project))->assertOk();
        $this->actingAs($actor)->get(route('roles.index'))->assertOk();
        $this->actingAs($actor)->get(route('admin-users.index'))->assertOk();
        $this->actingAs($actor)->get(route('organization.index'))->assertOk();
    }

    public function test_seeded_operational_roles_retain_their_existing_user_and_organization_entry_points(): void
    {
        $branch = $this->branch('SLO');

        foreach (['branch_manager', 'pusat', 'admin'] as $roleSlug) {
            $actor = $this->user($roleSlug, $branch);
            $this->actingAs($actor)->get(route('admin-users.index'))->assertOk();
        }

        foreach (['branch_manager', 'pusat'] as $roleSlug) {
            $actor = $this->user($roleSlug, $branch);
            $this->actingAs($actor)->get(route('organization.index'))->assertOk();
        }

        $this->actingAs($this->user('admin', $branch))->get(route('organization.index'))->assertForbidden();
    }

    public function test_supplemental_superadmin_role_does_not_escalate_primary_access(): void
    {
        $branch = $this->branch('SLO');
        $actor = $this->user('sales', $branch);
        $actor->roles()->attach(Role::query()->where('slug', 'superadmin')->firstOrFail());
        $actor->refresh();

        $this->assertForbiddenFor($actor, route('branches.index'), 'supplemental superadmin');
        $this->assertForbiddenFor($actor, route('projects.index'), 'supplemental superadmin');
        $this->assertForbiddenFor($actor, route('roles.index'), 'supplemental superadmin');
        $this->actingAs($actor)->get(route('admin-users.index'))->assertForbidden();
        $this->actingAs($actor)->get(route('organization.index'))->assertForbidden();
    }

    public function test_non_superadmin_role_permissions_do_not_grant_system_role_administration(): void
    {
        $branch = $this->branch('SLO');
        $role = Role::query()->create([
            'name' => 'System Permission Test',
            'slug' => 'system_permission_test',
            'authority_level' => 10,
            'is_active' => true,
            'is_superadmin' => false,
        ]);
        $role->permissions()->sync(Permission::query()
            ->whereIn('slug', ['roles.view', 'roles.create', 'roles.update', 'roles.assign_permissions'])
            ->pluck('id'));
        $actor = $this->userWithRole($role, $branch);

        $this->actingAs($actor)->get(route('roles.index'))->assertForbidden();
        $labels = collect(app(NavigationService::class)->forUser($actor))->flatMap(
            fn (array $group): array => array_column($group['children'], 'label'),
        );

        $this->assertNotContains('Peran dan Izin', $labels->all());
    }

    public function test_operational_management_permissions_do_not_grant_branch_project_or_kavling_administration(): void
    {
        $branch = $this->branch('SLO');
        $project = $this->project($branch);
        $role = Role::query()->create([
            'name' => 'Operational Permission Test',
            'slug' => 'operational_permission_test',
            'authority_level' => 10,
            'is_active' => true,
            'is_superadmin' => false,
        ]);
        $role->permissions()->sync(Permission::query()
            ->whereIn('slug', ['branches.manage', 'projects.manage'])
            ->pluck('id'));
        $actor = $this->userWithRole($role, $branch);

        $this->actingAs($actor)->get(route('branches.index'))->assertForbidden();
        $this->actingAs($actor)->get(route('projects.index'))->assertForbidden();
        $this->actingAs($actor)->get(route('kavlings.index', $project))->assertForbidden();
        $this->actingAs($actor)->put(route('branches.update', $branch), ['name' => 'Tidak Sah'])->assertForbidden();
        $this->actingAs($actor)->post(route('kavlings.bulk-store', $project), ['list' => "Blok-A-1\n"])->assertForbidden();

        $this->assertDatabaseCount('kavlings', 0);
        $this->assertSame('SLO', $branch->fresh()->name);
    }

    public function test_system_administration_owner_allows_only_active_primary_superadmin_areas(): void
    {
        $branch = $this->branch('SLO');
        $service = app(SystemAdministrationAuthorizationService::class);
        $superadmin = $this->user('superadmin', $branch);
        $pusat = $this->user('pusat', $branch);
        $inactive = $this->user('superadmin', $branch);
        $inactive->forceFill(['is_active' => false, 'account_status' => AccountStatus::Inactive])->saveQuietly();

        foreach (['branches', 'projects', 'kavlings', 'roles', 'permissions'] as $area) {
            $this->assertTrue($service->canManage($superadmin, $area), $area);
            $this->assertFalse($service->canManage($pusat, $area), $area);
            $this->assertFalse($service->canManage($inactive, $area), $area);
        }

        $this->assertFalse($service->canManage($superadmin, 'unknown'), 'unknown area');
    }

    public function test_phase_1_authorization_changelog_is_idempotent_and_visible(): void
    {
        $title = 'Pemisahan Otorisasi Administrasi Sistem dan Operasional';
        $migration = require database_path('migrations/2026_10_30_000001_add_phase_1_authorization_changelog.php');

        $migration->up();
        $migration->up();

        $this->assertSame(1, \DB::table('changelogs')->whereNull('version')->where('title', $title)->count());
        $this->actingAs($this->user('superadmin'))->get(route('changelogs.index'))
            ->assertOk()
            ->assertSeeText($title);
    }

    private function assertForbiddenFor(User $actor, string $url, string $context): TestResponse
    {
        return $this->actingAs($actor)->get($url)->assertForbidden("Unexpected access for {$context}.");
    }

    private function branch(string $code): Branch
    {
        return Branch::query()->create([
            'name' => $code,
            'code' => $code,
            'is_active' => true,
        ]);
    }

    private function project(Branch $branch): LeadMaster
    {
        return LeadMaster::query()->create([
            'branch_id' => $branch->id,
            'project_name' => 'Proyek '.$branch->code,
            'is_active' => true,
        ]);
    }

    private function user(string $roleSlug, ?Branch $branch = null): User
    {
        return $this->userWithRole(Role::query()->where('slug', $roleSlug)->firstOrFail(), $branch);
    }

    private function userWithRole(Role $role, ?Branch $branch = null): User
    {
        return User::factory()->create([
            'role_id' => $role->id,
            'branch_id' => $branch?->id,
            'is_active' => true,
            'account_status' => AccountStatus::Active,
            'email_verified_at' => now(),
            'password_changed_at' => now(),
        ]);
    }
}
