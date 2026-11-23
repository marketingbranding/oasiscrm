<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ConsumerApplication;
use App\Models\ConsumerLegacyIdentity;
use App\Models\ConsumerPsjb;
use App\Models\Customer;
use App\Models\Kavling;
use App\Models\KonsumenProgressSheetRow;
use App\Models\KonsumenProgressSyncStatus;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\User;
use App\Services\BranchParityReportService;
use App\Services\ConsumerReadComparisonService;
use App\Services\LegacyConsumerReadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class BranchParityReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_br32_branch_report_exposes_canonical_metrics_without_pii(): void
    {
        [$branch, $project] = $this->context();
        $application = ConsumerApplication::create([
            'customer_id' => Customer::create(['name' => 'Rahasia', 'phone' => '0812345678'])->id,
            'branch_id' => $branch->id,
            'project_id' => $project->id,
            'kavling_id' => Kavling::query()->where('project_id', $project->id)->value('id'),
            'transaction_status' => 'REJECT BANK',
            'current_stage' => 'akad',
            'akad_date' => '2026-11-01',
        ]);
        ConsumerLegacyIdentity::create(['consumer_application_id' => $application->id, 'customer_id' => $application->customer_id, 'legacy_source' => 'manual_spreadsheet_paste', 'external_key' => 'external:PARITY-1']);
        KonsumenProgressSheetRow::create(['branch_id' => $branch->id, 'sheet_id' => $branch->sheet_id, 'sheet_name' => 'data_konsumen', 'row_hash' => 'matching-row', 'row_data' => ['id_kavling' => 'K-01', 'nama_konsumen' => 'Rahasia', 'no_hp' => '0812345678', 'project_name' => $project->project_name, 'external_id' => 'PARITY-1', 'transaction_status' => 'REJECT BANK', 'tanggal_akad' => '2026-11-01']]);
        KonsumenProgressSheetRow::create(['branch_id' => $branch->id, 'sheet_id' => $branch->sheet_id, 'sheet_name' => 'akad', 'row_hash' => 'matching-stage', 'row_data' => ['id_kavling' => 'K-01', 'tanggal_akad' => '2026-11-01']]);
        KonsumenProgressSyncStatus::create(['branch_id' => $branch->id, 'status' => 'success', 'finished_at' => now()]);

        $report = app(BranchParityReportService::class)->report($branch);

        $this->assertSame('LULUS', $report->status, json_encode([$report->summary, $report->differences], JSON_THROW_ON_ERROR));
        $this->assertSame(1, $report->metrics['transactions_by_status']['MUNDUR']);
        $this->assertSame(0, $report->metrics['occupied_kavling']);
        $this->assertArrayNotHasKey('customer_name', $report->toArray());
        $this->assertStringNotContainsString('Rahasia', json_encode($report->toArray(), JSON_THROW_ON_ERROR));
        $this->assertSame($application->id, ConsumerApplication::query()->find($application->id)->id);
        $this->assertDatabaseHas('consumer_parity_snapshots', ['branch_id' => $branch->id, 'status' => 'LULUS']);
    }

    public function test_br32_cermin_kosong_is_tidak_lengkap(): void
    {
        [$branch] = $this->context();
        $report = app(BranchParityReportService::class)->report($branch);
        $this->assertSame('TIDAK_LENGKAP', $report->status);
        $this->assertStringContainsString('kosong', $report->summary['alasan_tidak_lengkap']);
    }

    public function test_br32_sinkron_gagal_is_tidak_lengkap(): void
    {
        [$branch] = $this->context();
        KonsumenProgressSheetRow::create(['branch_id' => $branch->id, 'sheet_id' => $branch->sheet_id, 'sheet_name' => 'data_konsumen', 'row_hash' => 'failed-sync-row', 'row_data' => ['id_kavling' => 'K-01', 'nama_konsumen' => 'Gagal']]);
        KonsumenProgressSyncStatus::create(['branch_id' => $branch->id, 'status' => 'failed', 'finished_at' => now()]);
        $report = app(BranchParityReportService::class)->report($branch);
        $this->assertSame('TIDAK_LENGKAP', $report->status);
    }

    public function test_br32_sinkron_basi_is_tidak_lengkap(): void
    {
        [$branch] = $this->context();
        KonsumenProgressSheetRow::create(['branch_id' => $branch->id, 'sheet_id' => $branch->sheet_id, 'sheet_name' => 'data_konsumen', 'row_hash' => 'stale-row', 'row_data' => ['id_kavling' => 'K-01', 'nama_konsumen' => 'Basi']]);
        KonsumenProgressSyncStatus::create(['branch_id' => $branch->id, 'status' => 'success', 'finished_at' => now()->subHours(25)]);
        $report = app(BranchParityReportService::class)->report($branch);
        $this->assertSame('TIDAK_LENGKAP', $report->status);
    }

    public function test_br32_sheet_only_and_oasis_only_are_gagal(): void
    {
        [$branch, $project] = $this->context();
        KonsumenProgressSheetRow::create(['branch_id' => $branch->id, 'sheet_id' => $branch->sheet_id, 'sheet_name' => 'data_konsumen', 'row_hash' => 'only-sheet', 'row_data' => ['id_kavling' => 'K-01', 'nama_konsumen' => 'Sheet']]);
        ConsumerApplication::create(['customer_id' => Customer::create(['name' => 'Oasis'])->id, 'branch_id' => $branch->id, 'project_id' => $project->id]);
        KonsumenProgressSyncStatus::create(['branch_id' => $branch->id, 'status' => 'success', 'finished_at' => now()]);
        $report = app(BranchParityReportService::class)->report($branch);
        $this->assertSame('GAGAL', $report->status);
        $this->assertGreaterThan(0, $report->summary['sheet_only'] + $report->summary['oasis_only']);
    }

    public function test_br32_status_and_akad_date_mismatch_are_reported(): void
    {
        [$branch, $project] = $this->context();
        $application = ConsumerApplication::create(['customer_id' => Customer::create(['name' => 'Mismatch'])->id, 'branch_id' => $branch->id, 'project_id' => $project->id, 'transaction_status' => 'LANJUT', 'akad_date' => '2026-01-02']);
        ConsumerLegacyIdentity::create(['consumer_application_id' => $application->id, 'legacy_source' => 'manual_spreadsheet_paste', 'external_key' => 'external:MISMATCH']);
        KonsumenProgressSheetRow::create(['branch_id' => $branch->id, 'sheet_id' => $branch->sheet_id, 'sheet_name' => 'data_konsumen', 'row_hash' => 'mismatch-row', 'row_data' => ['id_kavling' => 'K-01', 'nama_konsumen' => 'Mismatch', 'external_id' => 'MISMATCH', 'transaction_status' => 'MUNDUR', 'tanggal_akad' => '2026-01-03']]);
        KonsumenProgressSyncStatus::create(['branch_id' => $branch->id, 'status' => 'success', 'finished_at' => now()]);
        $report = app(BranchParityReportService::class)->report($branch);
        $this->assertSame('MISMATCH', $report->differences[0]['status']);
        $this->assertContains('status', $report->differences[0]['mismatch_fields']);
        $this->assertContains('akad_date', $report->differences[0]['mismatch_fields']);
    }

    public function test_br32_two_sheet_rows_same_kavling_with_distinct_transaction_ids_match_all(): void
    {
        [$branch, $project] = $this->context();
        foreach ([['Satu', 'TRX-1'], ['Dua', 'TRX-2']] as [$name, $transactionId]) {
            $application = ConsumerApplication::create(['customer_id' => Customer::create(['name' => $name])->id, 'branch_id' => $branch->id, 'project_id' => $project->id, 'id_transaksi' => $transactionId, 'kavling_id' => Kavling::where('project_id', $project->id)->value('id'), 'transaction_status' => 'LANJUT']);
            ConsumerLegacyIdentity::create(['consumer_application_id' => $application->id, 'legacy_source' => 'manual_spreadsheet_paste', 'external_key' => 'external:'.$transactionId]);
            KonsumenProgressSheetRow::create(['branch_id' => $branch->id, 'sheet_id' => $branch->sheet_id, 'sheet_name' => 'data_konsumen', 'row_hash' => $transactionId, 'row_data' => ['id_kavling' => 'K-01', 'nama_konsumen' => $name, 'id_transaksi' => $transactionId, 'external_id' => $transactionId]]);
        }
        $report = app(ConsumerReadComparisonService::class)->compare($branch, $project);
        $this->assertSame(2, $report->summary['matched']);
        $this->assertSame(0, $report->summary['ambiguous']);
    }

    public function test_br32_same_consumer_two_transactions_match_via_transaction(): void
    {
        [$branch, $project] = $this->context();
        foreach ([['Satu', 'TRX-1'], ['Dua', 'TRX-2']] as [$name, $transactionId]) {
            $application = ConsumerApplication::create(['customer_id' => Customer::create(['name' => $name])->id, 'branch_id' => $branch->id, 'project_id' => $project->id, 'id_transaksi' => $transactionId]);
            ConsumerPsjb::create(['consumer_application_id' => $application->id, 'id_kons' => 'KONS-SAMA', 'id_psjb' => 'PSJB-SAMA', 'tanggal_psjb' => '2026-01-01']);
            KonsumenProgressSheetRow::create(['branch_id' => $branch->id, 'sheet_id' => $branch->sheet_id, 'sheet_name' => 'data_konsumen', 'row_hash' => $transactionId, 'row_data' => ['id_kavling' => 'K-01', 'nama_konsumen' => $name, 'id_transaksi' => $transactionId, 'id_konsumen' => 'KONS-SAMA']]);
        }
        $report = app(ConsumerReadComparisonService::class)->compare($branch, $project);
        $this->assertSame(['TRANSACTION', 'TRANSACTION'], array_column($report->rows, 'match_key'));
    }

    public function test_br32_stage_data_does_not_leak_between_same_kavling_consumers(): void
    {
        [$branch, $project] = $this->context();
        KonsumenProgressSheetRow::create(['branch_id' => $branch->id, 'sheet_id' => $branch->sheet_id, 'sheet_name' => 'data_konsumen', 'row_hash' => 'mundur', 'row_data' => ['id_kavling' => 'K-01', 'nama_konsumen' => 'Mundur', 'id_transaksi' => 'TRX-MUNDUR', 'transaction_status' => 'MUNDUR']]);
        KonsumenProgressSheetRow::create(['branch_id' => $branch->id, 'sheet_id' => $branch->sheet_id, 'sheet_name' => 'data_konsumen', 'row_hash' => 'baru', 'row_data' => ['id_kavling' => 'K-01', 'nama_konsumen' => 'Baru', 'id_transaksi' => 'TRX-BARU', 'transaction_status' => 'LANJUT']]);
        KonsumenProgressSheetRow::create(['branch_id' => $branch->id, 'sheet_id' => $branch->sheet_id, 'sheet_name' => 'akad', 'row_hash' => 'akad-baru', 'row_data' => ['id_kavling' => 'K-01', 'id_transaksi' => 'TRX-BARU', 'tanggal_akad' => '2026-01-10']]);
        $records = app(LegacyConsumerReadService::class)->records($branch, $project);
        $this->assertNull(collect($records)->firstWhere('transactionId', 'TRX-MUNDUR')->akadDate);
        $this->assertSame('2026-01-10', collect($records)->firstWhere('transactionId', 'TRX-BARU')->akadDate);
    }

    public function test_br32_sheet_only_and_oasis_only_are_counted_separately(): void
    {
        [$branch, $project] = $this->context();
        KonsumenProgressSheetRow::create(['branch_id' => $branch->id, 'sheet_id' => $branch->sheet_id, 'sheet_name' => 'data_konsumen', 'row_hash' => 'sheet-only', 'row_data' => ['id_kavling' => 'K-01', 'nama_konsumen' => 'Sheet Only', 'external_id' => 'SHEET-ONLY']]);
        $application = ConsumerApplication::create(['customer_id' => Customer::create(['name' => 'Oasis Only'])->id, 'branch_id' => $branch->id, 'project_id' => $project->id, 'id_transaksi' => 'OASIS-ONLY']);
        $report = app(ConsumerReadComparisonService::class)->compare($branch, $project);
        $this->assertSame(1, $report->summary['legacy_only']);
        $this->assertSame(1, $report->summary['local_only']);
    }

    public function test_br32_legacy_status_selesai_matches_oasis_lanjut_without_mismatch(): void
    {
        [$branch, $project] = $this->context();
        $application = ConsumerApplication::create(['customer_id' => Customer::create(['name' => 'Selesai'])->id, 'branch_id' => $branch->id, 'project_id' => $project->id, 'transaction_status' => 'LANJUT']);
        ConsumerLegacyIdentity::create(['consumer_application_id' => $application->id, 'legacy_source' => 'manual_spreadsheet_paste', 'external_key' => 'external:SELESAI']);
        KonsumenProgressSheetRow::create(['branch_id' => $branch->id, 'sheet_id' => $branch->sheet_id, 'sheet_name' => 'data_konsumen', 'row_hash' => 'selesai-row', 'row_data' => ['id_kavling' => 'K-01', 'nama_konsumen' => 'Selesai', 'external_id' => 'SELESAI', 'transaction_status' => 'SELESAI']]);
        $report = app(ConsumerReadComparisonService::class)->compare($branch, $project);
        $this->assertNotContains('status', $report->rows[0]['mismatch_fields']);
    }

    public function test_br32_id_konsumen_and_psjb_matching_records_match_key(): void
    {
        [$branch, $project] = $this->context();
        $application = ConsumerApplication::create(['customer_id' => Customer::create(['name' => 'ID Match'])->id, 'branch_id' => $branch->id, 'project_id' => $project->id]);
        ConsumerPsjb::create(['consumer_application_id' => $application->id, 'id_kons' => 'KONS-1', 'id_psjb' => 'PSJB-1', 'tanggal_psjb' => '2026-01-01']);
        KonsumenProgressSheetRow::create(['branch_id' => $branch->id, 'sheet_id' => $branch->sheet_id, 'sheet_name' => 'data_konsumen', 'row_hash' => 'id-match', 'row_data' => ['id_kavling' => 'K-01', 'nama_konsumen' => 'ID Match', 'id_konsumen' => 'KONS-1', 'id_psjb' => 'PSJB-1']]);
        KonsumenProgressSyncStatus::create(['branch_id' => $branch->id, 'status' => 'success', 'finished_at' => now()]);
        $report = app(ConsumerReadComparisonService::class)->compare($branch, $project);
        $this->assertSame('CONSUMER', $report->rows[0]['match_key']);
    }

    public function test_br32_psjb_is_highest_available_fallback_after_empty_transaction_and_consumer(): void
    {
        [$branch, $project] = $this->context();
        $application = ConsumerApplication::create(['customer_id' => Customer::create(['name' => 'PSJB Match'])->id, 'branch_id' => $branch->id, 'project_id' => $project->id]);
        ConsumerPsjb::create(['consumer_application_id' => $application->id, 'id_psjb' => 'PSJB-ONLY', 'tanggal_psjb' => '2026-01-01']);
        KonsumenProgressSheetRow::create(['branch_id' => $branch->id, 'sheet_id' => $branch->sheet_id, 'sheet_name' => 'data_konsumen', 'row_hash' => 'psjb-only', 'row_data' => ['id_kavling' => 'K-01', 'nama_konsumen' => 'PSJB Match', 'id_psjb' => 'PSJB-ONLY']]);
        $report = app(ConsumerReadComparisonService::class)->compare($branch, $project);
        $this->assertSame('PSJB', $report->rows[0]['match_key']);
    }

    public function test_br32_duplicate_oasis_identity_is_ambiguous(): void
    {
        [$branch, $project] = $this->context();
        foreach (['Satu', 'Dua'] as $name) {
            $application = ConsumerApplication::create(['customer_id' => Customer::create(['name' => $name])->id, 'branch_id' => $branch->id, 'project_id' => $project->id]);
            ConsumerLegacyIdentity::create(['consumer_application_id' => $application->id, 'legacy_source' => 'manual_spreadsheet_paste', 'external_key' => 'external:DUP']);
        }
        KonsumenProgressSheetRow::create(['branch_id' => $branch->id, 'sheet_id' => $branch->sheet_id, 'sheet_name' => 'data_konsumen', 'row_hash' => 'duplicate-oasis', 'row_data' => ['id_kavling' => 'K-01', 'nama_konsumen' => 'Sheet', 'external_id' => 'DUP']]);
        $report = app(ConsumerReadComparisonService::class)->compare($branch, $project);
        $this->assertSame('AMBIGUOUS', $report->rows[0]['status']);
    }

    public function test_br32_duplicate_sheet_identity_is_ambiguous(): void
    {
        [$branch, $project] = $this->context();
        foreach (['Satu', 'Dua'] as $index => $name) {
            KonsumenProgressSheetRow::create(['branch_id' => $branch->id, 'sheet_id' => $branch->sheet_id, 'sheet_name' => 'data_konsumen', 'row_hash' => 'duplicate-sheet-'.$index, 'row_data' => ['id_kavling' => 'K-01', 'nama_konsumen' => $name, 'id_transaksi' => 'TRX-DUP']]);
        }
        $application = ConsumerApplication::create(['customer_id' => Customer::create(['name' => 'Oasis'])->id, 'branch_id' => $branch->id, 'project_id' => $project->id]);
        $report = app(ConsumerReadComparisonService::class)->compare($branch, $project);
        $this->assertSame('AMBIGUOUS', $report->rows[0]['status']);
    }

    public function test_br32_impersonating_user_cannot_run_parity(): void
    {
        [$branch] = $this->context();
        $actor = User::factory()->create(['role_id' => Role::where('slug', 'superadmin')->value('id'), 'password_changed_at' => now()]);
        $target = User::factory()->create(['role_id' => Role::where('slug', 'admin')->value('id'), 'password_changed_at' => now()]);
        $this->actingAs($target)->withSession(['impersonation.original_user_id' => $actor->id, 'impersonation.target_user_id' => $target->id, 'impersonation.started_at' => now()->toIso8601String()])->post(route('admin.branch-parity.run', $branch))->assertForbidden();
    }

    public function test_br32_legacy_key_regression_keeps_external_format(): void
    {
        [$branch, $project] = $this->context();
        KonsumenProgressSheetRow::create(['branch_id' => $branch->id, 'sheet_id' => $branch->sheet_id, 'sheet_name' => 'data_konsumen', 'row_hash' => 'legacy-key', 'row_data' => ['id_kavling' => 'K-01', 'nama_konsumen' => 'Legacy', 'external_id' => 'LEGACY-1', 'project_name' => $project->project_name]]);
        $records = app(LegacyConsumerReadService::class)->records($branch, $project);
        $this->assertSame('external:legacy-1', $records[0]->legacyKey);
    }

    public function test_br32_http_get_is_read_only_and_post_creates_snapshot_with_redirect(): void
    {
        [$branch] = $this->context();
        $admin = User::factory()->create(['role_id' => Role::where('slug', 'superadmin')->value('id'), 'password_changed_at' => now()]);
        $this->actingAs($admin)->get(route('admin.branch-parity.index', ['branch' => $branch->id]))->assertOk()->assertSee('Belum pernah diperiksa.');
        $this->assertDatabaseCount('consumer_parity_snapshots', 0);
        $this->actingAs($admin)->post(route('admin.branch-parity.run', $branch))->assertRedirect();
        $this->assertDatabaseCount('consumer_parity_snapshots', 1);
    }

    public function test_br32_non_superadmin_is_forbidden(): void
    {
        [$branch] = $this->context();
        $user = User::factory()->create(['role_id' => Role::where('slug', 'admin')->value('id'), 'password_changed_at' => now()]);
        $this->actingAs($user)->get(route('admin.branch-parity.index', ['branch' => $branch->id]))->assertForbidden();
    }

    public function test_br32_xlsx_export_excludes_consumer_pii(): void
    {
        [$branch] = $this->context();
        $admin = User::factory()->create(['role_id' => Role::where('slug', 'superadmin')->value('id'), 'password_changed_at' => now()]);
        $response = $this->actingAs($admin)->get(route('admin.branch-parity.export', $branch));
        $path = $response->baseResponse->getFile()->getPathname();
        $text = '';
        foreach (IOFactory::load($path)->getWorksheetIterator() as $sheet) {
            $text .= json_encode($sheet->toArray(), JSON_THROW_ON_ERROR);
        }
        $this->assertStringNotContainsString('Rahasia', $text);
        $this->assertStringNotContainsString('0812345678', $text);
    }

    public function test_br32_branch_report_marks_sheet_only_rows(): void
    {
        [$branch, $project] = $this->context();
        KonsumenProgressSheetRow::create([
            'branch_id' => $branch->id,
            'sheet_id' => $branch->sheet_id,
            'sheet_name' => 'data_konsumen',
            'row_hash' => 'branch-parity-row',
            'row_data' => ['id_kavling' => 'K-01', 'nama_konsumen' => 'Sheet Only', 'project_name' => $project->project_name, 'external_id' => 'SHEET-1'],
        ]);
        KonsumenProgressSyncStatus::create(['branch_id' => $branch->id, 'status' => 'success', 'finished_at' => now()]);

        $report = app(BranchParityReportService::class)->report($branch);

        $this->assertSame('GAGAL', $report->status);
        $this->assertSame(1, $report->summary['sheet_only']);
        $this->assertSame('LEGACY_ONLY', $report->differences[0]['status']);
    }

    private function context(): array
    {
        $branch = Branch::create(['name' => 'Parity Branch', 'code' => 'PB01', 'sheet_id' => 'parity-sheet', 'is_active' => true]);
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Parity Project', 'is_active' => true]);
        Kavling::create(['project_id' => $project->id, 'kavling_code' => 'K-01', 'name' => 'K-01']);

        return [$branch, $project];
    }
}
