<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\SystemTaskRun;
use App\Models\User;
use App\Services\SystemHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SystemHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_superadmin_can_view_health_page_and_no_secrets_are_exposed(): void
    {
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin', 'is_superadmin' => false]);
        $superRole = Role::create(['name' => 'Superadmin', 'slug' => 'superadmin', 'is_superadmin' => true]);
        $admin = User::factory()->create(['role_id' => $adminRole->id, 'password_changed_at' => now()]);
        $superadmin = User::factory()->create(['role_id' => $superRole->id, 'password_changed_at' => now()]);

        $this->actingAs($admin)->get(route('admin.system-health'))->assertForbidden();
        $response = $this->actingAs($superadmin)->get(route('admin.system-health'))
            ->assertOk()->assertSee('System Health')->assertSee('Vite assets')->assertSee('Migrations');
        foreach (['DB_PASSWORD', 'GOOGLE_SHEETS_CREDENTIALS_PATH', 'AI_PRIMARY_KEY', $superadmin->email] as $secret) {
            $response->assertDontSee($secret);
        }
    }

    public function test_health_page_warns_when_presence_cleanup_is_stale(): void
    {
        $role = Role::create(['name' => 'Superadmin', 'slug' => 'superadmin', 'is_superadmin' => true]);
        $user = User::factory()->create(['role_id' => $role->id, 'password_changed_at' => now()]);
        SystemTaskRun::create([
            'task_key' => 'oasis:presence-cleanup', 'started_at' => now()->subHours(4),
            'finished_at' => now()->subHours(4), 'status' => 'success', 'summary' => ['rows_deleted' => 3],
        ]);

        $this->actingAs($user)->get(route('admin.system-health'))
            ->assertOk()->assertSee('Presence cleanup execution')->assertSee('warning');
    }

    public function test_missing_vite_manifest_is_reported_without_exposing_path(): void
    {
        config(['health.vite_manifest_path' => storage_path('missing-health-manifest.json')]);

        $report = app(SystemHealthService::class)->report();
        $vite = collect($report['storage'])->firstWhere('label', 'Vite assets');

        $this->assertSame('fail', $vite['status']);
        $this->assertSame('Vite manifest missing', $vite['message']);
        $this->assertStringNotContainsString(storage_path(), $vite['message']);
    }
}
