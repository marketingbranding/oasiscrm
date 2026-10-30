<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DanaTalangan;
use App\Models\DanaTalanganBridgeSetting;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\User;
use App\Services\DanaTalanganBridgeModeService;
use App\Services\DanaTalanganBridgeService;
use App\Services\DanaTalanganSpreadsheetContract;
use App\Services\DanaTalanganSpreadsheetWriter;
use App\Services\ProjectIdentityResolver;
use App\Services\SyncLockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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

    public function test_bridge_push_does_not_invoke_writer_when_spreadsheet_sync_is_dormant(): void
    {
        config([
            'oasis.spreadsheet_sync_enabled' => false,
            'services.google_sheets.dana_talangan_bridge_enabled' => true,
        ]);
        DanaTalanganBridgeSetting::create([
            'spreadsheet_id' => 'spreadsheet-id',
            'mode' => 'bidirectional',
            'status' => 'success',
            'preflight_at' => now(),
            'preflight_hash' => str_repeat('a', 64),
        ]);
        $writer = Mockery::mock(DanaTalanganSpreadsheetWriter::class);
        $writer->shouldNotReceive('append');
        $contracts = Mockery::mock(DanaTalanganSpreadsheetContract::class);
        $contracts->shouldNotReceive('resolve');
        $branch = Branch::create(['name' => 'Cabang Test', 'code' => 'TEST', 'is_active' => true]);
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Proyek Test', 'is_active' => true]);
        $actor = User::factory()->create(['role_id' => Role::where('slug', 'superadmin')->value('id'), 'branch_id' => $branch->id, 'password_changed_at' => now()]);
        $record = DanaTalangan::create([
            'oasis_sync_id' => (string) Str::uuid(),
            'remote_target_spreadsheet_id' => 'spreadsheet-id',
            'tanggal' => '2026-08-31',
            'nama_konsumen' => 'Konsumen Test',
            'project_id' => $project->id,
            'project_name' => $project->project_name,
            'pinjam_nama' => false,
            'konfirmasi_keuangan' => false,
            'branch_id' => $branch->id,
            'status' => 'sanggup',
            'sync_status' => 'synced',
            'created_by' => $actor->id,
        ]);

        $bridge = new DanaTalanganBridgeService(
            app(DanaTalanganBridgeModeService::class),
            $contracts,
            $writer,
            app(SyncLockService::class),
            new ProjectIdentityResolver,
        );

        $result = $bridge->push($record);

        $this->assertFalse($result['ok']);
        $this->assertSame('disabled', $result['status']);
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

    public function test_monday_mvp_changelog_migration_is_idempotent(): void
    {
        $title = 'Dana Talangan Siap Operasional Senin';
        \DB::table('changelogs')->whereNull('version')->where('title', $title)->delete();
        $migration = require base_path('database/migrations/2026_10_30_180000_add_dana_talangan_monday_mvp_changelog.php');

        $migration->up();
        $migration->up();

        $this->assertSame(1, \DB::table('changelogs')->whereNull('version')->where('title', $title)->count());

        $migration->down();
        $this->assertSame(0, \DB::table('changelogs')->whereNull('version')->where('title', $title)->count());
    }
}
