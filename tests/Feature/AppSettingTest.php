<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\User;
use App\Services\AppSettingResolver;
use App\Services\AppSettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AppSettingTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $roleSlug): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $roleSlug)->value('id'),
            'password_changed_at' => now(),
            'account_status' => 'active',
        ]);
    }

    public function test_superadmin_can_view_and_update_prd_v3_settings(): void
    {
        $superadmin = $this->user('superadmin');

        $this->actingAs($superadmin)
            ->get(route('admin.app-settings.index'))
            ->assertOk()
            ->assertSee('Pengaturan Aturan')
            ->assertSee('Jeda pengingat kegiatan');

        $this->actingAs($superadmin)
            ->put(route('admin.app-settings.update'), [
                'sales_agenda.reminder_lead_minutes' => 20,
                'sales_monitoring.stale_lead_days' => 5,
                'org_hierarchical_monitoring' => true,
            ])
            ->assertRedirect(route('admin.app-settings.index'));

        $this->assertSame(20, app(AppSettingResolver::class)->int('sales_agenda.reminder_lead_minutes'));
        $this->assertSame(5, app(AppSettingResolver::class)->int('sales_monitoring.stale_lead_days'));
        $this->assertTrue(app(AppSettingResolver::class)->bool('org_hierarchical_monitoring'));
    }

    public function test_br52_non_superadmin_cannot_read_or_update_settings(): void
    {
        $staff = $this->user('staff');

        $this->actingAs($staff)->get(route('admin.app-settings.index'))->assertForbidden();
        $this->actingAs($staff)->put(route('admin.app-settings.update'), [
            'org_hierarchical_monitoring' => true,
        ])->assertForbidden();
    }

    public function test_supplemental_role_does_not_grant_settings_access(): void
    {
        $staff = $this->user('staff');
        $superadminRole = Role::query()->where('slug', 'superadmin')->firstOrFail();
        $staff->roles()->attach($superadminRole);

        $this->actingAs($staff)->get(route('admin.app-settings.index'))->assertForbidden();
    }

    public function test_invalid_or_unknown_values_do_not_change_settings(): void
    {
        $superadmin = $this->user('superadmin');
        $resolver = app(AppSettingResolver::class);
        $this->assertSame(15, $resolver->int('sales_agenda.reminder_lead_minutes'));

        $this->actingAs($superadmin)
            ->from(route('admin.app-settings.index'))
            ->put(route('admin.app-settings.update'), [
                'sales_agenda.reminder_lead_minutes' => 0,
                'unknown.setting' => 'bad',
            ])
            ->assertRedirect(route('admin.app-settings.index'))
            ->assertSessionHasErrors(['sales_agenda.reminder_lead_minutes', 'unknown.setting']);

        $this->assertSame(15, $resolver->int('sales_agenda.reminder_lead_minutes'));
        $this->assertDatabaseCount('activity_log', 0);
    }

    public function test_service_rejects_out_of_range_values_before_database_write(): void
    {
        $superadmin = $this->user('superadmin');

        $this->expectException(ValidationException::class);

        app(AppSettingService::class)->update($superadmin, [
            'sales_agenda.reminder_lead_minutes' => 0,
        ]);
    }

    public function test_each_changed_setting_writes_audited_value_without_contact_fields(): void
    {
        $superadmin = $this->user('superadmin');

        $this->actingAs($superadmin)->put(route('admin.app-settings.update'), [
            'sales_agenda.reminder_lead_minutes' => 20,
            'sales_monitoring.stale_lead_days' => 5,
        ])->assertRedirect();

        $logs = ActivityLog::query()->where('event', 'app_setting_updated')->get();
        $this->assertCount(2, $logs);
        $this->assertSame(2, $logs->sum(fn (ActivityLog $log): int => (int) ($log->properties['new_value'] ?? 0) > 0 ? 1 : 0));
        foreach ($logs as $log) {
            $this->assertSame($superadmin->id, $log->causer_id);
            $this->assertArrayNotHasKey('email', $log->properties);
            $this->assertArrayNotHasKey('phone', $log->properties);
            $this->assertArrayNotHasKey('secret', $log->properties);
        }
    }
}
