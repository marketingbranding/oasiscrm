<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\DanaTalanganBridgeService;
use App\Services\KonsumenProgressSyncService;
use App\Services\NavigationService;
use App\Services\SalesLeadLifecycleSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationalModuleAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_dormant_operational_routes_return_not_found_and_json_sync_errors_remain_json(): void
    {
        config([
            'oasis.database_operational_enabled' => false,
            'oasis.consumer_progress_operational_enabled' => false,
            'oasis.spreadsheet_sync_enabled' => false,
            'app.workspace_v2_enabled' => false,
        ]);
        $user = $this->user('superadmin');

        $this->actingAs($user)->get(route('database.index'))->assertNotFound();
        $this->actingAs($user)->get(route('konsumen-progress.index'))->assertNotFound();
        $this->actingAs($user)->get(route('workspace-v2.dashboard'))->assertNotFound();

        $this->actingAs($user)
            ->postJson(route('database.sync'), ['branch_id' => 1])
            ->assertNotFound()
            ->assertJson(['message' => 'Not Found']);

        $this->actingAs($user)->postJson(route('konsumen-progress.sync'), ['branch_id' => 1])->assertNotFound();
        $this->actingAs($user)->postJson(route('dana-talangan.sync'))->assertNotFound();
        $this->actingAs($user)->getJson(route('dana-talangan.sync-status'))->assertNotFound();
        $this->actingAs($user)->postJson(route('sales-pocketbook.lifecycle-sync'))->assertNotFound();
        $this->actingAs($user)->getJson(route('sales-pocketbook.lifecycle-sync.status'))->assertNotFound();
    }

    public function test_spreadsheet_sync_commands_report_dormant_status_without_resolving_services(): void
    {
        config(['oasis.spreadsheet_sync_enabled' => false]);
        $this->app->bind(KonsumenProgressSyncService::class, fn () => throw new \RuntimeException('must not resolve'));
        $this->app->bind(SalesLeadLifecycleSyncService::class, fn () => throw new \RuntimeException('must not resolve'));
        $this->app->bind(DanaTalanganBridgeService::class, fn () => throw new \RuntimeException('must not resolve'));

        foreach (['konsumen-progress:sync', 'sales-lead-lifecycle:sync', 'dana-talangan:sync', 'dana-talangan-bridge:sync'] as $command) {
            $this->artisan($command)
                ->expectsOutputToContain('dormant')
                ->assertSuccessful();
        }
    }

    public function test_spreadsheet_sync_routes_return_not_found_when_modules_are_enabled_but_sync_is_dormant(): void
    {
        config([
            'oasis.database_operational_enabled' => true,
            'oasis.consumer_progress_operational_enabled' => true,
            'oasis.spreadsheet_sync_enabled' => false,
        ]);
        $user = $this->user('superadmin');

        $this->actingAs($user)->postJson(route('database.sync'), ['branch_id' => 1])->assertNotFound();
        $this->actingAs($user)->getJson(route('database.sync-status', ['branch_id' => 1]))->assertNotFound();
        $this->actingAs($user)->postJson(route('konsumen-progress.sync'), ['branch_id' => 1])->assertNotFound();
        $this->actingAs($user)->getJson(route('konsumen-progress.sync-status', ['branch_id' => 1]))->assertNotFound();
        $this->actingAs($user)->postJson(route('database.records.store'), [
            'branch_id' => 1,
            'sheet_name' => 'data_konsumen',
        ])->assertNotFound();
    }

    public function test_dormant_spreadsheet_sync_hides_the_entire_dana_talangan_sync_surface(): void
    {
        config(['oasis.spreadsheet_sync_enabled' => false]);
        $user = $this->user('superadmin');

        $this->actingAs($user)
            ->get(route('dana-talangan.index'))
            ->assertOk()
            ->assertDontSee('Klik Sync Sekarang')
            ->assertDontSee('Rekonsiliasi');
    }

    public function test_navigation_and_dashboard_do_not_expose_dormant_database_or_sync_controls(): void
    {
        config([
            'oasis.database_operational_enabled' => false,
            'oasis.consumer_progress_operational_enabled' => false,
            'oasis.spreadsheet_sync_enabled' => false,
        ]);
        $user = $this->user('superadmin');

        $navigation = app(NavigationService::class)->forUser($user);
        $labels = collect($navigation)->flatMap(fn (array $group): array => array_column($group['children'], 'label'))->all();

        $this->assertNotContains('Database', $labels);
        $this->assertNotContains('Database Konsumen', $labels);
        $this->assertNotContains('NUP / Waiting List', $labels);
        $this->assertNotContains('Konsumen Progress', $labels);
        $this->assertNotContains('Selesai', $labels);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertDontSee(route('database.sync'), false)
            ->assertDontSee('Sinkronisasi Database');
    }

    public function test_rollout_changelog_is_idempotent_and_rendered(): void
    {
        $user = $this->user('superadmin');

        $this->assertSame(1, \DB::table('changelogs')->whereNull('version')->where('title', 'Gate rollout modul operasional OASIS')->count());
        $this->assertSame(1, \DB::table('changelogs')->whereNull('version')->where('title', 'Pengamanan gate rollout dan sinkronisasi OASIS')->count());
        $this->actingAs($user)->get(route('changelogs.index'))->assertOk()->assertSee('Gate rollout modul operasional OASIS');
        $this->actingAs($user)->get(route('changelogs.index'))->assertSee('Pengamanan gate rollout dan sinkronisasi OASIS');
    }

    private function user(string $role): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $role)->value('id'),
            'password_changed_at' => now(),
        ])->load('role.permissions');
    }
}
