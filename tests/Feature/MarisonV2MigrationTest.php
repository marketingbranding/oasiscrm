<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ConsumerApplication;
use App\Models\ConsumerMigrationReconciliation;
use App\Models\Kavling;
use App\Models\LeadMaster;
use App\Models\MarisonImportBatch;
use App\Models\MarisonProjectMapping;
use App\Models\Role;
use App\Models\User;
use App\Services\MarisonV2ImportPreviewService;
use App\Services\MarisonV2ImportService;
use App\Services\MarisonV2PackageValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class MarisonV2MigrationTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private LeadMaster $project;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::create(['code' => 'MGL', 'name' => 'Magelang', 'is_active' => true]);
        $this->project = LeadMaster::create(['branch_id' => $this->branch->id, 'project_name' => 'Kaliangkrik', 'is_active' => true]);
        Kavling::create(['project_id' => $this->project->id, 'kavling_code' => 'A-01', 'name' => 'A-01']);
        MarisonProjectMapping::create(['source_system' => 'marison_v2', 'branch_code' => 'MGL', 'source_project_id' => 'PRJ-MGL-KAL', 'oasis_project_id' => $this->project->id]);
        $this->admin = User::factory()->create(['role_id' => Role::where('slug', 'superadmin')->value('id'), 'email_verified_at' => now(), 'password_changed_at' => now()]);
    }

    public function test_superadmin_upload_page_renders_without_writes(): void
    {
        $before = [MarisonImportBatch::count(), ConsumerApplication::count()];
        $this->actingAs($this->admin)->get(route('admin.marison-migrations.create'))
            ->assertOk()
            ->assertSee('Migrasi Marison V2')
            ->assertSee('Paket JSON')
            ->assertSee('Pemetaan Proyek Eksplisit')
            ->assertSee('Validasi &amp; Preview', false);
        $this->assertSame($before, [MarisonImportBatch::count(), ConsumerApplication::count()]);
    }

    public function test_valid_preview_and_superadmin_ui_authorization(): void
    {
        $file = $this->file($this->package());
        $response = $this->actingAs($this->admin)->post(route('admin.marison-migrations.preview'), ['package' => $file]);
        $batch = MarisonImportBatch::sole();
        $response->assertRedirect(route('admin.marison-migrations.batches.show', $batch));
        $this->assertSame('READY', $batch->transactions()->sole()->outcome);
        $this->assertSame(1, data_get($batch->counts, 'outcomes.READY'));
        $this->actingAs($this->user('manager'))->get(route('admin.marison-migrations.create'))->assertForbidden();
    }

    public function test_malformed_wrong_contract_forbidden_pii_unknown_branch_and_missing_mapping_are_blocked(): void
    {
        foreach ([
            '{broken' => 'package',
            $this->packageJson($this->package(['contract_version' => '2.0'])) => 'contract_version',
            $this->packageJson($this->package([], ['nama_konsumen' => 'Rahasia'])) => 'package.transactions.0.nama_konsumen',
        ] as $json => $key) {
            try {
                app(MarisonV2PackageValidator::class)->validate($this->path($json));
                $this->fail('Invalid package accepted.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($key, $e->errors());
            }
        }
        $unknown = $this->package(['source' => array_merge($this->package()['source'], ['branch_code' => 'XXX'])], ['branch_code' => 'XXX']);
        $batch = app(MarisonV2ImportPreviewService::class)->stage($this->path($this->packageJson($unknown)), $this->admin);
        $this->assertSame('BLOCKED', $batch->transactions()->sole()->outcome);
        MarisonProjectMapping::query()->delete();
        $batch = app(MarisonV2ImportPreviewService::class)->stage($this->path($this->packageJson($this->package())), $this->admin);
        $this->assertSame('BLOCKED', $batch->transactions()->sole()->outcome);
    }

    public function test_import_maps_all_processes_without_customer_or_kavling_assignment(): void
    {
        $batch = $this->stage();
        $result = $this->confirm($batch);
        $this->assertSame(1, $result['created']);
        $app = ConsumerApplication::sole();
        $this->assertNull($app->customer_id);
        $this->assertNull($app->kavling_id);
        $this->assertNull($app->nama_konsumen);
        $this->assertNull($app->nik);
        $this->assertSame('migration_pending', $app->application_status);
        $this->assertSame('bast', $app->current_stage);
        $this->assertDatabaseCount('consumer_kavling_assignments', 0);
        $this->assertDatabaseCount('consumer_psjbs', 1);
        $this->assertDatabaseCount('consumer_bank_processes', 2);
        $this->assertDatabaseCount('consumer_ppjb_developers', 1);
        $this->assertDatabaseCount('consumer_akad_records', 1);
        $this->assertDatabaseCount('consumer_bast_records', 1);
        $this->assertDatabaseHas('consumer_stage_events', ['stage' => 'bi_checking', 'source_id' => 'KONS-1']);
        $this->assertDatabaseHas('consumer_stage_events', ['stage' => 'migration_baseline', 'source_id' => 'STAT-0']);
        $this->assertSame('SELESAI', ConsumerMigrationReconciliation::sole()->suggested_decision);
    }

    public function test_bank_attempts_merge_pemberkasan_and_proses_bank_and_sp3k_never_comes_from_ppjb(): void
    {
        $this->confirm($this->stage());
        $first = DB::table('consumer_bank_processes')->where('source_id', 'ATT-1')->first();
        $second = DB::table('consumer_bank_processes')->where('source_id', 'ATT-2')->first();
        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame('BERKAS-1', $first->id_berkas);
        $this->assertSame('SP3K-CANON', $first->no_sp3k);
        $this->assertSame('2026-05-10 00:00:00', $first->sp3k_at);
        $ppjb = DB::table('consumer_ppjb_developers')->first();
        $this->assertStringStartsWith('2026-05-10', $ppjb->tanggal_sp3k);
        $this->assertNotSame('2099-01-01', $ppjb->tanggal_sp3k);
    }

    public function test_unknown_kavling_becomes_needs_review_and_is_not_imported(): void
    {
        $package = $this->package();
        $package['transactions'][0]['current_kavling'] = 'UNKNOWN-99';
        $package['manifest']['counts'] = $this->counts($package);
        $batch = $this->stage($package);
        $this->assertSame('NEEDS_REVIEW', $batch->transactions()->sole()->outcome);
        $result = $this->confirm($batch);
        $this->assertSame(0, $result['created']);
        $this->assertDatabaseCount('consumer_applications', 0);
    }

    public function test_same_payload_is_already_imported_and_changed_payload_requires_review(): void
    {
        $package = $this->package();
        $this->confirm($this->stage($package));
        $same = $this->stage($package);
        $this->assertSame('ALREADY_IMPORTED', $same->transactions()->sole()->outcome);
        $changedTransaction = $package['transactions'][0];
        $changedTransaction['payment']['detail'] = 'Berubah';
        $changed = $this->stage($this->package([], $changedTransaction));
        $this->assertSame('SOURCE_CHANGED_REVIEW', $changed->transactions()->sole()->outcome);
        $this->assertDatabaseCount('consumer_applications', 1);
    }

    public function test_unlinked_preserved_and_baseline_does_not_advance_stage(): void
    {
        $package = $this->package();
        $package['transactions'][0]['process'] = array_fill_keys(['bi_checking', 'psjb', 'pemberkasan', 'bank_attempts', 'proses_bank', 'ppjb_dev', 'akad', 'bast'], []);
        $package['transactions'][0]['history']['status'] = [['event_id' => 'STAT-0', 'event_type' => 'MIGRATION_BASELINE', 'event_timestamp' => '2026-01-01T00:00:00+07:00']];
        $package['manifest']['counts'] = $this->counts($package);
        $batch = $this->stage($package);
        $this->assertDatabaseHas('marison_unlinked_records', ['source_sheet' => 'orphan_sheet', 'reason' => 'Tidak punya transaksi']);
        $this->confirm($batch);
        $this->assertNull(ConsumerApplication::sole()->current_stage);
    }

    public function test_duplicate_process_source_id_across_transactions_is_rejected(): void
    {
        $package = $this->package();
        $second = $package['transactions'][0];
        $second['id_transaksi_v2'] = 'TRX-2';
        $package['transactions'][] = $second;
        $package['manifest']['counts'] = $this->counts($package);
        $this->expectException(ValidationException::class);
        app(MarisonV2PackageValidator::class)->validate($this->path($this->packageJson($package)));
    }

    public function test_bi_identity_is_scoped_to_transaction_while_document_ids_remain_strict(): void
    {
        $package = $this->package();
        $second = $package['transactions'][0];
        $second['id_transaksi_v2'] = 'TRX-2';
        $second['process'] = array_fill_keys(
            ['bi_checking', 'psjb', 'pemberkasan', 'bank_attempts', 'proses_bank', 'ppjb_dev', 'akad', 'bast'],
            [],
        );
        $second['process']['bi_checking'] = $package['transactions'][0]['process']['bi_checking'];
        $second['history'] = ['kavling' => [], 'status' => []];
        $package['transactions'][] = $second;
        $package['manifest']['counts'] = $this->counts($package);

        $batch = $this->stage($package);
        $this->assertSame(2, $batch->transactions()->where('outcome', MarisonV2ImportPreviewService::STATUS_READY)->count());

        $result = $this->confirm($batch);
        $this->assertSame(2, $result['created']);
        $this->assertSame(2, DB::table('consumer_stage_events')->where('stage', 'bi_checking')->where('source_id', 'KONS-1')->count());
        $this->assertDatabaseHas('consumer_migration_source_records', [
            'source_type' => 'bi_checking_event',
            'source_record_id' => 'TRX-1:KONS-1',
        ]);
        $this->assertDatabaseHas('consumer_migration_source_records', [
            'source_type' => 'bi_checking_event',
            'source_record_id' => 'TRX-2:KONS-1',
        ]);
    }

    public function test_stale_preview_rejected_and_transaction_rollback_leaves_no_partial_rows(): void
    {
        $batch = $this->stage();
        $this->expectException(ConflictHttpException::class);
        app(MarisonV2ImportService::class)->confirm($batch, $this->admin, str_repeat('0', 64), 1);
    }

    public function test_blocked_package_cannot_confirm_and_no_partial_rows_exist(): void
    {
        MarisonProjectMapping::query()->delete();
        $batch = $this->stage();
        try {
            $this->confirm($batch);
            $this->fail('Blocked batch confirmed.');
        } catch (ConflictHttpException) {
        }
        $this->assertDatabaseCount('consumer_applications', 0);
        $this->assertDatabaseCount('consumer_stage_events', 0);
    }

    private function stage(?array $package = null): MarisonImportBatch
    {
        return app(MarisonV2ImportPreviewService::class)->stage($this->path($this->packageJson($package ?? $this->package())), $this->admin);
    }

    private function confirm(MarisonImportBatch $batch): array
    {
        return app(MarisonV2ImportService::class)->confirm($batch, $this->admin, $batch->preview_hash, $batch->preview_version);
    }

    private function package(array $root = [], array $transaction = []): array
    {
        $baseTransaction = [
            'id_transaksi_v2' => 'TRX-1', 'id_konsumen_v2' => 'SRC-CUST-1', 'branch_code' => 'MGL', 'project_source_id' => 'PRJ-MGL-KAL', 'current_kavling' => 'A-01',
            'payment' => ['method' => 'KPR', 'detail' => 'FLPP'], 'source_state' => ['status_transaksi' => 'Lanjut', 'status_bank' => 'SP3K', 'status_kavling' => 'Booking', 'tahap_terkini' => 'BAST'], 'lineage' => ['confidence' => 'HIGH'],
            'process' => [
                'bi_checking' => [['id_kons' => 'KONS-1', 'tanggal_slik' => '2026-01-02', 'hasil_slik' => 'Lolos', 'keterangan' => 'OK']],
                'psjb' => [['id_psjb' => 'PSJB-1', 'id_kons' => 'KONS-1', 'tanggal_psjb' => '2026-02-01', 'harga_unit' => 150000000, 'tanggal_utj' => '2026-02-02', 'utj' => 1000000, 'dp_all_in' => 5000000, 'nominal_cicilan' => 1000000, 'jumlah_cicilan' => 10, 'luas_klt_m2' => 60, 'harga_klt/m' => 1000000, 'harga_klt_total' => 60000000, 'cara_pembayaran' => 'KPR']],
                'pemberkasan' => [['id_bank_attempt' => 'ATT-1', 'id_berkas' => 'BERKAS-1', 'tanggal_terima_bank' => '2026-03-01', 'bank' => 'BTN', 'kc/unit' => 'Magelang', 'request_plafond' => 140000000, 'request_tenor' => 20, 'status' => 'Dikirim']],
                'bank_attempts' => [['id_bank_attempt' => 'ATT-1', 'id_berkas' => 'BERKAS-1', 'attempt_no' => 1, 'bank' => 'BTN'], ['id_bank_attempt' => 'ATT-2', 'id_berkas' => 'BERKAS-2', 'attempt_no' => 2, 'bank' => 'BRI']],
                'proses_bank' => [['id_bank_attempt' => 'ATT-1', 'id_berkas' => 'BERKAS-1', 'no_sp3k' => 'SP3K-CANON', 'tanggal_sp3k' => '2026-05-10', 'jenis_respon' => 'Approved', 'approved_plafond' => 135000000, 'approved_tenor' => 20, 'status' => 'SP3K']],
                'ppjb_dev' => [['id_ppjb_dev' => 'PPJB-1', 'tanggal_sp3k' => '2099-01-01', 'no_sp3k' => 'MIRROR-WRONG', 'tanggal_ttd_ppjb' => '2026-05-20', 'status' => 'Selesai']],
                'akad' => [['no_ppjb_akad' => 'AKAD-1', 'tanggal_akad' => '2026-06-01', 'kualitas_akad' => 'Baik', 'status_dp' => 'Lunas', 'status_utilitas' => 'Aktif', 'progress_bangunan' => '100%', 'detail_kendala' => null]],
                'bast' => [['no_bast' => 'BAST-1', 'tanggal_bast' => '2026-06-10', 'status' => 'Selesai', 'keterangan' => 'Diserahkan']],
            ],
            'history' => ['kavling' => [['event_id' => 'KAV-1', 'event_timestamp' => '2026-01-01T00:00:00+07:00', 'kavling_lama' => null, 'kavling_baru' => 'A-01', 'event_type' => 'BOOKING']], 'status' => [['event_id' => 'STAT-0', 'event_type' => 'MIGRATION_BASELINE', 'event_timestamp' => '2025-12-01T00:00:00+07:00']]],
        ];
        $package = [
            'contract' => 'marison-v2-to-oasis', 'contract_version' => '1.0',
            'source' => ['branch_code' => 'MGL', 'spreadsheet_id' => 'SHEET-MGL', 'v2_version' => 'V2.5.50', 'exported_at' => '2026-09-18T10:00:00+07:00'],
            'manifest' => ['counts' => []], 'transactions' => [array_replace_recursive($baseTransaction, $transaction)],
            'unlinked_records' => [['source_sheet' => 'orphan_sheet', 'source_row' => 99, 'reason' => 'Tidak punya transaksi', 'payload' => ['id_berkas' => 'ORPHAN-1']]],
        ];
        $package = array_replace_recursive($package, $root);
        $package['manifest']['counts'] = $this->counts($package);

        return $package;
    }

    private function counts(array $package): array
    {
        $counts = array_fill_keys(['transactions', 'bi_checking', 'psjb', 'pemberkasan', 'bank_attempts', 'proses_bank', 'ppjb_dev', 'akad', 'bast', 'kavling_events', 'status_events', 'unlinked_records'], 0);
        $counts['transactions'] = count($package['transactions']);
        $counts['unlinked_records'] = count($package['unlinked_records']);
        foreach ($package['transactions'] as $trx) {
            foreach (['bi_checking', 'psjb', 'pemberkasan', 'bank_attempts', 'proses_bank', 'ppjb_dev', 'akad', 'bast'] as $k) {
                $counts[$k] += count($trx['process'][$k]);
            } $counts['kavling_events'] += count($trx['history']['kavling']);
            $counts['status_events'] += count($trx['history']['status']);
        }

        return $counts;
    }

    private function user(string $role): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', $role)->value('id'), 'email_verified_at' => now(), 'password_changed_at' => now()]);
    }

    private function packageJson(array $package): string
    {
        return json_encode($package, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    private function path(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'marison-');
        file_put_contents($path, $contents);

        return $path;
    }

    private function file(array $package): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('marison.json', $this->packageJson($package));
    }
}
