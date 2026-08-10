<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Changelog;
use App\Models\OperationalMaintenanceSetting;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\OperationalMaintenanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class OperationalMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_can_open_management_page_and_normal_user_cannot(): void
    {
        $superadmin = $this->user('superadmin');
        $staff = $this->user('staff');

        $this->actingAs($superadmin)
            ->get(route('admin.maintenance.index'))
            ->assertOk()
            ->assertSee('Full Maintenance Mode')
            ->assertSee('Aktifkan maintenance');

        $this->actingAs($staff)->get(route('admin.maintenance.index'))->assertForbidden();
    }

    public function test_enable_and_disable_are_versioned_and_audited(): void
    {
        $superadmin = $this->user('superadmin');
        $setting = OperationalMaintenanceSetting::query()->findOrFail(OperationalMaintenanceSetting::GLOBAL_ID);

        $this->actingAs($superadmin)->put(route('admin.maintenance.enable'), [
            'title' => 'Pemeliharaan terjadwal',
            'message' => 'OASIS sedang diperbarui. Silakan kembali setelah waktu perkiraan.',
            'estimated_end_at' => now()->addHour()->format('Y-m-d H:i:s'),
            'lock_version' => $setting->lock_version,
            'confirmation' => 'AKTIFKAN MAINTENANCE',
            'maintenance_action' => 'enable',
        ])->assertRedirect(route('admin.maintenance.index'));

        $enabled = $setting->fresh();
        $this->assertTrue($enabled->enabled);
        $this->assertSame(1, $enabled->lock_version);
        $this->assertSame($superadmin->id, $enabled->enabled_by);
        $this->assertDatabaseHas('activity_log', [
            'causer_id' => $superadmin->id,
            'event' => 'operational_maintenance_enabled',
        ]);

        $this->actingAs($superadmin)->put(route('admin.maintenance.disable'), [
            'lock_version' => $enabled->lock_version,
            'confirmation' => 'NONAKTIFKAN MAINTENANCE',
            'maintenance_action' => 'disable',
        ])->assertRedirect(route('admin.maintenance.index'));

        $disabled = $enabled->fresh();
        $this->assertFalse($disabled->enabled);
        $this->assertSame(2, $disabled->lock_version);
        $this->assertSame($superadmin->id, $disabled->disabled_by);

        $log = ActivityLog::query()->where('event', 'operational_maintenance_disabled')->firstOrFail();
        $this->assertSame($superadmin->id, $log->properties['actor_user_id']);
        $this->assertArrayHasKey('duration_seconds', $log->properties);
        $this->assertArrayNotHasKey('email', $log->properties);
    }

    public function test_stale_or_invalid_activation_cannot_change_state(): void
    {
        $superadmin = $this->user('superadmin');
        $setting = OperationalMaintenanceSetting::query()->findOrFail(OperationalMaintenanceSetting::GLOBAL_ID);
        $payload = [
            'title' => 'Pemeliharaan',
            'message' => 'Pesan pemeliharaan.',
            'estimated_end_at' => now()->subMinute()->format('Y-m-d H:i:s'),
            'lock_version' => $setting->lock_version + 1,
            'confirmation' => 'SALAH',
            'maintenance_action' => 'enable',
        ];

        $this->actingAs($superadmin)->from(route('admin.maintenance.index'))
            ->put(route('admin.maintenance.enable'), $payload)
            ->assertRedirect(route('admin.maintenance.index'))
            ->assertSessionHasErrors(['estimated_end_at', 'confirmation']);

        $payload['estimated_end_at'] = now()->addHour()->format('Y-m-d H:i:s');
        $payload['confirmation'] = 'AKTIFKAN MAINTENANCE';
        $this->actingAs($superadmin)->from(route('admin.maintenance.index'))
            ->put(route('admin.maintenance.enable'), $payload)
            ->assertSessionHasErrors('lock_version');

        $this->assertFalse($setting->fresh()->enabled);
        $this->assertDatabaseMissing('activity_log', ['event' => 'operational_maintenance_enabled']);
    }

    public function test_stale_disable_cannot_change_active_state(): void
    {
        $superadmin = $this->user('superadmin');
        $this->activateDirectly();
        $setting = OperationalMaintenanceSetting::query()->findOrFail(OperationalMaintenanceSetting::GLOBAL_ID);

        $this->actingAs($superadmin)->from(route('admin.maintenance.index'))
            ->put(route('admin.maintenance.disable'), [
                'lock_version' => $setting->lock_version + 1,
                'confirmation' => 'NONAKTIFKAN MAINTENANCE',
                'maintenance_action' => 'disable',
            ])
            ->assertSessionHasErrors('lock_version');

        $this->assertTrue($setting->fresh()->enabled);
        $this->assertDatabaseMissing('activity_log', ['event' => 'operational_maintenance_disabled']);
    }

    public function test_manage_permission_without_primary_bypass_cannot_activate_maintenance(): void
    {
        $role = Role::query()->create([
            'name' => 'Maintenance Manager',
            'slug' => 'maintenance_manager',
            'is_active' => true,
            'is_superadmin' => false,
        ]);
        $role->permissions()->attach(Permission::query()->where('slug', 'system.maintenance_manage')->firstOrFail());
        $manager = User::factory()->create([
            'role_id' => $role->id,
            'password_changed_at' => now(),
        ]);
        $setting = OperationalMaintenanceSetting::query()->findOrFail(OperationalMaintenanceSetting::GLOBAL_ID);

        $this->actingAs($manager)->put(route('admin.maintenance.enable'), [
            'title' => 'Pemeliharaan',
            'message' => 'Pesan pemeliharaan.',
            'lock_version' => $setting->lock_version,
            'confirmation' => 'AKTIFKAN MAINTENANCE',
            'maintenance_action' => 'enable',
        ])->assertForbidden();

        $this->assertFalse($setting->fresh()->enabled);
    }

    public function test_normal_user_receives_standalone_html_503_and_write_is_not_executed(): void
    {
        $staff = $this->user('staff');
        $this->activateDirectly();
        $originalName = $staff->name;

        $this->actingAs($staff)->get(route('profile.edit'))
            ->assertServiceUnavailable()
            ->assertSee('OASIS / Sistem Operasional')
            ->assertSee('HTTP 503')
            ->assertSee('Keluar dari OASIS')
            ->assertDontSee('vite/client', false)
            ->assertDontSee('presence/heartbeat', false)
            ->assertDontSee('notifications', false);

        $this->actingAs($staff)->patch(route('profile.update'), [
            'name' => 'Nama yang tidak boleh tersimpan',
            'email' => $staff->email,
        ])->assertServiceUnavailable();

        $this->assertSame($originalName, $staff->fresh()->name);
        $this->assertDatabaseCount('activity_log', 0);
    }

    public function test_json_response_has_stable_contract_and_retry_after(): void
    {
        $staff = $this->user('staff');
        $estimatedEnd = now()->addMinutes(10)->startOfSecond();
        $this->activateDirectly($estimatedEnd);

        $response = $this->actingAs($staff)->getJson(route('notifications.index'))
            ->assertServiceUnavailable()
            ->assertExactJson([
                'message' => 'OASIS sedang dalam pemeliharaan.',
                'maintenance' => true,
                'estimated_end_at' => $estimatedEnd->toIso8601String(),
            ]);

        $this->assertGreaterThanOrEqual(60, (int) $response->headers->get('Retry-After'));
        $this->assertLessThanOrEqual(600, (int) $response->headers->get('Retry-After'));
    }

    public function test_primary_pusat_and_superadmin_bypass_but_supplemental_roles_do_not(): void
    {
        $pusat = $this->user('pusat');
        $superadmin = $this->user('superadmin');
        $staff = $this->user('staff');
        $staff->roles()->attach(Role::query()->where('slug', 'superadmin')->firstOrFail());
        $this->activateDirectly();

        $this->actingAs($pusat)->get(route('profile.edit'))->assertOk();
        $this->actingAs($superadmin)->get(route('admin.maintenance.index'))->assertOk();
        $this->actingAs($staff->fresh())->get(route('profile.edit'))->assertServiceUnavailable();
    }

    public function test_authentication_lifecycle_routes_remain_available(): void
    {
        $user = $this->user('staff');
        $user->update(['password_changed_at' => null, 'must_change_password' => true]);
        $this->activateDirectly();

        $this->get(route('login'))->assertOk()->assertDontSee('HTTP 503');
        $this->actingAs($user)->get(route('password.change'))->assertOk()->assertDontSee('HTTP 503');
        $this->actingAs($user)->post(route('logout'))->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_missing_singleton_fails_open_and_logs_sanitized_context(): void
    {
        $staff = $this->user('staff');
        OperationalMaintenanceSetting::query()->delete();
        Log::spy();

        $this->actingAs($staff)->get(route('profile.edit'))->assertOk();

        Log::shouldHaveReceived('error')->once()->with(
            'Operational maintenance singleton is missing; access remains open.',
            ['operation' => 'operational_maintenance_read'],
        );
    }

    public function test_malformed_public_timestamp_fails_open(): void
    {
        $staff = $this->user('staff');
        $this->activateDirectly();
        OperationalMaintenanceSetting::query()->whereKey(OperationalMaintenanceSetting::GLOBAL_ID)->update([
            'estimated_end_at' => 'not-a-date',
        ]);
        Log::spy();

        $this->actingAs($staff)->get(route('profile.edit'))->assertOk();

        Log::shouldHaveReceived('error')->once()->withArgs(
            fn (string $message, array $context) => $message === 'Operational maintenance public data could not be read; access remains open.'
                && $context['operation'] === 'operational_maintenance_public_data'
                && isset($context['exception']),
        );
    }

    public function test_existing_session_recovers_access_immediately_after_disable(): void
    {
        $staff = $this->user('staff');
        $superadmin = $this->user('superadmin');
        $this->activateDirectly();
        $setting = OperationalMaintenanceSetting::query()->findOrFail(OperationalMaintenanceSetting::GLOBAL_ID);

        $this->actingAs($staff)->get(route('profile.edit'))->assertServiceUnavailable();
        app(OperationalMaintenanceService::class)->disable($superadmin, $setting->lock_version);
        $this->get(route('profile.edit'))->assertOk();
        $this->assertAuthenticatedAs($staff);
    }

    public function test_changelog_is_deployed_once_and_rendered(): void
    {
        $title = 'Full Maintenance Mode untuk pemeliharaan OASIS';
        $pusat = $this->user('pusat');

        $this->assertSame(1, Changelog::query()->whereNull('version')->where('title', $title)->count());
        $this->actingAs($pusat)->get(route('changelogs.index'))->assertOk()->assertSee($title);
    }

    private function user(string $roleSlug): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $roleSlug)->value('id'),
            'password_changed_at' => now(),
            'account_status' => 'active',
        ]);
    }

    private function activateDirectly($estimatedEndAt = null): void
    {
        OperationalMaintenanceSetting::query()
            ->whereKey(OperationalMaintenanceSetting::GLOBAL_ID)
            ->update([
                'enabled' => true,
                'estimated_end_at' => $estimatedEndAt,
                'enabled_at' => now(),
            ]);
    }
}
