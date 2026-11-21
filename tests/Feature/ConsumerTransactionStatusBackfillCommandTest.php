<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ConsumerApplication;
use App\Models\ConsumerMigrationReconciliation;
use App\Models\LeadMaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ConsumerTransactionStatusBackfillCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_br36_legacy_transaction_mapping_dry_run_reports_categories_and_review_cases_without_mutation(): void
    {
        Storage::fake('local');
        [$branch, $project] = $this->context();
        $pindah = $this->application($branch, $project, 'Pindah Kavling');
        $rejectSlik = $this->application($branch, $project, 'Reject');
        $rejectBank = $this->application($branch, $project, 'Reject');
        $rejectLegacy = $this->application($branch, $project, 'Reject');
        $replaced = $this->application($branch, $project, 'DIGANTI_KONSUMEN');
        $unknown = $this->application($branch, $project, 'Status Lama Tidak Dikenal');
        $canonical = $this->application($branch, $project, 'LANJUT');
        ConsumerMigrationReconciliation::query()->create([
            'consumer_application_id' => $rejectSlik->id,
            'branch_id' => $branch->id,
            'project_id' => $project->id,
            'source_system' => 'marison_v2',
            'source_transaction_id' => 'REJECT-SLIK',
            'source_stage_status' => 'Reject SLIK',
            'reconciliation_status' => 'PENDING',
            'source_payload_hash' => hash('sha256', 'reject-slik'),
        ]);
        ConsumerMigrationReconciliation::query()->create([
            'consumer_application_id' => $rejectBank->id,
            'branch_id' => $branch->id,
            'project_id' => $project->id,
            'source_system' => 'marison_v2',
            'source_transaction_id' => 'REJECT-BANK',
            'source_bank_status' => 'Reject Bank',
            'reconciliation_status' => 'PENDING',
            'source_payload_hash' => hash('sha256', 'reject-bank'),
        ]);

        $beforeApplications = ConsumerApplication::query()->pluck('transaction_status', 'id')->all();
        $beforeEvents = $this->app->make('db')->table('consumer_stage_events')->count();
        $beforeReconciliations = ConsumerMigrationReconciliation::query()->pluck('reconciliation_status', 'id')->all();

        $this->artisan('consumer:transaction-status-v3-backfill', [
            '--dry-run' => true,
            '--output' => 'reports/transaction-status-map.json',
        ])->assertSuccessful()
            ->expectsOutputToContain('Kasus perlu tinjau: 2')
            ->expectsOutputToContain('Laporan tersimpan: reports/transaction-status-map.json');

        $report = json_decode(Storage::disk('local')->get('reports/transaction-status-map.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($report['meta']['dry_run']);
        $this->assertSame(1, $report['counts']['pindah_kavling_ke_lanjut']);
        $this->assertSame(1, $report['counts']['reject_slik_ke_mundur']);
        $this->assertSame(1, $report['counts']['reject_bank_ke_mundur']);
        $this->assertSame(1, $report['counts']['reject_data_lama_ke_mundur']);
        $this->assertSame(1, $report['counts']['diganti_konsumen_ke_ditutup']);
        $this->assertSame(1, $report['counts']['sudah_kanonis']);
        $this->assertSame(1, $report['counts']['perlu_tinjau']);
        $this->assertSame(2, count($report['review_cases']));
        $this->assertSame('PINDAH_KAVLING', $this->mapping($report, $pindah->id)['event_type']);
        $this->assertSame('LANJUT', $this->mapping($report, $pindah->id)['transaction_status']);
        $this->assertSame('Reject SLIK', $this->mapping($report, $rejectSlik->id)['withdraw_reason']);
        $this->assertSame('Reject Bank', $this->mapping($report, $rejectBank->id)['withdraw_reason']);
        $this->assertSame('Reject (data lama)', $this->mapping($report, $rejectLegacy->id)['withdraw_reason']);
        $this->assertSame('DITUTUP', $this->mapping($report, $replaced->id)['transaction_status']);
        $this->assertSame('GANTI_NAMA', $this->mapping($report, $replaced->id)['closed_by_event']);

        $this->assertSame($beforeApplications, ConsumerApplication::query()->pluck('transaction_status', 'id')->all());
        $this->assertSame($beforeEvents, $this->app->make('db')->table('consumer_stage_events')->count());
        $this->assertSame($beforeReconciliations, ConsumerMigrationReconciliation::query()->pluck('reconciliation_status', 'id')->all());
    }

    public function test_command_requires_dry_run_and_writes_no_report_without_it(): void
    {
        Storage::fake('local');

        $this->artisan('consumer:transaction-status-v3-backfill')
            ->assertFailed()
            ->expectsOutputToContain('Gunakan --dry-run');

        Storage::disk('local')->assertMissing('temp/consumer-transaction-status-v3.json');
    }

    public function test_output_path_must_stay_on_local_disk(): void
    {
        Storage::fake('local');

        $this->artisan('consumer:transaction-status-v3-backfill', [
            '--dry-run' => true,
            '--output' => '../outside.json',
        ])->assertFailed()
            ->expectsOutputToContain('path relatif pada disk local');
    }

    private function context(): array
    {
        $branch = Branch::query()->create(['name' => 'Mapping Branch', 'code' => 'MAP', 'is_active' => true]);
        $project = LeadMaster::query()->create(['branch_id' => $branch->id, 'project_name' => 'Mapping Project', 'is_active' => true]);

        return [$branch, $project];
    }

    private function application(Branch $branch, LeadMaster $project, string $status): ConsumerApplication
    {
        return ConsumerApplication::query()->create([
            'branch_id' => $branch->id,
            'project_id' => $project->id,
            'application_status' => 'active',
            'transaction_status' => $status,
        ]);
    }

    private function mapping(array $report, int $applicationId): array
    {
        return collect($report['mappings'])->firstWhere('application_id', $applicationId);
    }
}
