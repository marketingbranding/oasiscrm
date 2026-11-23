<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ConsumerApplication;
use App\Models\ConsumerLegacyIdentity;
use App\Models\Customer;
use App\Models\Kavling;
use App\Models\KonsumenProgressSheetRow;
use App\Models\KonsumenProgressSyncStatus;
use App\Models\LeadMaster;
use App\Services\BranchParityReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        KonsumenProgressSheetRow::create(['branch_id' => $branch->id, 'sheet_id' => $branch->sheet_id, 'sheet_name' => 'data_konsumen', 'row_hash' => 'matching-row', 'row_data' => ['id_kavling' => 'K-01', 'nama_konsumen' => 'Rahasia', 'no_hp' => '0812345678', 'project_name' => $project->project_name, 'external_id' => 'PARITY-1', 'tanggal_akad' => '2026-11-01']]);
        KonsumenProgressSheetRow::create(['branch_id' => $branch->id, 'sheet_id' => $branch->sheet_id, 'sheet_name' => 'akad', 'row_hash' => 'matching-stage', 'row_data' => ['id_kavling' => 'K-01', 'tanggal_akad' => '2026-11-01']]);
        KonsumenProgressSyncStatus::create(['branch_id' => $branch->id, 'status' => 'success', 'finished_at' => now()]);

        $report = app(BranchParityReportService::class)->report($branch);

        $this->assertSame('GAGAL', $report->status, json_encode([$report->summary, $report->differences], JSON_THROW_ON_ERROR));
        $this->assertSame(1, $report->metrics['transactions_by_status']['MUNDUR']);
        $this->assertSame(0, $report->metrics['occupied_kavling']);
        $this->assertArrayNotHasKey('customer_name', $report->toArray());
        $this->assertStringNotContainsString('Rahasia', json_encode($report->toArray(), JSON_THROW_ON_ERROR));
        $this->assertSame($application->id, ConsumerApplication::query()->find($application->id)->id);
        $this->assertDatabaseHas('consumer_parity_snapshots', ['branch_id' => $branch->id, 'status' => 'GAGAL']);
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
