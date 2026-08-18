<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\ConsumerApplication;
use App\Models\ConsumerBankProcess;
use App\Models\ConsumerLegacyIdentity;
use App\Models\ConsumerStageEvent;
use App\Models\HistoricalProcessImportBatch;
use App\Models\Role;
use App\Models\User;
use App\Services\ConsumerKavlingBackfillService;
use App\Services\HistoricalProcessImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class HistoricalProcessImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_canonical_headers_per_stage_are_accepted_and_normalized(): void
    {
        $service = app(HistoricalProcessImportService::class);

        $bi = "id_kavling\tnama_konsumen\tno_ktp\tid_kons\ttanggal_slik\thasil_slik\tketerangan\nKAV-1\tBudi\t0123456789012345\tK-001\t8/1/2026\tLancar\t";
        $rows = $service->parse($bi);
        $this->assertSame('bi_checking', $rows[0]['sheet_type']);
        $this->assertSame('2026-08-01', $rows[0]['normalized_data']['tanggal_slik']);
        $this->assertSame('0123456789012345', $rows[0]['nik']);
        $this->assertArrayNotHasKey('no_ktp', $rows[0]['normalized_data']);
        $this->assertArrayNotHasKey('no_ktp', $rows[0]['raw_data']);

        $psjb = "id_kavling\tid_kons\tid_psjb\ttanggal_psjb\tnama_koordinator\tnama_sales\tharga_unit\ttanggal_utj\tutj\ttanggal_dp_klt\tdp_all_in\tnominal_cicilan\tjumlah_cicilan\tluas_klt\tharga_klt/m\tharga_klt_total\tcara_pembayaran\tid_promo\tlead_time_hari\tstatus\tketerangan\nKAV-1\tK-001\tPS-1\t2026-08-02\tKoor\tSales\t150000000\t\t\t\t\t\t\t0\t0\t0\tTunai\t\t\t\t";
        $rows = $service->parse($psjb);
        $this->assertSame('PSJB', $rows[0]['sheet_type']);
        $this->assertSame('2026-08-02', $rows[0]['normalized_data']['tanggal_psjb']);
        $this->assertSame('150000000.00', $rows[0]['normalized_data']['harga_unit']);

        $pemberkasan = "id_kavling\tid_psjb\tid_berkas\ttanggal_terima_bank\tbank\tkc/unit\trequest_plafond\trequest_tenor\ttipe_pemberkasan\tlead_time_hari\tstatus\tketerangan\nKAV-1\tPS-1\tB-1\t2026-08-03\tBCA\tKC A\t200000000\t240\tReguler\t\t\t";
        $this->assertSame('pemberkasan', $service->parse($pemberkasan)[0]['sheet_type']);

        $prosesBank = "id_kavling\tid_berkas\tno_sp3k\tjenis_respon\tapproved_plafond\tapproved_tenor\tlead_time_hari\tstatus\tkategori_revisi\tdetail_revisi\tkendala\tketerangan\nKAV-1\tB-1\tSP-1\tApproved\t200000000\t240\t\t\t\t\t\t";
        $this->assertSame('proses_bank', $service->parse($prosesBank)[0]['sheet_type']);

        $ppjbDev = "id_kavling\tno_sp3k\tid_ppjb_dev\ttanggal_sp3k\ttanggal_ttd_ppjb\tlead_time_hari\tstatus\tketerangan\nKAV-1\tSP-1\tPP-1\t2026-08-04\t2026-08-05\t\t\t";
        $this->assertSame('ppjb_dev', $service->parse($ppjbDev)[0]['sheet_type']);

        $akad = "id_kavling\tid_ppjb_dev\tno_ppjb_akad\ttanggal_akad\tkualitas_akad\tlead_time_hari\tstatus\tstatus_bangunan\tstatus_dp_konsumen\tstatus_utilitas\tstatus_konsumen\tketerangan_terlambat\tketerangan\nKAV-1\tPP-1\tAK-1\t2026-09-01\tBaik\t\tAktif\t\t\t\t\t\t";
        $this->assertSame('akad', $service->parse($akad)[0]['sheet_type']);

        $bast = "id_kavling\tno_ppjb_akad\tno_bast\ttanggal_bast\tlead_time_hari\tstatus\tketerangan\nKAV-1\tAK-1\tBast-1\t2026-10-01\t\t\t";
        $this->assertSame('bast', $service->parse($bast)[0]['sheet_type']);
    }

    public function test_unknown_or_reordered_headers_are_rejected(): void
    {
        $service = app(HistoricalProcessImportService::class);
        $caught = 0;

        try {
            $service->parse("nama_konsumen\tid_kavling\tno_ktp\tid_kons\ttanggal_slik\thasil_slik\tketerangan\nBudi\tKAV-1\t0123456789012345\tK-001\t\t\t");
        } catch (InvalidArgumentException) {
            $caught++;
        }

        try {
            $service->parse("id_kavling\tnama_konsumen\tno_ktp\tid_kons\ttanggal_slik\thasil_slik\nKAV-1\tBudi\t0123456789012345\tK-001\t\t");
        } catch (InvalidArgumentException) {
            $caught++;
        }

        try {
            $service->parse("id_kavling\tnama_konsumen\tno_ktp\tid_kons\ttanggal_slik\tspelled_wrong\tketerangan\nKAV-1\tBudi\t0123456789012345\tK-001\t\t\t");
        } catch (InvalidArgumentException) {
            $caught++;
        }

        $this->assertSame(3, $caught);
    }

    public function test_preview_stages_rows_without_writing_consumer_tables(): void
    {
        [$branch, $superadmin] = $this->context();
        $bi = "id_kavling\tnama_konsumen\tno_ktp\tid_kons\ttanggal_slik\thasil_slik\tketerangan\nKAV-1\tBudi\t0123456789012345\tK-001\t8/1/2026\tLancar\t";

        $this->actingAs($superadmin)->post(route('historical-process.import.preview'), ['branch_id' => $branch->id, 'tsv' => $bi])->assertRedirect();

        $this->assertSame(0, ConsumerApplication::count());
        $this->assertSame(0, ConsumerLegacyIdentity::count());
        $this->assertSame(0, ConsumerStageEvent::count());
        $this->assertSame(0, ConsumerBankProcess::count());
        $batch = HistoricalProcessImportBatch::sole();
        $this->assertSame('preview_ready', $batch->status);
        $this->assertSame(1, $batch->rows()->count());
        $this->assertSame('Baru', $batch->rows()->first()->status);
    }

    public function test_confirm_creates_application_and_stage_chain_with_encrypted_nik(): void
    {
        [$branch, $superadmin] = $this->context();
        $this->stage($superadmin, $branch->id, "id_kavling\tnama_konsumen\tno_ktp\tid_kons\ttanggal_slik\thasil_slik\tketerangan\nKAV-1\tBudi\t0123456789012345\tK-001\t8/1/2026\tLancar\t");

        $application = ConsumerApplication::sole();
        $this->assertSame('KAV-1', $application->id_kavling);
        $this->assertSame('Budi', $application->nama_konsumen);
        $this->assertSame('0123456789012345', $application->nik);
        $this->assertNotSame('0123456789012345', $application->getRawOriginal('nik'));
        $this->assertSame('K-001', $application->legacyIdentity->id_kons);
        $event = ConsumerStageEvent::sole();
        $this->assertSame('bi_checking', $event->stage);
        $this->assertSame('K-001', $event->source_id);
        $this->assertSame('2026-08-01', $event->event_date->format('Y-m-d'));
        $this->assertStringNotContainsString('0123456789012345', $event->notes);
        $audit = ActivityLog::where('event', 'historical_process_imported')->sole();
        $this->assertStringNotContainsString('0123456789012345', json_encode($audit->properties));
    }

    public function test_downstream_stages_follow_canonical_id_chain_not_kavling(): void
    {
        [$branch, $superadmin] = $this->context();
        $this->stage($superadmin, $branch->id, "id_kavling\tnama_konsumen\tno_ktp\tid_kons\ttanggal_slik\thasil_slik\tketerangan\nKAV-1\tBudi\t0123456789012345\tK-001\t8/1/2026\tLancar\t");
        $this->stage($superadmin, $branch->id, "id_kavling\tid_kons\tid_psjb\ttanggal_psjb\tnama_koordinator\tnama_sales\tharga_unit\ttanggal_utj\tutj\ttanggal_dp_klt\tdp_all_in\tnominal_cicilan\tjumlah_cicilan\tluas_klt\tharga_klt/m\tharga_klt_total\tcara_pembayaran\tid_promo\tlead_time_hari\tstatus\tketerangan\nKAV-1\tK-001\tPS-1\t2026-08-02\tKoor\tSales\t150000000\t\t\t\t\t\t\t0\t0\t0\tTunai\t\t\t\t");

        $application = ConsumerApplication::sole();
        $this->assertSame('K-001', $application->legacyIdentity->id_kons);
        $this->assertSame('PS-1', $application->legacyIdentity->id_psjb);
        $this->assertSame(2, $application->stageEvents()->count());
        $this->assertDatabaseHas('consumer_stage_events', ['application_id' => $application->id, 'stage' => 'PSJB', 'source_id' => 'PS-1']);
    }

    public function test_downstream_row_without_existing_chain_is_flagged_and_skipped(): void
    {
        [$branch, $superadmin] = $this->context();
        $psjb = "id_kavling\tid_kons\tid_psjb\ttanggal_psjb\tnama_koordinator\tnama_sales\tharga_unit\ttanggal_utj\tutj\ttanggal_dp_klt\tdp_all_in\tnominal_cicilan\tjumlah_cicilan\tluas_klt\tharga_klt/m\tharga_klt_total\tcara_pembayaran\tid_promo\tlead_time_hari\tstatus\tketerangan\nKAV-1\tK-999\tPS-1\t2026-08-02\tKoor\tSales\t0\t\t\t\t\t\t\t0\t0\t0\tTunai\t\t\t\t";

        $this->actingAs($superadmin)->post(route('historical-process.import.preview'), ['branch_id' => $branch->id, 'tsv' => $psjb])->assertRedirect();
        $batch = HistoricalProcessImportBatch::sole();
        $this->assertSame('Perlu Diperiksa', $batch->rows()->first()->status);

        $this->actingAs($superadmin)->post(route('historical-process.import.confirm', $batch), ['expected_updated_at' => $batch->updated_at->toISOString()])->assertRedirect();
        $this->assertSame(0, ConsumerApplication::count());
        $this->assertSame(0, ConsumerStageEvent::count());
        $this->assertSame(0, $batch->fresh()->created_rows);
    }

    public function test_reimport_same_source_id_is_idempotent_and_skipped(): void
    {
        [$branch, $superadmin] = $this->context();
        $bi = "id_kavling\tnama_konsumen\tno_ktp\tid_kons\ttanggal_slik\thasil_slik\tketerangan\nKAV-1\tBudi\t0123456789012345\tK-001\t8/1/2026\tLancar\t";
        $this->stage($superadmin, $branch->id, $bi);

        $this->actingAs($superadmin)->post(route('historical-process.import.preview'), ['branch_id' => $branch->id, 'tsv' => $bi])->assertRedirect();
        $batch = HistoricalProcessImportBatch::latest('id')->firstOrFail();
        $this->assertSame('Duplikat', $batch->rows()->first()->status);
        $this->actingAs($superadmin)->post(route('historical-process.import.confirm', $batch), ['expected_updated_at' => $batch->updated_at->toISOString()])->assertRedirect();

        $this->assertSame(1, ConsumerApplication::count());
        $this->assertSame(1, ConsumerStageEvent::count());
        $this->assertSame(0, $batch->fresh()->created_rows);
    }

    public function test_multiple_bank_process_attempts_are_appended_without_overwrite(): void
    {
        [$branch, $superadmin] = $this->context();
        $this->stage($superadmin, $branch->id, "id_kavling\tnama_konsumen\tno_ktp\tid_kons\ttanggal_slik\thasil_slik\tketerangan\nKAV-1\tBudi\t0123456789012345\tK-001\t8/1/2026\tLancar\t");
        $this->stage($superadmin, $branch->id, "id_kavling\tid_kons\tid_psjb\ttanggal_psjb\tnama_koordinator\tnama_sales\tharga_unit\ttanggal_utj\tutj\ttanggal_dp_klt\tdp_all_in\tnominal_cicilan\tjumlah_cicilan\tluas_klt\tharga_klt/m\tharga_klt_total\tcara_pembayaran\tid_promo\tlead_time_hari\tstatus\tketerangan\nKAV-1\tK-001\tPS-1\t2026-08-02\tKoor\tSales\t0\t\t\t\t\t\t\t0\t0\t0\tTunai\t\t\t\t");
        $this->stage($superadmin, $branch->id, "id_kavling\tid_psjb\tid_berkas\ttanggal_terima_bank\tbank\tkc/unit\trequest_plafond\trequest_tenor\ttipe_pemberkasan\tlead_time_hari\tstatus\tketerangan\nKAV-1\tPS-1\tB-1\t2026-08-03\tBCA\tKC A\t200000000\t240\tReguler\t\t\t");
        $this->stage($superadmin, $branch->id, "id_kavling\tid_berkas\tno_sp3k\tjenis_respon\tapproved_plafond\tapproved_tenor\tlead_time_hari\tstatus\tkategori_revisi\tdetail_revisi\tkendala\tketerangan\nKAV-1\tB-1\tSP-1\tRevisi\t\t\t\t\t1\tDokumen\t\t\t");
        $this->stage($superadmin, $branch->id, "id_kavling\tid_berkas\tno_sp3k\tjenis_respon\tapproved_plafond\tapproved_tenor\tlead_time_hari\tstatus\tkategori_revisi\tdetail_revisi\tkendala\tketerangan\nKAV-1\tB-1\tSP-2\tApproved\t200000000\t240\t\t\t\t\t\t");

        $application = ConsumerApplication::sole();
        $this->assertSame(2, ConsumerBankProcess::where('application_id', $application->id)->count());
        $this->assertSame('SP-1', ConsumerBankProcess::where('no_sp3k', 'SP-1')->value('no_sp3k'));
        $this->assertSame('Approved', ConsumerBankProcess::where('no_sp3k', 'SP-2')->value('response_type'));
        $this->assertSame('200000000.00', ConsumerBankProcess::where('no_sp3k', 'SP-2')->value('approved_plafond'));
        $this->assertSame(2, ConsumerStageEvent::where('application_id', $application->id)->where('stage', 'proses_bank')->count());
    }

    public function test_preview_and_confirm_reject_non_superadmin_and_impersonation(): void
    {
        [$branch, $superadmin] = $this->context();
        $admin = $this->user('admin', $branch);
        $bi = "id_kavling\tnama_konsumen\tno_ktp\tid_kons\ttanggal_slik\thasil_slik\tketerangan\nKAV-1\tBudi\t0123456789012345\tK-001\t8/1/2026\tLancar\t";

        $this->actingAs($admin)->get(route('historical-process.import.create'))->assertForbidden();
        $this->actingAs($admin)->post(route('historical-process.import.preview'), ['branch_id' => $branch->id, 'tsv' => $bi])->assertForbidden();

        $session = ['impersonation.original_user_id' => 999, 'impersonation.target_user_id' => $superadmin->id];
        $this->actingAs($superadmin)->withSession($session)->post(route('historical-process.import.preview'), ['branch_id' => $branch->id, 'tsv' => $bi])->assertForbidden();
    }

    public function test_akad_and_bast_imports_are_recognized_as_sold_by_backfill_preview(): void
    {
        [$branch, $superadmin] = $this->context();
        $this->stage($superadmin, $branch->id, "id_kavling\tnama_konsumen\tno_ktp\tid_kons\ttanggal_slik\thasil_slik\tketerangan\nKAV-1\tBudi\t0123456789012345\tK-001\t8/1/2026\tLancar\t");
        $this->stage($superadmin, $branch->id, "id_kavling\tid_kons\tid_psjb\ttanggal_psjb\tnama_koordinator\tnama_sales\tharga_unit\ttanggal_utj\tutj\ttanggal_dp_klt\tdp_all_in\tnominal_cicilan\tjumlah_cicilan\tluas_klt\tharga_klt/m\tharga_klt_total\tcara_pembayaran\tid_promo\tlead_time_hari\tstatus\tketerangan\nKAV-1\tK-001\tPS-1\t2026-08-02\tKoor\tSales\t0\t\t\t\t\t\t\t0\t0\t0\tTunai\t\t\t\t");
        $this->stage($superadmin, $branch->id, "id_kavling\tid_psjb\tid_berkas\ttanggal_terima_bank\tbank\tkc/unit\trequest_plafond\trequest_tenor\ttipe_pemberkasan\tlead_time_hari\tstatus\tketerangan\nKAV-1\tPS-1\tB-1\t2026-08-03\tBCA\tKC A\t0\t240\tReguler\t\t\t");
        $this->stage($superadmin, $branch->id, "id_kavling\tid_berkas\tno_sp3k\tjenis_respon\tapproved_plafond\tapproved_tenor\tlead_time_hari\tstatus\tkategori_revisi\tdetail_revisi\tkendala\tketerangan\nKAV-1\tB-1\tSP-1\tApproved\t0\t240\t\t\t\t\t\t");
        $this->stage($superadmin, $branch->id, "id_kavling\tno_sp3k\tid_ppjb_dev\ttanggal_sp3k\ttanggal_ttd_ppjb\tlead_time_hari\tstatus\tketerangan\nKAV-1\tSP-1\tPP-1\t2026-08-04\t2026-08-05\t\t\t");
        $this->stage($superadmin, $branch->id, "id_kavling\tid_ppjb_dev\tno_ppjb_akad\ttanggal_akad\tkualitas_akad\tlead_time_hari\tstatus\tstatus_bangunan\tstatus_dp_konsumen\tstatus_utilitas\tstatus_konsumen\tketerangan_terlambat\tketerangan\nKAV-1\tPP-1\tAK-1\t2026-09-01\tBaik\t\tAktif\t\t\t\t\t\t");

        $stats = app(ConsumerKavlingBackfillService::class)->preview($branch);
        $this->assertSame(1, $stats['TOTAL_CANDIDATES']);
        $this->assertSame(1, $stats['READY_SOLD']);
        $this->assertSame(0, $stats['READY_RESERVED']);
        $this->assertSame(0, $stats['CONFLICT']);
        $this->assertSame(0, $stats['SKIPPED']);
    }

    public function test_reused_kavling_between_applications_is_reported_as_conflict_not_sold(): void
    {
        [$branch, $superadmin] = $this->context();
        $this->stage($superadmin, $branch->id, "id_kavling\tnama_konsumen\tno_ktp\tid_kons\ttanggal_slik\thasil_slik\tketerangan\nKAV-1\tBudi\t0123456789012345\tK-001\t8/1/2026\tLancar\t");
        $this->stage($superadmin, $branch->id, "id_kavling\tnama_konsumen\tno_ktp\tid_kons\ttanggal_slik\thasil_slik\tketerangan\nKAV-1\tSiti\t1123456789012345\tK-002\t8/1/2026\tLancar\t");
        $this->stage($superadmin, $branch->id, "id_kavling\tid_kons\tid_psjb\ttanggal_psjb\tnama_koordinator\tnama_sales\tharga_unit\ttanggal_utj\tutj\ttanggal_dp_klt\tdp_all_in\tnominal_cicilan\tjumlah_cicilan\tluas_klt\tharga_klt/m\tharga_klt_total\tcara_pembayaran\tid_promo\tlead_time_hari\tstatus\tketerangan\nKAV-1\tK-001\tPS-1\t2026-08-02\tKoor\tSales\t0\t\t\t\t\t\t\t0\t0\t0\tTunai\t\t\t\t");
        $this->stage($superadmin, $branch->id, "id_kavling\tid_kons\tid_psjb\ttanggal_psjb\tnama_koordinator\tnama_sales\tharga_unit\ttanggal_utj\tutj\ttanggal_dp_klt\tdp_all_in\tnominal_cicilan\tjumlah_cicilan\tluas_klt\tharga_klt/m\tharga_klt_total\tcara_pembayaran\tid_promo\tlead_time_hari\tstatus\tketerangan\nKAV-1\tK-002\tPS-2\t2026-08-02\tKoor\tSales\t0\t\t\t\t\t\t\t0\t0\t0\tTunai\t\t\t\t");

        $stats = app(ConsumerKavlingBackfillService::class)->preview($branch);
        $this->assertSame(2, $stats['TOTAL_CANDIDATES']);
        $this->assertSame(0, $stats['READY_SOLD']);
        $this->assertSame(0, $stats['READY_RESERVED']);
        $this->assertSame(2, $stats['CONFLICT']);
    }

    public function test_preview_html_never_exposes_plaintext_nik(): void
    {
        [$branch, $superadmin] = $this->context();
        $bi = "id_kavling\tnama_konsumen\tno_ktp\tid_kons\ttanggal_slik\thasil_slik\tketerangan\nKAV-1\tBudi\t0123456789012345\tK-001\t8/1/2026\tLancar\t";

        $this->actingAs($superadmin)->post(route('historical-process.import.preview'), ['branch_id' => $branch->id, 'tsv' => $bi]);
        $batch = HistoricalProcessImportBatch::sole();

        $html = $this->actingAs($superadmin)->get(route('historical-process.import.show', $batch))->getContent();
        $this->assertStringNotContainsString('0123456789012345', $html);
        $this->assertStringContainsString('••••••••••••2345', $html);
    }

    private function stage(User $superadmin, int $branchId, string $tsv): void
    {
        $this->actingAs($superadmin)->post(route('historical-process.import.preview'), ['branch_id' => $branchId, 'tsv' => $tsv])->assertRedirect();
        $batch = HistoricalProcessImportBatch::latest('id')->firstOrFail();
        $this->actingAs($superadmin)->post(route('historical-process.import.confirm', $batch), ['expected_updated_at' => $batch->updated_at->toISOString()])->assertRedirect();
    }

    private function context(): array
    {
        $branch = Branch::create(['name' => 'Magelang', 'code' => 'MGL', 'is_active' => true, 'sheet_id' => 'sheet-mgl']);

        return [$branch, $this->user('superadmin', $branch)];
    }

    private function user(string $roleSlug, Branch $branch): User
    {
        $role = Role::firstOrCreate(['slug' => $roleSlug], ['name' => $roleSlug, 'is_superadmin' => $roleSlug === 'superadmin']);
        $user = User::factory()->create([
            'role_id' => $role->id,
            'branch_id' => $branch->id,
            'password_changed_at' => now(),
        ]);
        $user->branches()->syncWithoutDetaching([$branch->id => ['can_view' => true, 'can_edit' => true]]);

        return $user;
    }
}
