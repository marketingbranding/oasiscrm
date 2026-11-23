<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\ConsumerParitySnapshot;
use App\Models\Role;
use App\Models\User;
use App\Services\BranchMigrationStateService;
use App\Services\DatabaseSheetSyncService;
use App\Services\KonsumenProgressSyncService;
use App\Services\SalesLeadLifecycleSyncService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BranchMigrationStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_wp24_non_superadmin_gets_403_on_direct_state_url_and_json(): void
    {
        $user = User::factory()->create(['role_id' => Role::where('slug', 'pusat')->value('id'), 'password_changed_at' => now(), 'account_status' => 'active', 'is_active' => true]);

        $this->actingAs($user)->get(route('admin.branch-migration-states.index'))->assertForbidden();
        $this->actingAs($user)->getJson(route('admin.branch-migration-states.index'))->assertForbidden();
    }

    public function test_wp24_superadmin_can_view_state_page(): void
    {
        $user = User::factory()->create(['role_id' => Role::where('slug', 'superadmin')->value('id'), 'password_changed_at' => now(), 'account_status' => 'active', 'is_active' => true]);

        $this->actingAs($user)->get(route('admin.branch-migration-states.index'))->assertOk();
    }

    public function test_wp24_migration_page_shows_latest_parity_status_and_report_link(): void
    {
        $branch = Branch::create(['name' => 'Cabang Parity UI', 'code' => 'CUI', 'is_active' => true]);
        ConsumerParitySnapshot::create(['branch_id' => $branch->id, 'checked_at' => now(), 'status' => 'TIDAK_LENGKAP', 'summary' => [], 'metrics' => [], 'differences' => []]);
        $actor = User::factory()->create(['role_id' => Role::where('slug', 'superadmin')->value('id'), 'password_changed_at' => now(), 'account_status' => 'active', 'is_active' => true]);

        $this->actingAs($actor)->get(route('admin.branch-migration-states.index'))->assertOk()->assertSeeText('TIDAK_LENGKAP')->assertSee(route('admin.branch-parity.index', ['branch' => $branch->id]));
    }

    public function test_wp24_superadmin_can_move_one_state_forward_and_audit_change(): void
    {
        $branch = Branch::create(['name' => 'Cabang Transisi', 'code' => 'CT', 'is_active' => true]);
        DB::table('branch_migration_states')->insert(['branch_id' => $branch->id, 'state' => 'sheet', 'created_at' => now(), 'updated_at' => now()]);
        $actor = User::factory()->create(['role_id' => Role::where('slug', 'superadmin')->value('id'), 'password_changed_at' => now(), 'account_status' => 'active', 'is_active' => true]);

        $state = app(BranchMigrationStateService::class)->transition($actor, $branch, 'sheet', 'migrasi', 'Paket migrasi siap.');

        $this->assertSame('migrasi', $state->state);
        $this->assertDatabaseHas('activity_log', ['event' => 'branch_migration_state_changed', 'subject_id' => $branch->id]);
        $this->assertSame('sheet', data_get(ActivityLog::latest('id')->first()->properties, 'from_state'));
    }

    public function test_wp24_oasis_full_requires_fresh_latest_lulus_parity_snapshot(): void
    {
        $branch = Branch::create(['name' => 'Cabang Gate', 'code' => 'CG', 'is_active' => true]);
        DB::table('branch_migration_states')->insert(['branch_id' => $branch->id, 'state' => 'paralel', 'created_at' => now(), 'updated_at' => now()]);
        $actor = User::factory()->create(['role_id' => Role::where('slug', 'superadmin')->value('id'), 'password_changed_at' => now(), 'account_status' => 'active', 'is_active' => true]);
        ConsumerParitySnapshot::create(['branch_id' => $branch->id, 'checked_at' => now()->subHours(25), 'status' => 'LULUS', 'summary' => [], 'metrics' => [], 'differences' => []]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('LULUS');
        app(BranchMigrationStateService::class)->transition($actor, $branch, 'paralel', 'oasis_penuh', 'Cutover cabang.');
    }

    public function test_wp24_oasis_full_accepts_latest_fresh_lulus_snapshot_and_audits_it(): void
    {
        $branch = Branch::create(['name' => 'Cabang Gate Pass', 'code' => 'CGP', 'is_active' => true]);
        DB::table('branch_migration_states')->insert(['branch_id' => $branch->id, 'state' => 'paralel', 'created_at' => now(), 'updated_at' => now()]);
        $actor = User::factory()->create(['role_id' => Role::where('slug', 'superadmin')->value('id'), 'password_changed_at' => now(), 'account_status' => 'active', 'is_active' => true]);
        $snapshot = ConsumerParitySnapshot::create(['branch_id' => $branch->id, 'checked_at' => now()->subHour(), 'status' => 'LULUS', 'summary' => [], 'metrics' => [], 'differences' => []]);

        $state = app(BranchMigrationStateService::class)->transition($actor, $branch, 'paralel', 'oasis_penuh', 'Cutover cabang.');

        $this->assertSame('oasis_penuh', $state->state);
        $this->assertSame($snapshot->id, data_get(ActivityLog::latest('id')->first()->properties, 'parity_snapshot_id'));
    }

    public function test_wp24_oasis_full_rejects_latest_failed_snapshot(): void
    {
        $branch = Branch::create(['name' => 'Cabang Gate Failed', 'code' => 'CGF', 'is_active' => true]);
        DB::table('branch_migration_states')->insert(['branch_id' => $branch->id, 'state' => 'paralel', 'created_at' => now(), 'updated_at' => now()]);
        $actor = User::factory()->create(['role_id' => Role::where('slug', 'superadmin')->value('id'), 'password_changed_at' => now(), 'account_status' => 'active', 'is_active' => true]);
        ConsumerParitySnapshot::create(['branch_id' => $branch->id, 'checked_at' => now()->subHour(), 'status' => 'GAGAL', 'summary' => [], 'metrics' => [], 'differences' => []]);

        $this->expectException(ValidationException::class);
        app(BranchMigrationStateService::class)->transition($actor, $branch, 'paralel', 'oasis_penuh', 'Cutover cabang.');
    }

    public function test_wp24_rollback_to_parallel_requires_reason_and_reenables_sync(): void
    {
        $branch = Branch::create(['name' => 'Cabang Rollback', 'code' => 'CRB', 'is_active' => true]);
        DB::table('branch_migration_states')->insert(['branch_id' => $branch->id, 'state' => 'oasis_penuh', 'created_at' => now(), 'updated_at' => now()]);
        $actor = User::factory()->create(['role_id' => Role::where('slug', 'superadmin')->value('id'), 'password_changed_at' => now(), 'account_status' => 'active', 'is_active' => true]);

        $state = app(BranchMigrationStateService::class)->transition($actor, $branch, 'oasis_penuh', 'paralel', 'Rollback sinkronisasi.');

        $this->assertSame('paralel', $state->state);
        $this->assertTrue(app(BranchMigrationStateService::class)->allowsSheetSync($branch));
    }

    public function test_wp24_cutover_override_requires_primary_owner_and_reason(): void
    {
        $branch = Branch::create(['name' => 'Cabang Override', 'code' => 'COV', 'is_active' => true]);
        DB::table('branch_migration_states')->insert(['branch_id' => $branch->id, 'state' => 'paralel', 'created_at' => now(), 'updated_at' => now()]);
        $actor = User::factory()->create(['role_id' => Role::where('slug', 'superadmin')->value('id'), 'password_changed_at' => now(), 'account_status' => 'active', 'is_active' => true]);

        $this->expectException(ValidationException::class);
        app(BranchMigrationStateService::class)->transition($actor, $branch, 'paralel', 'oasis_penuh', '');
    }

    public function test_wp24_owner_can_override_cutover_with_reason_and_snapshot_audit(): void
    {
        $branch = Branch::create(['name' => 'Cabang Owner', 'code' => 'COW', 'is_active' => true]);
        DB::table('branch_migration_states')->insert(['branch_id' => $branch->id, 'state' => 'paralel', 'created_at' => now(), 'updated_at' => now()]);
        $actor = User::factory()->create(['role_id' => Role::where('slug', 'superadmin')->value('id'), 'password_changed_at' => now(), 'account_status' => 'active', 'is_active' => true]);
        $snapshot = ConsumerParitySnapshot::create(['branch_id' => $branch->id, 'checked_at' => now()->subDays(2), 'status' => 'GAGAL', 'summary' => [], 'metrics' => [], 'differences' => []]);

        app(BranchMigrationStateService::class)->transition($actor, $branch, 'paralel', 'oasis_penuh', 'Pemilik menyetujui pengecualian.', true);

        $properties = ActivityLog::latest('id')->first()->properties;
        $this->assertSame($snapshot->id, data_get($properties, 'parity_snapshot_id'));
        $this->assertTrue(data_get($properties, 'parity_override'));
    }

    public function test_wp24_non_owner_cannot_override_cutover(): void
    {
        $branch = Branch::create(['name' => 'Cabang Non Owner', 'code' => 'CNO', 'is_active' => true]);
        DB::table('branch_migration_states')->insert(['branch_id' => $branch->id, 'state' => 'paralel', 'created_at' => now(), 'updated_at' => now()]);
        $actor = User::factory()->create(['role_id' => Role::where('slug', 'pusat')->value('id'), 'password_changed_at' => now(), 'account_status' => 'active', 'is_active' => true]);

        $this->expectException(AuthorizationException::class);
        app(BranchMigrationStateService::class)->transition($actor, $branch, 'paralel', 'oasis_penuh', 'Override tidak sah.', true);
    }

    public function test_wp24_superadmin_can_move_one_state_backward_with_reason(): void
    {
        $branch = Branch::create(['name' => 'Cabang Mundur', 'code' => 'CM', 'is_active' => true]);
        DB::table('branch_migration_states')->insert(['branch_id' => $branch->id, 'state' => 'paralel', 'created_at' => now(), 'updated_at' => now()]);
        $actor = User::factory()->create(['role_id' => Role::where('slug', 'superadmin')->value('id'), 'password_changed_at' => now(), 'account_status' => 'active', 'is_active' => true]);

        $state = app(BranchMigrationStateService::class)->transition($actor, $branch, 'paralel', 'migrasi', 'Parity belum bersih.');

        $this->assertSame('migrasi', $state->state);
        $this->assertSame('Parity belum bersih.', data_get(ActivityLog::latest('id')->first()->properties, 'reason'));
    }

    public function test_wp24_transition_rejects_jump_noop_stale_and_empty_reason_without_audit(): void
    {
        $branch = Branch::create(['name' => 'Cabang Invalid', 'code' => 'CI', 'is_active' => true]);
        DB::table('branch_migration_states')->insert(['branch_id' => $branch->id, 'state' => 'sheet', 'created_at' => now(), 'updated_at' => now()]);
        $actor = User::factory()->create(['role_id' => Role::where('slug', 'superadmin')->value('id'), 'password_changed_at' => now(), 'account_status' => 'active', 'is_active' => true]);
        $service = app(BranchMigrationStateService::class);

        foreach ([['sheet', 'paralel', 'lompat'], ['sheet', 'sheet', 'no-op'], ['migrasi', 'paralel', 'stale'], ['sheet', 'migrasi', '   ']] as [$expected, $target, $reason]) {
            try {
                $service->transition($actor, $branch, $expected, $target, $reason);
                $this->fail('Transition harus ditolak.');
            } catch (\Throwable $exception) {
                $this->assertInstanceOf(ValidationException::class, $exception);
            }
        }

        $this->assertSame(0, ActivityLog::query()->where('event', 'branch_migration_state_changed')->count());
    }

    public function test_wp24_missing_state_resolves_to_sheet_and_does_not_block_writes(): void
    {
        $branch = Branch::create(['name' => 'Cabang Baru', 'code' => 'CB', 'is_active' => true]);

        $service = app(BranchMigrationStateService::class);

        $this->assertSame('sheet', $service->stateFor($branch));
        $service->assertWritable($branch);
        $this->assertDatabaseMissing('branch_migration_states', ['branch_id' => $branch->id]);
    }

    public function test_wp24_oasis_full_skip_log_is_deduplicated_without_activity_log(): void
    {
        $branch = Branch::create(['name' => 'Cabang Skip', 'code' => 'CK', 'is_active' => true]);
        DB::table('branch_migration_states')->insert(['branch_id' => $branch->id, 'state' => 'oasis_penuh', 'created_at' => now(), 'updated_at' => now()]);
        Log::spy();
        $service = app(BranchMigrationStateService::class);

        $service->logSkip($branch, 'database');
        $service->logSkip($branch, 'database');

        Log::shouldHaveReceived('info')->once();
        $this->assertSame(0, ActivityLog::query()->count());
    }

    public function test_wp24_oasis_full_syncs_skip_database_konsumen_and_lifecycle(): void
    {
        $branch = Branch::create(['name' => 'Cabang Sync Skip', 'code' => 'CSK', 'is_active' => true]);
        DB::table('branch_migration_states')->insert(['branch_id' => $branch->id, 'state' => 'oasis_penuh', 'created_at' => now(), 'updated_at' => now()]);

        $database = app(DatabaseSheetSyncService::class)->syncBranch($branch);
        $progress = app(KonsumenProgressSyncService::class)->syncBranch($branch);
        $lifecycle = app(SalesLeadLifecycleSyncService::class)->sync($branch);

        $this->assertSame('skipped', $database['status']);
        $this->assertSame('skipped', $progress['status']);
        $this->assertTrue($progress['ok']);
        $this->assertSame('skipped', $lifecycle['status']);
        $this->assertTrue($lifecycle['ok']);
        $this->assertDatabaseMissing('database_sheet_sync_statuses', ['branch_id' => $branch->id]);
    }

    public function test_wp24_state_matrix_allows_input_and_controls_sheet_boundaries(): void
    {
        $branch = Branch::create(['name' => 'Cabang Matrix', 'code' => 'CMX', 'is_active' => true]);
        $service = app(BranchMigrationStateService::class);

        foreach (['sheet', 'migrasi', 'paralel', 'oasis_penuh'] as $state) {
            DB::table('branch_migration_states')->updateOrInsert(
                ['branch_id' => $branch->id],
                ['state' => $state, 'created_at' => now(), 'updated_at' => now()],
            );

            $this->assertSame(in_array($state, ['paralel', 'oasis_penuh'], true), $service->allowsOasisConsumerInput($branch));
            $this->assertSame(in_array($state, ['sheet', 'migrasi'], true), $service->allowsSheetWrite($branch));
            $this->assertSame($state !== 'oasis_penuh', $service->allowsSheetSync($branch));
        }
    }

    public function test_wp24_sheet_input_rejection_uses_friendly_message(): void
    {
        $branch = Branch::create(['name' => 'Cabang Sheet', 'code' => 'CSH', 'is_active' => true]);
        DB::table('branch_migration_states')->insert(['branch_id' => $branch->id, 'state' => 'sheet', 'created_at' => now(), 'updated_at' => now()]);

        $this->expectExceptionMessage('Cabang ini masih memakai spreadsheet untuk proses penjualan.');
        app(BranchMigrationStateService::class)->assertOasisConsumerInputAllowed($branch);
    }

    public function test_wp24_oasis_full_branch_rejects_writes(): void
    {
        $branch = Branch::create(['name' => 'Cabang Penuh', 'code' => 'CP', 'is_active' => true]);
        DB::table('branch_migration_states')->insert(['branch_id' => $branch->id, 'state' => 'oasis_penuh', 'created_at' => now(), 'updated_at' => now()]);

        $this->expectException(AuthorizationException::class);
        app(BranchMigrationStateService::class)->assertWritable($branch);
    }

    public function test_wp24_migration_defaults_all_existing_branches_to_sheet_and_is_idempotent(): void
    {
        $first = Branch::create(['name' => 'Cabang Satu', 'code' => 'CS', 'is_active' => true]);
        $second = Branch::create(['name' => 'Cabang Dua', 'code' => 'CD', 'is_active' => true]);

        $migration = require database_path('migrations/2026_11_23_000000_create_branch_migration_states_table.php');
        $migration->up();

        $this->assertSame('sheet', DB::table('branch_migration_states')->where('branch_id', $first->id)->value('state'));
        $this->assertSame('sheet', DB::table('branch_migration_states')->where('branch_id', $second->id)->value('state'));

        DB::table('branch_migration_states')->where('branch_id', $first->id)->update(['state' => 'paralel']);
        $migration->up();

        $this->assertSame('paralel', DB::table('branch_migration_states')->where('branch_id', $first->id)->value('state'));
        $this->assertSame(2, DB::table('branch_migration_states')->count());
    }
}
