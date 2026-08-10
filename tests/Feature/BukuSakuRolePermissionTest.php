<?php

namespace Tests\Feature;

use App\Http\Controllers\Crm\SupervisorSalesPocketbookController;
use App\Models\Changelog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Mockery;
use Tests\TestCase;

class BukuSakuRolePermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_and_coordinator_receive_deliberate_buku_saku_permissions(): void
    {
        $sales = User::factory()->create(['role_id' => Role::query()->where('slug', 'sales')->value('id')]);
        $coordinator = User::factory()->create(['role_id' => Role::query()->where('slug', 'sales_coordinator')->value('id')]);

        $this->assertTrue($sales->hasAllPermissions([
            'sales_pocketbook.view_own',
            'sales_pocketbook.manage_own',
            'sales_pocketbook.export_own',
            'sales_pocketbook.export',
        ]));
        $this->assertFalse($sales->hasPermission('sales_pocketbook.sync'));
        $this->assertFalse($sales->hasPermission('sales_pocketbook.view_team'));

        $this->assertTrue($coordinator->hasAllPermissions([
            'sales_pocketbook.view_team',
            'sales_pocketbook.manage_team',
            'sales_pocketbook.export_team',
            'sales_pocketbook.export',
            'sales_pocketbook.sync',
        ]));
        $this->assertFalse($coordinator->hasPermission('sales_pocketbook.reconcile'));
    }

    public function test_landing_uses_primary_role_and_ignores_supplemental_roles(): void
    {
        $salesRole = Role::query()->where('slug', 'sales')->firstOrFail();
        $coordinatorRole = Role::query()->where('slug', 'sales_coordinator')->firstOrFail();
        $supervisorRole = Role::query()->where('slug', 'supervisor')->firstOrFail();
        $staffRole = Role::query()->where('slug', 'staff')->firstOrFail();

        $sales = User::factory()->create(['role_id' => $salesRole->id]);
        $coordinator = User::factory()->create(['role_id' => $coordinatorRole->id]);
        $supervisor = User::factory()->create(['role_id' => $supervisorRole->id]);
        $staff = User::factory()->create(['role_id' => $staffRole->id]);
        $staff->roles()->attach([$coordinatorRole->id, $supervisorRole->id]);

        $this->assertSame('sales-pocketbook.index', $sales->landingRouteName());
        $this->assertSame('sales-pocketbook.index', $coordinator->landingRouteName());
        $this->assertSame('sales-pocketbook.index', $supervisor->landingRouteName());
        $this->assertSame('dashboard', $staff->landingRouteName());
    }

    public function test_shared_pocketbook_dispatches_only_primary_supervisor(): void
    {
        $supervisorRole = Role::query()->where('slug', 'supervisor')->firstOrFail();
        $staffRole = Role::query()->where('slug', 'staff')->firstOrFail();
        $supervisor = User::factory()->create(['role_id' => $supervisorRole->id, 'password_changed_at' => now()]);
        $staff = User::factory()->create(['role_id' => $staffRole->id, 'password_changed_at' => now()]);
        $staff->roles()->attach($supervisorRole);

        $controller = $this->mock(SupervisorSalesPocketbookController::class);
        $controller->shouldReceive('index')->once()->with(Mockery::type(Request::class))->andReturn(view('auth.login'));

        $this->actingAs($supervisor)->get(route('sales-pocketbook.index'))->assertOk();
        $this->actingAs($staff)->get(route('sales-pocketbook.index'))->assertForbidden();
    }

    public function test_supervisor_export_routes_are_static_and_use_export_permission_middleware(): void
    {
        foreach ([
            'sales-pocketbook.supervisor-monitoring.agenda-export',
            'sales-pocketbook.supervisor-monitoring.lead-export',
        ] as $name) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route);
            $this->assertContains('permission:sales_pocketbook.export', $route->gatherMiddleware());
        }
    }

    public function test_permission_description_and_changelog_are_deployed_once(): void
    {
        $this->assertDatabaseHas('permissions', [
            'slug' => 'sales_pocketbook.sync',
            'description' => 'Mendorong lead tim Buku Saku Sales ke spreadsheet cabang sesuai lingkup akses.',
        ]);
        $this->assertSame(1, Changelog::query()
            ->whereNull('version')
            ->where('title', 'Buku Saku Role Workspace & Local-First Lead')
            ->where('category', 'changed')
            ->whereNull('created_by')
            ->count());
    }
}
