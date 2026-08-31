<?php

namespace Tests\Feature;

use App\Enums\DanaTalanganBridgeMode;
use App\Models\Branch;
use App\Models\DanaTalangan;
use App\Models\DanaTalanganBridgeSetting;
use App\Models\DanaTalanganReconciliationItem;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\User;
use App\Services\DanaTalanganBridgeModeService;
use App\Services\DanaTalanganBridgeService;
use App\Services\DanaTalanganService;
use App\Services\DanaTalanganSpreadsheetContract;
use App\Services\DanaTalanganSpreadsheetWriter;
use App\Services\GoogleSheetsApiService;
use App\Services\SyncLockService;
use App\ValueObjects\DanaTalanganSpreadsheetWriteResult;
use App\ValueObjects\ResolvedDanaTalanganSpreadsheetContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class DanaTalanganBridgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.google_sheets.dana_talangan_bridge_enabled' => true,
            'services.google_sheets.dana_talangan_spreadsheet_id' => 'spreadsheet-id',
            'services.google_sheets.dana_talangan_sheet_name' => 'Talangan',
        ]);
    }

    public function test_mode_defaults_off_and_requires_preflight(): void
    {
        $modes = app(DanaTalanganBridgeModeService::class);

        $this->assertFalse($modes->isPushEnabled());
        $this->assertFalse($modes->isPullEnabled());
        $this->expectException(\DomainException::class);
        $modes->setMode(DanaTalanganBridgeMode::PushOnly);
    }

    public function test_preflight_accepts_exact_a_to_q_without_writes(): void
    {
        $google = Mockery::mock(GoogleSheetsApiService::class);
        $google->shouldReceive('sheetIds')->once()->with('spreadsheet-id')->andReturn(['Talangan' => 7]);
        $google->shouldReceive('quoteSheetName')->once()->with('Talangan')->andReturn("'Talangan'");
        $google->shouldReceive('batchGetRaw')->once()->with('spreadsheet-id', ["'Talangan'!A1:Q1"], 'FORMATTED_VALUE')->andReturn(['Talangan' => [DanaTalanganSpreadsheetContract::HEADERS]]);
        $google->shouldReceive('gridMetadata')->once()->with('spreadsheet-id', 'Talangan', 'A:Q')->andReturn(['sheet_id' => 7, 'formulas' => [], 'validations' => []]);
        $google->shouldNotReceive('updateRange');
        $contract = (new DanaTalanganSpreadsheetContract($google))->resolve();

        $this->assertSame('spreadsheet-id', $contract->spreadsheetId);
        $this->assertSame(64, strlen($contract->hash));
    }

    public function test_preflight_rejects_wrong_metadata_and_formulas_without_write(): void
    {
        $headers = DanaTalanganSpreadsheetContract::HEADERS;
        $headers[14] = 'wrong';
        $google = Mockery::mock(GoogleSheetsApiService::class);
        $google->shouldReceive('sheetIds')->once()->andReturn(['Talangan' => 7]);
        $google->shouldReceive('quoteSheetName')->once()->andReturn("'Talangan'");
        $google->shouldReceive('batchGetRaw')->once()->andReturn(['Talangan' => [$headers]]);
        $google->shouldNotReceive('gridMetadata');
        $google->shouldNotReceive('updateRange');
        try {
            (new DanaTalanganSpreadsheetContract($google))->resolve();
            $this->fail('Wrong metadata must fail.');
        } catch (\RuntimeException) {
        }

        $google = Mockery::mock(GoogleSheetsApiService::class);
        $google->shouldReceive('sheetIds')->once()->andReturn(['Talangan' => 7]);
        $google->shouldReceive('quoteSheetName')->once()->andReturn("'Talangan'");
        $google->shouldReceive('batchGetRaw')->once()->andReturn(['Talangan' => [DanaTalanganSpreadsheetContract::HEADERS]]);
        $google->shouldReceive('gridMetadata')->once()->andReturn(['sheet_id' => 7, 'formulas' => [['row' => 2, 'column' => 3]], 'validations' => []]);
        $google->shouldNotReceive('updateRange');
        $this->expectException(\RuntimeException::class);
        (new DanaTalanganSpreadsheetContract($google))->resolve();
    }

    public function test_formula_leading_text_is_escaped(): void
    {
        $contract = new DanaTalanganSpreadsheetContract(Mockery::mock(GoogleSheetsApiService::class));

        foreach (['=', '+', '-', '@'] as $prefix) {
            $this->assertSame("'{$prefix}value", $contract->valueForWrite($prefix.'value'));
        }
        $this->assertSame('2026-08-31', $contract->valueForWrite('2026-08-31'));
    }

    public function test_push_updates_local_change_when_remote_remains_at_baseline(): void
    {
        [$record, $actor] = $this->record();
        $this->enable('push_only');
        $baseline = $this->row($record);
        $this->baseline($record, $baseline);
        $record->update(['penyelesaian' => 'Lokal baru', 'sync_status' => 'pending_update']);
        $sent = $this->row($record->fresh());
        $contracts = $this->contracts([$baseline]);
        $writer = Mockery::mock(DanaTalanganSpreadsheetWriter::class);
        $writer->shouldReceive('update')->once()->withArgs(fn (string $uuid, array $fields, bool $lock) => $uuid === $record->oasis_sync_id && $fields[11] === 'Lokal baru' && ! $lock)->andReturn(new DanaTalanganSpreadsheetWriteResult('spreadsheet-id', 'Talangan', 2, $record->oasis_sync_id, $sent));

        $result = $this->bridge($contracts, $writer)->push($record->fresh(), $actor);

        $this->assertTrue($result['ok']);
        $this->assertSame('synced', $record->fresh()->sync_status);
    }

    public function test_pull_updates_only_clean_shared_fields(): void
    {
        [$record, $actor] = $this->record();
        $this->enable('bidirectional');
        $baseline = $this->row($record);
        $this->baseline($record, $baseline);
        $remote = $baseline;
        $remote['Penyelesaian'] = 'Selesai remote';
        $remote['Status Cicilan'] = 'lunas';
        $contracts = $this->contracts([$remote]);

        $result = $this->bridge($contracts)->pull($actor);

        $fresh = $record->fresh();
        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['summary']['updated']);
        $this->assertSame('Selesai remote', $fresh->penyelesaian);
        $this->assertSame('lunas', $fresh->status);
        $this->assertSame('Konsumen Test', $fresh->nama_konsumen);
    }

    public function test_pull_owned_change_and_divergent_shared_change_reconcile_without_mutation(): void
    {
        [$record, $actor] = $this->record();
        $this->enable('bidirectional');
        $baseline = $this->row($record);
        $this->baseline($record, $baseline);
        $record->update(['penyelesaian' => 'Lokal']);
        $remote = $baseline;
        $remote['Nama Konsumen'] = 'Remote PII';
        $remote['Penyelesaian'] = 'Remote';

        $result = $this->bridge($this->contracts([$remote]))->pull($actor);

        $this->assertSame(1, $result['summary']['unresolved']);
        $this->assertSame('Konsumen Test', $record->fresh()->nama_konsumen);
        $this->assertSame('Lokal', $record->fresh()->penyelesaian);
        $item = DanaTalanganReconciliationItem::firstOrFail();
        $this->assertSame('remote_conflict', $item->issue_code);
        $this->assertSame(['Nama Konsumen', 'Penyelesaian'], $item->field_names);
        $this->assertStringNotContainsString('Remote PII', json_encode($item->safe_metadata));
    }

    public function test_remote_create_missing_and_tombstone_only_reconcile(): void
    {
        [$record, $actor] = $this->record();
        $this->enable('bidirectional');
        $baseline = $this->row($record);
        $this->baseline($record, $baseline);
        $new = $this->row($record);
        $new['_row_number'] = 3;
        $new['oasis_sync_id'] = '';
        $new['Nama Konsumen'] = 'Remote New';
        $tombstone = $baseline;
        $tombstone['oasis_deleted_at'] = now()->toIso8601String();

        $result = $this->bridge($this->contracts([$new, $tombstone]))->pull($actor);

        $this->assertSame(2, $result['summary']['unresolved']);
        $this->assertDatabaseCount('dana_talangans', 1);
        $this->assertDatabaseHas('dana_talangan_reconciliation_items', ['issue_code' => 'remote_create_pending_review']);
        $this->assertDatabaseHas('dana_talangan_reconciliation_items', ['issue_code' => 'remote_tombstone']);
        $this->assertNull($record->fresh()->deleted_at);
    }

    public function test_dry_run_does_not_mutate_status_or_reconciliation(): void
    {
        [$record, $actor] = $this->record();
        $this->enable('bidirectional');
        $baseline = $this->row($record);
        $this->baseline($record, $baseline);
        $remote = $baseline;
        $remote['Penyelesaian'] = 'Would update';

        $result = $this->bridge($this->contracts([$remote]))->pull($actor, true);

        $this->assertSame(1, $result['summary']['updated']);
        $this->assertNull($record->fresh()->penyelesaian);
        $this->assertDatabaseCount('dana_talangan_reconciliation_items', 0);
        $this->assertDatabaseCount('dana_talangan_sync_statuses', 0);
    }

    public function test_approval_requires_unchanged_row_and_exact_unique_project(): void
    {
        [$existing, $actor] = $this->record();
        $this->enable('bidirectional');
        $row = $this->row($existing);
        $row['_row_number'] = 3;
        $row['oasis_sync_id'] = '';
        $row['Nama Konsumen'] = 'Remote Approved';
        $item = DanaTalanganReconciliationItem::create([
            'spreadsheet_id' => 'spreadsheet-id',
            'remote_row_number' => 3,
            'issue_code' => 'remote_create_pending_review',
            'safe_metadata' => ['payload_hash' => $this->payloadHash($row)],
            'identity_key' => hash('sha256', 'approval'),
            'status' => 'open',
        ]);
        $writer = Mockery::mock(DanaTalanganSpreadsheetWriter::class);
        $writer->shouldReceive('setSyncId')->once()->withArgs(fn (int $number, string $uuid, bool $lock) => $number === 3 && Str::isUuid($uuid) && ! $lock)->andReturnUsing(fn (int $number, string $uuid) => new DanaTalanganSpreadsheetWriteResult('spreadsheet-id', 'Talangan', $number, $uuid, $row + ['oasis_sync_id' => $uuid]));
        $record = $this->bridge($this->contracts([$row]), $writer)->approveRemoteCreate($item, $actor);

        $this->assertSame('Remote Approved', $record->nama_konsumen);
        $this->assertNotNull($record->project_id);
        $this->assertDatabaseHas('dana_talangan_reconciliation_items', ['id' => $item->id, 'status' => 'resolved']);

        $changed = $row;
        $changed['Penyelesaian'] = 'Changed after review';
        $other = DanaTalanganReconciliationItem::create([
            'spreadsheet_id' => 'spreadsheet-id',
            'remote_row_number' => 3,
            'issue_code' => 'remote_create_pending_review',
            'safe_metadata' => ['payload_hash' => $this->payloadHash($row)],
            'identity_key' => hash('sha256', 'changed'),
            'status' => 'open',
        ]);
        $this->expectException(\DomainException::class);
        $this->bridge($this->contracts([$changed]))->approveRemoteCreate($other, $actor);
    }

    public function test_delivered_delete_failure_and_concurrent_edit_preserve_local_record(): void
    {
        [$record, $actor] = $this->record();
        $record->update(['last_synced_at' => now()]);
        $bridge = Mockery::mock(DanaTalanganBridgeService::class);
        $bridge->shouldReceive('canonicalPayload')->andReturnUsing(fn (DanaTalangan $item) => ['Nama Konsumen' => $item->nama_konsumen]);
        $bridge->shouldReceive('payloadHash')->andReturnUsing(fn (array $payload) => hash('sha256', json_encode($payload)));
        $bridge->shouldReceive('tombstone')->once()->andThrow(new \RuntimeException('remote down'));
        $this->app->instance(DanaTalanganBridgeService::class, $bridge);
        $service = new DanaTalanganService(app(DanaTalanganBridgeModeService::class));
        try {
            $service->delete($record, $actor);
            $this->fail('Delete must fail.');
        } catch (\DomainException) {
        }
        $this->assertDatabaseHas('dana_talangans', ['id' => $record->id, 'deleted_at' => null, 'sync_status' => 'pending_delete']);

        $record->update(['sync_status' => 'synced', 'delete_pending_at' => null]);
        $bridge = Mockery::mock(DanaTalanganBridgeService::class);
        $bridge->shouldReceive('canonicalPayload')->andReturnUsing(fn (DanaTalangan $item) => ['Nama Konsumen' => $item->nama_konsumen]);
        $bridge->shouldReceive('payloadHash')->andReturnUsing(fn (array $payload) => hash('sha256', json_encode($payload)));
        $bridge->shouldReceive('tombstone')->once()->andReturnUsing(function () use ($record): void {
            DanaTalangan::whereKey($record->id)->update(['nama_konsumen' => 'Concurrent']);
        });
        $this->app->instance(DanaTalanganBridgeService::class, $bridge);
        $service = new DanaTalanganService(app(DanaTalanganBridgeModeService::class));
        try {
            $service->delete($record->fresh(), $actor);
            $this->fail('Concurrent edit must block delete.');
        } catch (\DomainException) {
        }
        $this->assertDatabaseHas('dana_talangans', ['id' => $record->id, 'deleted_at' => null, 'nama_konsumen' => 'Concurrent', 'sync_status' => 'pending_delete']);
    }

    public function test_configured_spreadsheet_id_stays_in_environment_configuration(): void
    {
        $env = file_get_contents(base_path('.env.example'));
        $config = file_get_contents(config_path('services.php'));

        $this->assertStringContainsString('DANA_TALANGAN_SHEET_ID=1A7ISvfvvuDr5u9qAsNfi_CSehKNu7nwvJCaVWDU4jjM', $env);
        $this->assertStringNotContainsString('1A7ISvfvvuDr5u9qAsNfi_CSehKNu7nwvJCaVWDU4jjM', $config);
    }

    public function test_reconciliation_page_requires_manage_all(): void
    {
        [$record] = $this->record();
        DanaTalanganReconciliationItem::create([
            'dana_talangan_id' => $record->id,
            'spreadsheet_id' => 'spreadsheet-id',
            'remote_row_number' => 2,
            'issue_code' => 'remote_conflict',
            'identity_key' => hash('sha256', 'item'),
            'status' => 'open',
        ]);
        $admin = User::factory()->create(['role_id' => Role::where('slug', 'admin')->value('id'), 'password_changed_at' => now()]);
        $superadmin = User::factory()->create(['role_id' => Role::where('slug', 'superadmin')->value('id'), 'password_changed_at' => now()]);

        $this->actingAs($admin)->get(route('dana-talangan.reconciliation'))->assertForbidden();
        $this->actingAs($superadmin)->get(route('dana-talangan.reconciliation'))->assertOk()->assertSee('remote conflict');
    }

    private function record(): array
    {
        $branch = Branch::create(['name' => 'Cabang Test', 'code' => 'TEST'.Str::random(4), 'is_active' => true]);
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Proyek Test', 'sheet_project_name' => 'Proyek Test', 'is_active' => true]);
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

        return [$record, $actor];
    }

    private function enable(string $mode): void
    {
        DanaTalanganBridgeSetting::create([
            'spreadsheet_id' => 'spreadsheet-id',
            'mode' => $mode,
            'status' => 'active',
            'preflight_at' => now(),
            'preflight_hash' => str_repeat('a', 64),
            'enabled_at' => now(),
        ]);
    }

    private function row(DanaTalangan $record): array
    {
        return [
            '_row_number' => 2,
            'No' => '1',
            'Tanggal' => $record->tanggal->format('Y-m-d'),
            'Nama Konsumen' => $record->nama_konsumen,
            'Kav' => $record->kav ?? '',
            'Proyek' => $record->project_name,
            'Pinjam Nama' => $record->pinjam_nama ? '1' : '0',
            'Pekerjaan' => $record->pekerjaan ?? '',
            'Status Kawin' => $record->status_perkawinan ?? '',
            'Umur' => $record->umur === null ? '' : (string) $record->umur,
            'Marketing' => $record->nama_marketing ?? '',
            'TGL Komitmen' => $record->tgl_komitmen?->format('Y-m-d') ?? '',
            'Penyelesaian' => $record->penyelesaian ?? '',
            'Konfirmasi' => $record->konfirmasi_keuangan ? '1' : '0',
            'Status Cicilan' => $record->status,
            'oasis_sync_id' => $record->oasis_sync_id,
            'oasis_deleted_at' => '',
            'oasis_deleted_by' => '',
        ];
    }

    private function baseline(DanaTalangan $record, array $row): void
    {
        $fields = array_slice(DanaTalanganSpreadsheetContract::BUSINESS_HEADERS, 0, 14);
        $record->update([
            'last_synced_payload_hash' => $this->payloadHash($row),
            'last_remote_payload_hash' => $this->payloadHash($row),
            'last_synced_field_hashes' => collect($fields)->mapWithKeys(fn (string $field) => [$field => hash('sha256', (string) $row[$field])])->all(),
            'last_synced_at' => now(),
            'sheet_name' => 'Talangan',
            'sheet_row_number' => 2,
        ]);
    }

    private function payloadHash(array $row): string
    {
        $payload = collect(array_diff(DanaTalanganSpreadsheetContract::BUSINESS_HEADERS, ['No']))->mapWithKeys(fn (string $field) => [$field => (string) ($row[$field] ?? '')])->all();

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function contracts(array $rows): DanaTalanganSpreadsheetContract
    {
        $contract = new ResolvedDanaTalanganSpreadsheetContract('spreadsheet-id', 'Talangan', 7, DanaTalanganSpreadsheetContract::HEADERS, [], str_repeat('a', 64));
        $contracts = Mockery::mock(DanaTalanganSpreadsheetContract::class);
        $contracts->shouldReceive('resolve')->andReturn($contract);
        $contracts->shouldReceive('rows')->andReturn($rows);

        return $contracts;
    }

    private function bridge(DanaTalanganSpreadsheetContract $contracts, ?DanaTalanganSpreadsheetWriter $writer = null): DanaTalanganBridgeService
    {
        return new DanaTalanganBridgeService(
            app(DanaTalanganBridgeModeService::class),
            $contracts,
            $writer ?? Mockery::mock(DanaTalanganSpreadsheetWriter::class),
            app(SyncLockService::class),
        );
    }
}
