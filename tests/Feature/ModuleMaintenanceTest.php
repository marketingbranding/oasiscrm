<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\ModuleMaintenance;
use App\Models\OperationalMaintenanceSetting;
use App\Models\Role;
use App\Models\User;
use App\Services\ModuleMaintenanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Tests\TestCase;

class ModuleMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_registry_has_exact_keys_and_unknown_key_is_rejected(): void
    {
        $expected = ['database', 'sales_pocketbook', 'promo', 'consumer_progress', 'dana_talangan', 'work_planner', 'feedback_reports', 'users', 'branches', 'projects', 'kavling'];
        $service = app(ModuleMaintenanceService::class);

        $this->assertSame($expected, array_keys($service->availableModules()));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown OASIS module [forged].');
        $service->status('forged');
    }

    public function test_all_modules_default_off_and_expired_estimate_does_not_disable_state(): void
    {
        $service = app(ModuleMaintenanceService::class);
        $this->assertNotContains(true, $service->enabledMap(), true);

        $this->setModule('database', true, estimatedEndAt: now()->subMinute());
        $this->assertTrue($service->isUnderMaintenance('database'));
    }

    public function test_html_and_json_503_contracts_cover_fallback_custom_and_safe_output(): void
    {
        $user = $this->user('staff');
        $this->setModule('database', true);

        $this->actingAs($user)->get(route('database.index'))
            ->assertServiceUnavailable()
            ->assertSee('Database sedang dalam pemeliharaan')
            ->assertSee('Modul ini sedang dalam pemeliharaan. Silakan coba kembali nanti.')
            ->assertDontSee($user->email)
            ->assertDontSee('service-account')
            ->assertDontSee('APP_KEY');

        $this->setModule('database', true, 'Pesan khusus aman.');
        $this->actingAs($user)->getJson(route('database.index'))->assertServiceUnavailable()->assertExactJson([
            'message' => 'Pesan khusus aman.',
            'maintenance' => true,
            'module' => 'database',
            'module_label' => 'Database',
            'estimated_end_at' => null,
        ]);
    }

    public function test_retry_after_exists_only_for_future_estimate(): void
    {
        Carbon::setTestNow('2026-08-14 10:00:00');
        $user = $this->user('staff');

        $this->setModule('database', true, estimatedEndAt: now()->addMinutes(5));
        $this->actingAs($user)->getJson(route('database.index'))->assertHeader('Retry-After', '300');

        foreach ([null, now()->subMinute()] as $estimate) {
            $this->setModule('database', true, estimatedEndAt: $estimate);
            $this->actingAs($user)->getJson(route('database.index'))->assertHeaderMissing('Retry-After');
        }
        Carbon::setTestNow();
    }

    public function test_primary_superadmin_bypasses_with_banner_and_all_normal_role_classes_are_blocked(): void
    {
        Route::get('/module-maintenance-role-probe', fn () => response('executed'))->middleware(['web', 'module.maintenance:promo'])->name('module-maintenance-probe');
        $this->setModule('promo', true, 'Promo berhenti sementara.');

        foreach (['sales', 'sales_coordinator', 'supervisor', 'manager', 'branch_manager', 'pusat', 'admin', 'staff'] as $role) {
            $this->actingAs($this->user($role))->get('/module-maintenance-role-probe')->assertServiceUnavailable();
        }

        $this->actingAs($this->user('superadmin'))->get(route('promos.index'))
            ->assertOk()
            ->assertSee('MODE MAINTENANCE MODUL AKTIF — Promo')
            ->assertSee('Promo berhenti sementara.');
    }

    public function test_generic_middleware_blocks_every_http_verb_before_endpoint_runs(): void
    {
        Route::match(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], '/module-maintenance-probe', fn () => response('executed'))
            ->middleware(['web', 'module.maintenance:database']);
        $this->setModule('database', true);
        $this->actingAs($this->user('staff'));

        foreach (['get', 'post', 'put', 'patch', 'delete'] as $method) {
            $this->{$method}('/module-maintenance-probe')->assertServiceUnavailable()->assertDontSee('executed');
        }
    }

    public function test_database_routes_have_contract_and_controller_short_circuits_before_google(): void
    {
        $names = ['database.index', 'database.consumer', 'database.sheet', 'database.sync', 'database.sync-status', 'database.records.store', 'database.records.update', 'database.records.destroy'];
        foreach ($names as $name) {
            $this->assertRouteModule($name, 'database');
        }

        $this->setModule('database', true);
        $staff = $this->user('staff');
        $branch = Branch::query()->create(['name' => 'Maintenance', 'code' => 'MNT', 'is_active' => true]);
        $this->actingAs($staff)->get(route('database.index'))->assertServiceUnavailable();
        $this->actingAs($staff)->getJson(route('database.consumer', ['branch' => $branch, 'record_id' => 1]))->assertServiceUnavailable();
    }

    public function test_promo_and_sales_pocketbook_representatives_and_every_registry_route_contract(): void
    {
        $this->assertRouteModule('promos.index', 'promo');
        $this->assertRouteModule('sales-pocketbook.index', 'sales_pocketbook');
        $this->setModule('promo', true);
        $this->actingAs($this->user('staff'))->get(route('promos.index'))->assertServiceUnavailable();
        $this->setModule('sales_pocketbook', true);
        $this->actingAs($this->user('sales'))->get(route('sales-pocketbook.index'))->assertServiceUnavailable();

        $matched = [];
        foreach (config('oasis_modules') as $key => $module) {
            foreach (Route::getRoutes() as $route) {
                $name = $route->getName();
                if ($name && collect($module['route_patterns'])->contains(fn (string $pattern) => str($name)->is($pattern))) {
                    $this->assertRouteModule($name, $key);
                    $matched[$name] = true;
                }
            }
        }
        $this->assertCount(154, $matched);
    }

    public function test_other_modules_remain_accessible_two_can_run_and_disable_is_independent(): void
    {
        $superadmin = $this->user('superadmin');
        $service = app(ModuleMaintenanceService::class);
        $service->enable('database', $superadmin, []);
        $service->enable('promo', $superadmin, []);

        $this->assertTrue($service->status('database')['is_enabled']);
        $this->assertTrue($service->status('promo')['is_enabled']);
        $this->actingAs($this->user('staff'))->get(route('changelogs.index'))->assertOk();

        $service->disable('database', $superadmin);
        $this->assertFalse($service->status('database')['is_enabled']);
        $this->assertTrue($service->status('promo')['is_enabled']);
    }

    public function test_only_primary_superadmin_writes_and_input_validation_rejects_forgery(): void
    {
        $staff = $this->user('staff');
        $staff->roles()->attach(Role::where('slug', 'superadmin')->firstOrFail());

        $this->actingAs($staff)->put(route('admin.maintenance.modules.enable', 'database'), [])->assertForbidden();
        $this->actingAs($this->user('superadmin'))->put(route('admin.maintenance.modules.enable', 'forged'), [])->assertNotFound();
        $this->actingAs($this->user('superadmin'))->from(route('admin.maintenance.index'))
            ->put(route('admin.maintenance.modules.enable', 'database'), [
                'message' => str_repeat('x', 1001),
                'estimated_end_at' => now()->subMinute()->format('Y-m-d H:i:s'),
            ])->assertSessionHasErrors(['message', 'estimated_end_at']);
        $this->assertDatabaseMissing('module_maintenances', ['module_key' => 'database', 'is_enabled' => true]);
    }

    public function test_enable_update_disable_audit_is_exact_and_cache_invalidates_immediately(): void
    {
        $actor = $this->user('superadmin');
        $service = app(ModuleMaintenanceService::class);
        $this->assertFalse($service->status('database')['is_enabled']);

        $service->enable('database', $actor, ['message' => 'Awal']);
        $this->assertTrue($service->status('database')['is_enabled']);
        $service->update('database', $actor, ['message' => 'Baru']);
        $this->assertSame('Baru', $service->status('database')['message']);
        $service->disable('database', $actor);
        $this->assertFalse($service->status('database')['is_enabled']);

        $logs = ActivityLog::where('subject_type', ModuleMaintenance::class)->orderBy('id')->get();
        $this->assertSame(['module_maintenance_enabled', 'module_maintenance_updated', 'module_maintenance_disabled'], $logs->pluck('event')->all());
        foreach ($logs as $log) {
            $this->assertSame($actor->id, $log->causer_id);
            $this->assertSame('database', $log->properties['module_key']);
            $this->assertSame('Database', $log->properties['module_label']);
            $this->assertSame($actor->id, $log->properties['actor_id']);
            $this->assertArrayNotHasKey('email', $log->properties);
        }
        $this->assertSame('Baru', $logs[1]->properties['message']);
        $this->assertFalse(Cache::has('oasis.module_maintenance.database'));
    }

    public function test_global_maintenance_has_higher_priority_and_off_state_preserves_permission_behavior(): void
    {
        $staff = $this->user('staff');
        $this->setModule('database', true, 'Module response');
        OperationalMaintenanceSetting::whereKey(OperationalMaintenanceSetting::GLOBAL_ID)->update([
            'enabled' => true,
            'message' => 'Global response',
            'enabled_at' => now(),
        ]);

        $this->actingAs($staff)->getJson(route('database.index'))->assertExactJson([
            'message' => 'OASIS sedang dalam pemeliharaan.',
            'maintenance' => true,
            'estimated_end_at' => null,
        ]);

        OperationalMaintenanceSetting::whereKey(OperationalMaintenanceSetting::GLOBAL_ID)->update(['enabled' => false]);
        $this->setModule('database', false);
        $this->fakeGoogleSheets();
        $this->actingAs($staff)->get(route('database.index'))->assertOk();
        $this->actingAs($this->user('superadmin'))->get(route('admin.maintenance.index'))->assertOk();
    }

    public function test_management_routes_are_never_module_blocked(): void
    {
        foreach (['admin.maintenance.index', 'admin.maintenance.enable', 'admin.maintenance.disable', 'admin.maintenance.modules.enable', 'admin.maintenance.modules.update', 'admin.maintenance.modules.disable'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route);
            $this->assertFalse(collect($route->gatherMiddleware())->contains(fn (string $middleware) => str_starts_with($middleware, 'module.maintenance:')), $name);
        }
    }

    private function assertRouteModule(string $name, string $key): void
    {
        $route = Route::getRoutes()->getByName($name);
        $this->assertNotNull($route, $name);
        $middleware = $route->gatherMiddleware();
        $module = "module.maintenance:{$key}";
        $this->assertContains($module, $middleware, $name);
        $this->assertLessThan(array_search($module, $middleware, true), array_search('operational.maintenance', $middleware, true), $name);
    }

    private function setModule(string $key, bool $enabled, ?string $message = null, $estimatedEndAt = null): void
    {
        ModuleMaintenance::updateOrCreate(['module_key' => $key], [
            'is_enabled' => $enabled,
            'message' => $message,
            'estimated_end_at' => $estimatedEndAt,
            'started_at' => $enabled ? now() : null,
        ]);
        Cache::forget('oasis.module_maintenance.all');
    }

    private function user(string $role): User
    {
        return User::factory()->create([
            'role_id' => Role::where('slug', $role)->firstOrFail()->id,
            'account_status' => AccountStatus::Active,
            'is_active' => true,
            'email_verified_at' => now(),
            'must_change_password' => false,
            'password_changed_at' => now(),
        ]);
    }
}
