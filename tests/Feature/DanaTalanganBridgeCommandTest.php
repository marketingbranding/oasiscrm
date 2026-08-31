<?php

namespace Tests\Feature;

use App\Models\DanaTalanganBridgeSetting;
use App\Services\DanaTalanganBridgeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class DanaTalanganBridgeCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.google_sheets.dana_talangan_spreadsheet_id' => 'spreadsheet-id',
            'services.google_sheets.dana_talangan_sheet_name' => 'Talangan',
        ]);
    }

    public function test_kill_switch_commands_do_not_resolve_google_dependent_service(): void
    {
        config()->set('services.google_sheets.dana_talangan_bridge_enabled', false);
        $this->app->bind(DanaTalanganBridgeService::class, fn () => throw new \RuntimeException('must not resolve'));

        $this->artisan('dana-talangan-bridge:preflight')->expectsOutput('Bridge Dana Talangan sedang dinonaktifkan.')->assertSuccessful();
        $this->artisan('dana-talangan-bridge:sync')->expectsOutput('Bridge Dana Talangan sedang dinonaktifkan.')->assertSuccessful();
        $this->artisan('dana-talangan:sync')->expectsOutput('Bridge Dana Talangan sedang dinonaktifkan.')->assertSuccessful();
    }

    public function test_set_mode_preflights_before_activation(): void
    {
        config()->set('services.google_sheets.dana_talangan_bridge_enabled', true);
        $bridge = Mockery::mock(DanaTalanganBridgeService::class);
        $bridge->shouldReceive('preflight')->once()->andReturnUsing(fn () => DanaTalanganBridgeSetting::create([
            'spreadsheet_id' => 'spreadsheet-id',
            'mode' => 'off',
            'status' => 'success',
            'preflight_at' => now(),
            'preflight_hash' => str_repeat('a', 64),
        ]));
        $this->app->instance(DanaTalanganBridgeService::class, $bridge);

        $this->artisan('dana-talangan-bridge:set-mode', ['--mode' => 'push_only'])->assertSuccessful();
        $this->assertDatabaseHas('dana_talangan_bridge_settings', ['spreadsheet_id' => 'spreadsheet-id', 'mode' => 'push_only', 'status' => 'active']);
    }

    public function test_bridge_sync_delegates_dry_run_without_status_mutation(): void
    {
        config()->set('services.google_sheets.dana_talangan_bridge_enabled', true);
        $bridge = Mockery::mock(DanaTalanganBridgeService::class);
        $bridge->shouldReceive('pull')->once()->with(null, true)->andReturn([
            'ok' => true,
            'status' => 'success',
            'summary' => ['updated' => 0, 'unchanged' => 1, 'remote_create_pending_review' => 0, 'unresolved' => 0, 'ignored_tombstones' => 0],
        ]);
        $this->app->instance(DanaTalanganBridgeService::class, $bridge);

        $this->artisan('dana-talangan-bridge:sync', ['--dry-run' => true])->assertSuccessful();
        $this->assertDatabaseCount('dana_talangan_sync_statuses', 0);
    }

    public function test_changelog_is_unique_and_visible(): void
    {
        $this->assertSame(1, \DB::table('changelogs')->whereNull('version')->where('title', 'Bridge Dana Talangan Aman')->count());
    }
}
