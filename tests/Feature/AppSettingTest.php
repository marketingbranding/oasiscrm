<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Services\AppSettingResolver;
use App\Services\AppSettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
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
            ->get(route('admin.app-settings.index'))
            ->assertSee('name="settings[sales_agenda__reminder_lead_minutes]"', false);

        $this->actingAs($superadmin)
            ->put(route('admin.app-settings.update'), [
                'settings' => [
                    'sales_agenda__reminder_lead_minutes' => 20,
                    'sales_monitoring__stale_lead_days' => 5,
                    'org_hierarchical_monitoring' => true,
                ],
            ])
            ->assertRedirect(route('admin.app-settings.index'));

        $this->assertSame(20, app(AppSettingResolver::class)->int('sales_agenda.reminder_lead_minutes'));
        $this->assertSame(5, app(AppSettingResolver::class)->int('sales_monitoring.stale_lead_days'));
        $this->assertTrue(app(AppSettingResolver::class)->bool('org_hierarchical_monitoring'));
    }

    public function test_superadmin_can_update_branch_scoped_rollout_and_audit_it(): void
    {
        $superadmin = $this->user('superadmin');
        $branch = Branch::create(['code' => 'SET', 'name' => 'Settings', 'is_active' => true]);

        $this->actingAs($superadmin)->put(route('admin.app-settings.update'), [
            'settings' => [
                'lead_stages_v3__mode' => 'branches',
                'lead_stages_v3__branch_ids' => [$branch->id],
            ],
        ])->assertRedirect(route('admin.app-settings.index'));

        $this->assertSame(['mode' => 'branches', 'branch_ids' => [$branch->id]], app(AppSettingResolver::class)->value('lead_stages_v3'));
        $this->assertDatabaseHas('activity_log', ['event' => 'app_setting_updated']);
    }

    public function test_branch_scoped_rollout_requires_active_branch_for_branches_mode(): void
    {
        $superadmin = $this->user('superadmin');
        $response = $this->actingAs($superadmin)->from(route('admin.app-settings.index'))->put(route('admin.app-settings.update'), [
            'settings' => ['lead_stages_v3__mode' => 'branches'],
        ]);

        $response->assertRedirect()->assertSessionHasErrors('lead_stages_v3.branch_ids');
    }

    public function test_non_superadmin_cannot_read_or_update_settings(): void
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

    public function test_br_wp11_service_update_clears_changed_setting_cache(): void
    {
        $superadmin = $this->user('superadmin');
        $resolver = app(AppSettingResolver::class);

        $this->assertSame(15, $resolver->int('sales_agenda.reminder_lead_minutes'));
        Cache::put('oasis:app-setting:sales_agenda.reminder_lead_minutes', 15);

        app(AppSettingService::class)->update($superadmin, [
            'sales_agenda.reminder_lead_minutes' => 20,
        ]);

        $this->assertFalse(Cache::has('oasis:app-setting:sales_agenda.reminder_lead_minutes'));
        $this->assertSame(20, $resolver->int('sales_agenda.reminder_lead_minutes'));
    }

    public function test_invalid_or_unknown_values_do_not_change_settings(): void
    {
        $superadmin = $this->user('superadmin');
        $resolver = app(AppSettingResolver::class);
        $this->assertSame(15, $resolver->int('sales_agenda.reminder_lead_minutes'));

        $response = $this->actingAs($superadmin)
            ->from(route('admin.app-settings.index'))
            ->put(route('admin.app-settings.update'), [
                'settings' => [
                    'sales_agenda__reminder_lead_minutes' => 0,
                    'unknown__setting' => 'bad',
                ],
            ])
            ->assertRedirect(route('admin.app-settings.index'));

        $response
            ->assertSessionHasErrors(['settings.sales_agenda__reminder_lead_minutes', 'settings.unknown__setting'])
            ->assertSessionHasInput('settings.sales_agenda__reminder_lead_minutes', 0);

        $this->actingAs($superadmin)
            ->get(route('admin.app-settings.index'))
            ->assertSee('name="settings[sales_agenda__reminder_lead_minutes]"', false)
            ->assertSee('value="0"', false);

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

    #[DataProvider('calendarValidationCases')]
    public function test_br01_to_br04_reject_each_calendar_validation_violation(array $settings, array $errorKeys): void
    {
        $superadmin = $this->user('superadmin');

        $response = $this->actingAs($superadmin)
            ->from(route('admin.app-settings.index'))
            ->put(route('admin.app-settings.update'), ['settings' => $settings]);

        $response->assertRedirect(route('admin.app-settings.index'))
            ->assertSessionHasErrors($errorKeys);
        $this->assertDatabaseCount('app_settings', 0);
    }

    public static function calendarValidationCases(): array
    {
        return [
            'weekday start before break' => [[
                'sales_agenda__weekday_start_time' => '12:00',
                'sales_agenda__weekday_break_start' => '12:00',
                'sales_agenda__weekday_break_end' => '13:00',
                'sales_agenda__weekday_end_time' => '17:00',
                'sales_agenda__target_minutes' => 240,
            ], ['settings.sales_agenda__weekday_start_time', 'settings.sales_agenda__weekday_break_start']],
            'weekday break order' => [[
                'sales_agenda__weekday_start_time' => '08:00',
                'sales_agenda__weekday_break_start' => '13:00',
                'sales_agenda__weekday_break_end' => '12:00',
                'sales_agenda__weekday_end_time' => '17:00',
                'sales_agenda__target_minutes' => 360,
            ], ['settings.sales_agenda__weekday_break_start', 'settings.sales_agenda__weekday_break_end']],
            'weekday end after break' => [[
                'sales_agenda__weekday_start_time' => '08:00',
                'sales_agenda__weekday_break_start' => '12:00',
                'sales_agenda__weekday_break_end' => '13:00',
                'sales_agenda__weekday_end_time' => '12:00',
                'sales_agenda__target_minutes' => 1,
            ], ['settings.sales_agenda__weekday_break_end', 'settings.sales_agenda__weekday_end_time']],
            'weekday target within effective minutes' => [[
                'sales_agenda__weekday_start_time' => '08:00',
                'sales_agenda__weekday_break_start' => '12:00',
                'sales_agenda__weekday_break_end' => '13:00',
                'sales_agenda__weekday_end_time' => '16:00',
                'sales_agenda__target_minutes' => 421,
            ], ['settings.sales_agenda__target_minutes']],
            'friday start before break' => [[
                'sales_agenda__friday_start_time' => '11:30',
                'sales_agenda__friday_break_start' => '11:30',
                'sales_agenda__friday_break_end' => '13:00',
                'sales_agenda__friday_end_time' => '17:00',
                'sales_agenda__target_minutes' => 300,
            ], ['settings.sales_agenda__friday_start_time', 'settings.sales_agenda__friday_break_start']],
            'friday break order' => [[
                'sales_agenda__friday_start_time' => '07:30',
                'sales_agenda__friday_break_start' => '13:00',
                'sales_agenda__friday_break_end' => '12:00',
                'sales_agenda__friday_end_time' => '17:00',
                'sales_agenda__target_minutes' => 360,
            ], ['settings.sales_agenda__friday_break_start', 'settings.sales_agenda__friday_break_end']],
            'friday end after break' => [[
                'sales_agenda__friday_start_time' => '07:30',
                'sales_agenda__friday_break_start' => '11:30',
                'sales_agenda__friday_break_end' => '13:00',
                'sales_agenda__friday_end_time' => '12:00',
                'sales_agenda__target_minutes' => 1,
            ], ['settings.sales_agenda__friday_break_end', 'settings.sales_agenda__friday_end_time']],
            'friday target within effective minutes' => [[
                'sales_agenda__friday_start_time' => '07:30',
                'sales_agenda__friday_break_start' => '11:30',
                'sales_agenda__friday_break_end' => '13:00',
                'sales_agenda__friday_end_time' => '16:30',
                'sales_agenda__target_minutes' => 451,
            ], ['settings.sales_agenda__target_minutes']],
        ];
    }

    public function test_br01_to_br04_reject_invalid_weekday_calendar_order_and_target(): void
    {
        $superadmin = $this->user('superadmin');

        $response = $this->actingAs($superadmin)
            ->from(route('admin.app-settings.index'))
            ->put(route('admin.app-settings.update'), [
                'settings' => [
                    'sales_agenda__weekday_start_time' => '13:00',
                    'sales_agenda__weekday_break_start' => '12:00',
                    'sales_agenda__weekday_break_end' => '12:00',
                    'sales_agenda__weekday_end_time' => '11:00',
                    'sales_agenda__target_minutes' => 480,
                ],
            ]);

        $response->assertRedirect(route('admin.app-settings.index'))
            ->assertSessionHasErrors([
                'settings.sales_agenda__weekday_start_time',
                'settings.sales_agenda__weekday_break_start',
                'settings.sales_agenda__weekday_break_end',
                'settings.sales_agenda__weekday_end_time',
                'settings.sales_agenda__target_minutes',
            ]);
        $this->assertDatabaseCount('app_settings', 0);
    }

    public function test_br01_to_br04_reject_invalid_friday_calendar_order_and_target(): void
    {
        $superadmin = $this->user('superadmin');

        $response = $this->actingAs($superadmin)
            ->from(route('admin.app-settings.index'))
            ->put(route('admin.app-settings.update'), [
                'settings' => [
                    'sales_agenda__friday_start_time' => '14:00',
                    'sales_agenda__friday_break_start' => '13:00',
                    'sales_agenda__friday_break_end' => '13:00',
                    'sales_agenda__friday_end_time' => '12:00',
                    'sales_agenda__target_minutes' => 480,
                ],
            ]);

        $response->assertRedirect(route('admin.app-settings.index'))
            ->assertSessionHasErrors([
                'settings.sales_agenda__friday_start_time',
                'settings.sales_agenda__friday_break_start',
                'settings.sales_agenda__friday_break_end',
                'settings.sales_agenda__friday_end_time',
                'settings.sales_agenda__target_minutes',
            ]);
        $this->assertDatabaseCount('app_settings', 0);
    }

    public function test_br01_to_br04_accept_changed_valid_calendar_and_target(): void
    {
        $superadmin = $this->user('superadmin');

        $this->actingAs($superadmin)
            ->put(route('admin.app-settings.update'), [
                'settings' => [
                    'sales_agenda__weekday_start_time' => '08:00',
                    'sales_agenda__weekday_break_start' => '12:00',
                    'sales_agenda__weekday_break_end' => '13:00',
                    'sales_agenda__weekday_end_time' => '16:00',
                    'sales_agenda__friday_start_time' => '07:30',
                    'sales_agenda__friday_break_start' => '11:30',
                    'sales_agenda__friday_break_end' => '13:00',
                    'sales_agenda__friday_end_time' => '17:00',
                    'sales_agenda__target_minutes' => 420,
                ],
            ])
            ->assertRedirect(route('admin.app-settings.index'));

        $this->assertSame(420, app(AppSettingResolver::class)->int('sales_agenda.target_minutes'));
        $this->assertSame('16:00', app(AppSettingResolver::class)->string('sales_agenda.weekday_end_time'));
    }

    public function test_br01_to_br04_service_rejects_invalid_calendar_before_database_write(): void
    {
        $superadmin = $this->user('superadmin');

        $this->expectException(ValidationException::class);

        app(AppSettingService::class)->update($superadmin, [
            'sales_agenda.target_minutes' => 480,
            'sales_agenda.weekday_start_time' => '08:00',
            'sales_agenda.weekday_break_start' => '12:00',
            'sales_agenda.weekday_break_end' => '13:00',
            'sales_agenda.weekday_end_time' => '15:00',
        ]);
    }

    public function test_each_changed_setting_writes_audited_value_without_contact_fields(): void
    {
        $superadmin = $this->user('superadmin');

        $this->actingAs($superadmin)->put(route('admin.app-settings.update'), [
            'settings' => [
                'sales_agenda__reminder_lead_minutes' => 20,
                'sales_monitoring__stale_lead_days' => 5,
            ],
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
