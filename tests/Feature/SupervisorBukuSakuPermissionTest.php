<?php

namespace Tests\Feature;

use App\Models\Changelog;
use App\Models\Role;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SupervisorBukuSakuPermissionTest extends TestCase
{
    use RefreshDatabase;

    private const REQUIRED = [
        'sales_pocketbook.view_team',
        'sales_pocketbook.view_assigned',
        'sales_pocketbook.export_team',
        'sales_pocketbook.export_assigned',
        'sales_pocketbook.export',
    ];

    private const FORBIDDEN = [
        'sales_pocketbook.view_own',
        'sales_pocketbook.manage_own',
        'sales_pocketbook.manage_team',
        'sales_pocketbook.manage_assigned',
        'sales_pocketbook.export_own',
        'sales_pocketbook.sync',
        'sales_pocketbook.reconcile',
    ];

    public function test_catalog_and_deployed_supervisor_mapping_are_monitoring_only(): void
    {
        $catalog = PermissionCatalog::rolePermissions()['supervisor'];
        $supervisor = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'supervisor')->value('id'),
        ]);

        foreach (self::REQUIRED as $slug) {
            $this->assertContains($slug, $catalog);
            $this->assertTrue($supervisor->hasPermission($slug), $slug);
        }

        foreach (self::FORBIDDEN as $slug) {
            $this->assertNotContains($slug, $catalog);
            $this->assertFalse($supervisor->hasPermission($slug), $slug);
        }
    }

    public function test_supplemental_supervisor_role_does_not_escalate_permissions(): void
    {
        $staff = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'staff')->value('id'),
        ]);
        $staff->roles()->attach(Role::query()->where('slug', 'supervisor')->firstOrFail());

        foreach (self::REQUIRED as $slug) {
            $this->assertFalse($staff->hasPermission($slug), $slug);
        }
    }

    public function test_supervisor_work_planner_management_is_retained(): void
    {
        $supervisor = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'supervisor')->value('id'),
        ]);

        $this->assertTrue($supervisor->hasAllPermissions([
            'work_planner.manage_own',
            'work_planner.manage_team',
            'work_planner.manage_assigned',
            'work_planner.create',
            'work_planner.update',
            'work_planner.assign',
            'work_planner.export',
        ]));
    }

    public function test_migration_is_idempotent_and_changelog_renders_once(): void
    {
        $migration = require database_path('migrations/2026_08_10_000004_harden_supervisor_buku_saku_permissions.php');
        $migration->up();
        $migration->up();

        $title = 'Buku Saku Supervisor Monitoring';
        $this->assertSame(1, Changelog::query()->whereNull('version')->where('title', $title)->count());

        $superadmin = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'superadmin')->value('id'),
            'password_changed_at' => now(),
        ]);

        $this->actingAs($superadmin)->get(route('changelogs.index'))->assertOk()->assertSeeText($title);

        $roleId = Role::query()->where('slug', 'supervisor')->value('id');
        $workPlannerCount = DB::table('role_permission')
            ->join('permissions', 'permissions.id', '=', 'role_permission.permission_id')
            ->where('role_permission.role_id', $roleId)
            ->where('permissions.slug', 'like', 'work_planner.%')
            ->count();
        $migration->up();
        $this->assertSame($workPlannerCount, DB::table('role_permission')
            ->join('permissions', 'permissions.id', '=', 'role_permission.permission_id')
            ->where('role_permission.role_id', $roleId)
            ->where('permissions.slug', 'like', 'work_planner.%')
            ->count());
    }
}
