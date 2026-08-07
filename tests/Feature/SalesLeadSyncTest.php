<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\LeadMaster;
use App\Models\SalesLead;
use App\Models\SalesLeadLifecycleSyncStatus;
use App\Models\User;
use App\Services\GoogleSheetsApiService;
use App\Services\SalesLeadSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class SalesLeadSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_lead_sync_reads_only_lead_and_ignores_downstream_sheet_health(): void
    {
        [$branch, $project, $sales] = $this->context('sheet-lead-only', 'Lead Only Project', 'Lead Only Sales');
        $google = Mockery::mock(GoogleSheetsApiService::class);
        $this->expectSheets($google, 'sheet-lead-only', ['lead' => $this->leadSheet('LEAD-ONLY-1', $project->project_name, $sales->name, 'No Respon', 'SYNC-LEAD-ONLY')]);
        $this->app->instance(GoogleSheetsApiService::class, $google);

        $result = app(SalesLeadSyncService::class)->sync($branch);

        $this->assertTrue($result['ok']);
        $this->assertSame('success', $result['status']);
        $this->assertDatabaseHas('sales_leads', ['branch_id' => $branch->id, 'external_lead_id' => 'LEAD-ONLY-1']);
        $this->assertSame(1, $result['summary']['imported']);
        $this->assertSame(0, $result['summary']['unresolved']);
    }

    public function test_lead_sync_status_is_scoped_to_lead_separately_from_lifecycle(): void
    {
        [$branch, $project, $sales] = $this->context('sheet-scoped', 'Scoped Project', 'Scoped Sales');
        $google = Mockery::mock(GoogleSheetsApiService::class);
        $this->expectSheets($google, 'sheet-scoped', ['lead' => $this->leadSheet('SCOPED-1', $project->project_name, $sales->name, 'Akad', 'SYNC-SCOPED')]);
        $this->app->instance(GoogleSheetsApiService::class, $google);

        SalesLeadLifecycleSyncStatus::query()->create(['branch_id' => $branch->id, 'scope' => 'lifecycle', 'status' => 'failed']);

        $result = app(SalesLeadSyncService::class)->sync($branch);

        $this->assertTrue($result['ok']);
        $this->assertSame('success', $result['status']);
        $this->assertDatabaseHas('sales_lead_lifecycle_sync_statuses', ['branch_id' => $branch->id, 'scope' => 'lead', 'status' => 'success']);
        $this->assertDatabaseHas('sales_lead_lifecycle_sync_statuses', ['branch_id' => $branch->id, 'scope' => 'lifecycle', 'status' => 'failed']);
        $this->assertSame(2, SalesLeadLifecycleSyncStatus::query()->where('branch_id', $branch->id)->count());
    }

    public function test_lead_status_without_downstream_evidence_is_still_applied_from_shared_lead_tab(): void
    {
        [$branch, $project, $sales] = $this->context('sheet-lead-status', 'Lead Status Project', 'Lead Status Sales');
        $google = Mockery::mock(GoogleSheetsApiService::class);
        $this->expectSheets($google, 'sheet-lead-status', ['lead' => $this->leadSheet('LEAD-ST-1', $project->project_name, $sales->name, 'Utj', 'SYNC-LEAD-ST')]);
        $this->app->instance(GoogleSheetsApiService::class, $google);

        $result = app(SalesLeadSyncService::class)->sync($branch);

        $this->assertTrue($result['ok']);
        $lead = SalesLead::query()->where('external_sync_id', 'SYNC-LEAD-ST')->firstOrFail();
        $this->assertSame('utj', $lead->current_status->value);
        $this->assertSame(0, $result['summary']['unresolved']);
    }

    public function test_historical_unmapped_sales_never_assigned_and_produces_lead_reconciliation(): void
    {
        [$branch, $project, $sales] = $this->context('sheet-historical', 'Historical Project', 'Assigned New Sales');
        $google = Mockery::mock(GoogleSheetsApiService::class);
        $this->expectSheets($google, 'sheet-historical', ['lead' => $this->leadSheet('HIST-1', $project->project_name, 'Donni Bramasta', 'No Respon', 'SYNC-HIST')]);
        $this->app->instance(GoogleSheetsApiService::class, $google);

        $result = app(SalesLeadSyncService::class)->sync($branch);

        $this->assertTrue($result['ok']);
        $this->assertSame('partial_success', $result['status']);
        $this->assertSame(1, $result['summary']['unresolved']);
        $this->assertDatabaseHas('sales_lead_lifecycle_reconciliation_items', ['branch_id' => $branch->id, 'entity_type' => 'lead', 'issue_code' => 'sales_not_found', 'status' => 'open']);
        $this->assertSame(0, SalesLead::query()->where('branch_id', $branch->id)->where('sales_user_id', $sales->id)->count());
    }

    public function test_project_identity_uses_sheet_project_name_when_present(): void
    {
        [$branch, $project, $sales] = $this->context('sheet-sheetname', 'Jonggrangan', 'Sheet Name Sales');
        $project->update(['sheet_project_name' => 'Marison Kalinegoro']);
        $google = Mockery::mock(GoogleSheetsApiService::class);
        $this->expectSheets($google, 'sheet-sheetname', ['lead' => $this->leadSheet('SHEETNAME-1', 'Marison Kalinegoro', $sales->name, 'No Respon', 'SYNC-SHEETNAME')]);
        $this->app->instance(GoogleSheetsApiService::class, $google);

        $result = app(SalesLeadSyncService::class)->sync($branch);

        $this->assertTrue($result['ok']);
        $lead = SalesLead::query()->where('external_sync_id', 'SYNC-SHEETNAME')->firstOrFail();
        $this->assertSame($project->id, $lead->project_id);
    }

    public function test_command_sales_lead_sync_fails_on_missing_tab_and_succeeds_otherwise(): void
    {
        [$bad, $badProject, $badSales] = $this->context('sheet-lead-command-bad', 'Command Bad Project', 'Command Bad Sales');
        [$good, $goodProject, $goodSales] = $this->context('sheet-lead-command-good', 'Command Good Project', 'Command Good Sales');
        $google = Mockery::mock(GoogleSheetsApiService::class);
        $google->shouldReceive('sheetTitles')->once()->with('sheet-lead-command-bad')->andReturn([]);
        $this->expectSheets($google, 'sheet-lead-command-good', ['lead' => $this->leadSheet('CLC-1', $goodProject->project_name, $goodSales->name, 'No Respon', 'SYNC-CLC')]);
        $this->app->instance(GoogleSheetsApiService::class, $google);

        $this->artisan('sales-lead:sync')->assertFailed();
        $this->assertDatabaseHas('sales_lead_lifecycle_sync_statuses', ['branch_id' => $bad->id, 'scope' => 'lead', 'status' => 'failed']);
        $this->assertDatabaseHas('sales_leads', ['branch_id' => $good->id, 'external_lead_id' => 'CLC-1']);
        $this->assertDatabaseHas('sales_lead_lifecycle_sync_statuses', ['branch_id' => $good->id, 'scope' => 'lead', 'status' => 'success']);
    }

    private function context(string $sheetId, string $projectName, string $salesName): array
    {
        $branch = Branch::query()->create(['name' => 'Branch '.Str::random(6), 'code' => strtoupper(Str::random(6)), 'sheet_id' => $sheetId, 'is_active' => true]);
        $project = LeadMaster::query()->create(['branch_id' => $branch->id, 'project_name' => $projectName, 'is_active' => true]);
        $sales = User::factory()->create(['name' => $salesName, 'branch_id' => $branch->id]);
        $sales->assignedProjects()->attach($project->id, ['is_active' => true, 'is_primary' => true]);

        return [$branch, $project, $sales];
    }

    private function leadSheet(string $id, string $project, string $sales, string $status, string $syncId = ''): array
    {
        return [
            ['id_lead', 'tanggal_lead', 'nama_konsumen', 'proyek', 'sales_pic', 'status_lead', 'sumber_lead', 'kanal_masuk', 'aktivitas_lead', 'oasis_sync_id'],
            [$id, '2026-08-03', 'Consumer '.$id, $project, $sales, $status, 'Online', 'WhatsApp', 'Follow Up', $syncId],
        ];
    }

    private function expectSheets($google, string $spreadsheetId, array $sheets, int $times = 1): void
    {
        $titles = array_keys($sheets);
        $google->shouldReceive('sheetTitles')->times($times)->with($spreadsheetId)->andReturn($titles);
        foreach ($titles as $title) {
            $google->shouldReceive('quoteSheetName')->times($times)->with($title)->andReturn("'{$title}'");
        }
        $google->shouldReceive('batchGetRaw')->times($times)->withArgs(
            fn (string $id, array $ranges, string $render) => $id === $spreadsheetId && count($ranges) === count($titles) && $render === 'FORMATTED_VALUE',
        )->andReturn($sheets);
    }
}
