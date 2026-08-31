<?php

namespace Tests\Feature;

use App\Enums\SalesLeadStatus;
use App\Models\Branch;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\SalesLead;
use App\Models\SalesLeadBridgeSetting;
use App\Models\SalesLeadLifecycleReconciliationItem;
use App\Models\User;
use App\Services\GoogleSheetsApiService;
use App\Services\PhoneNormalizationService;
use App\Services\SalesLeadBridgeModeService;
use App\Services\SalesLeadBridgeService;
use App\Services\SalesLeadLifecycleService;
use App\Services\SalesLeadService;
use App\Services\SalesLeadSpreadsheetContract;
use App\Services\SalesLeadSpreadsheetWriter;
use App\Services\SalesSheetIdentityService;
use App\Services\SyncLockService;
use App\ValueObjects\ResolvedSalesLeadSpreadsheetContract;
use App\ValueObjects\SalesLeadSheetDefinition;
use App\ValueObjects\SalesLeadSpreadsheetWriteResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class SalesLeadBridgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.google_sheets.sales_lead_sync_enabled', true);
    }

    public function test_setting_defaults_off_and_global_flag_blocks_modes(): void
    {
        [$branch] = $this->context('Mode');
        $modes = app(SalesLeadBridgeModeService::class);

        $this->assertFalse($modes->isPushEnabled($branch));
        $this->assertFalse($modes->isPullEnabled($branch));
        $this->enable($branch, 'push_only');
        $this->assertTrue($modes->isPushEnabled($branch->fresh()));
        $this->assertFalse($modes->isPullEnabled($branch->fresh()));
        config()->set('services.google_sheets.sales_lead_sync_enabled', false);
        $this->assertFalse($modes->isPushEnabled($branch->fresh()));
    }

    public function test_push_appends_updates_is_idempotent_and_binds_target_on_failure(): void
    {
        [$branch, $project, $sales, $lead] = $this->context('Push');
        $this->enable($branch, 'push_only');
        $contract = $this->contract($branch, $project, $sales);
        $google = Mockery::mock(GoogleSheetsApiService::class);
        $google->shouldReceive('readLeadRows')->times(3)->andReturn(
            ['headers' => $contract->headers, 'rows' => []],
            ['headers' => $contract->headers, 'rows' => [$this->row($lead, 'REMOTE-1')]],
            ['headers' => $contract->headers, 'rows' => [$this->row($lead, 'REMOTE-1')]],
        );
        $writer = Mockery::mock(SalesLeadSpreadsheetWriter::class);
        $writer->shouldReceive('append')->once()->andReturn(new SalesLeadSpreadsheetWriteResult($branch->sheet_id, 'lead', 2, $lead->external_sync_id, $this->row($lead, 'REMOTE-1')));
        $writer->shouldReceive('updateBySyncId')->twice()->andReturn(new SalesLeadSpreadsheetWriteResult($branch->sheet_id, 'lead', 2, $lead->external_sync_id, $this->row($lead, 'REMOTE-1')));
        $bridge = $this->bridge($google, $writer, $contract);

        $this->assertSame('synced', $bridge->push($lead)['status']);
        $this->assertSame($branch->id, $lead->fresh()->remote_target_branch_id);
        $lead->update(['customer_name' => 'Updated', 'sync_status' => 'pending_update']);
        $this->assertSame('synced', $bridge->push($lead->fresh())['status']);
        $this->assertSame('synced', $bridge->push($lead->fresh())['status']);
    }

    public function test_first_push_failure_keeps_target_binding_and_blocks_branch_move(): void
    {
        [$branch, $project, $sales, $lead] = $this->context('Bind');
        [$otherBranch, $otherProject, $otherSales] = $this->context('BindOther', false);
        $this->enable($branch, 'push_only');
        $contract = $this->contract($branch, $project, $sales);
        $google = Mockery::mock(GoogleSheetsApiService::class);
        $google->shouldReceive('readLeadRows')->once()->andReturn(['headers' => $contract->headers, 'rows' => []]);
        $writer = Mockery::mock(SalesLeadSpreadsheetWriter::class);
        $writer->shouldReceive('append')->once()->andThrow(new \RuntimeException('down'));
        try {
            $this->bridge($google, $writer, $contract)->push($lead);
        } catch (\RuntimeException) {
        }
        $bound = $lead->fresh();
        $this->assertSame($branch->id, $bound->remote_target_branch_id);
        $this->assertNotNull($bound->delivery_attempted_at);
        $this->expectException(\DomainException::class);
        app(SalesLeadService::class)->update($bound, [
            'branch_id' => $otherBranch->id,
            'project_id' => $otherProject->id,
            'sales_user_id' => $otherSales->id,
            'lead_date' => '2026-08-31',
            'customer_name' => 'Moved',
            'phone' => '0812',
            'source' => 'Referral',
            'platform' => 'WhatsApp',
            'campaign_name' => 'Follow Up',
            'current_status' => 'no_response',
        ], $sales);
    }

    public function test_push_conflict_blocks_write_and_branch_isolation_holds(): void
    {
        [$branch, $project, $sales, $lead] = $this->context('Conflict');
        [$otherBranch] = $this->context('Other');
        $this->enable($branch, 'push_only');
        $contract = $this->contract($branch, $project, $sales);
        $baseline = $this->row($lead, 'REMOTE-1');
        $lead->update(['last_remote_payload_hash' => hash('sha256', json_encode($baseline, JSON_THROW_ON_ERROR)), 'last_synced_payload_hash' => str_repeat('a', 64)]);
        $changed = $baseline;
        $changed['nama_konsumen'] = 'Remote Changed';
        $google = Mockery::mock(GoogleSheetsApiService::class);
        $google->shouldReceive('readLeadRows')->once()->with($branch->sheet_id)->andReturn(['headers' => $contract->headers, 'rows' => [$changed]]);
        $writer = Mockery::mock(SalesLeadSpreadsheetWriter::class);
        $writer->shouldNotReceive('append');
        $writer->shouldNotReceive('updateBySyncId');

        $result = $this->bridge($google, $writer, $contract)->push($lead);

        $this->assertSame('conflict', $result['status']);
        $this->assertSame('conflict', $lead->fresh()->sync_status);
        $this->assertDatabaseHas('sales_lead_lifecycle_reconciliation_items', ['branch_id' => $branch->id, 'issue_code' => 'lead_remote_conflict']);
        $this->assertDatabaseMissing('sales_lead_lifecycle_reconciliation_items', ['branch_id' => $otherBranch->id]);
    }

    public function test_pull_missing_baseline_rejects_remote_divergence_without_mutation(): void
    {
        [$branch, $project, $sales, $lead] = $this->context('Pull');
        $this->enable($branch, 'bidirectional');
        $lead->update(['current_status' => SalesLeadStatus::Discussion, 'sync_status' => 'synced']);
        $row = $this->row($lead, 'REMOTE-2');
        $row['nama_konsumen'] = 'Remote Name';
        $row['keterangan'] = 'Remote Notes';
        $row['status_lead'] = 'Diskusi';
        $bridge = $this->pullBridge($branch, $project, $sales, [$row]);

        $result = $bridge->pull($branch);

        $fresh = $lead->fresh();
        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['summary']['unresolved']);
        $this->assertSame('Local Lead', $fresh->customer_name);
        $this->assertSame('baseline_missing', SalesLeadLifecycleReconciliationItem::where('branch_id', $branch->id)->value('issue_code'));
    }

    public function test_pull_dirty_divergence_conflicts_without_mutation(): void
    {
        [$branch, $project, $sales, $lead] = $this->context('Dirty');
        $this->enable($branch, 'bidirectional');
        $baseline = $this->row($lead, 'REMOTE-D');
        $bridge = $this->pullBridge($branch, $project, $sales, [$baseline]);
        $lead->update([
            'sync_status' => 'pending_update',
            'last_remote_payload_hash' => $bridge->payloadHash($baseline),
            'last_synced_payload_hash' => $bridge->payloadHash($baseline),
            'last_synced_field_hashes' => ['nama_konsumen' => hash('sha256', 'Old')],
            'customer_name' => 'Local Dirty',
        ]);
        $remote = $baseline;
        $remote['nama_konsumen'] = 'Remote Dirty';
        $bridge = $this->pullBridge($branch, $project, $sales, [$remote]);

        $result = $bridge->pull($branch);

        $this->assertSame(1, $result['summary']['unresolved']);
        $this->assertSame('Local Dirty', $lead->fresh()->customer_name);
    }

    public function test_pull_reconciles_duplicate_invalid_uuid_and_duplicate_id(): void
    {
        [$branch, $project, $sales, $lead] = $this->context('Invalid');
        $this->enable($branch, 'bidirectional');
        $one = $this->row($lead, 'DUP');
        $one['oasis_sync_id'] = 'invalid';
        $two = $one;
        $two['_row_number'] = 3;

        $result = $this->pullBridge($branch, $project, $sales, [$one, $two])->pull($branch);

        $this->assertSame(2, $result['summary']['unresolved']);
        $this->assertTrue(SalesLeadLifecycleReconciliationItem::where('branch_id', $branch->id)->where('issue_code', 'invalid_uuid')->exists());

        $first = $this->newRow($project, $sales, 'DUPLICATE-ID');
        $first['oasis_sync_id'] = (string) Str::uuid();
        $second = $first;
        $second['_row_number'] = 3;
        $second['oasis_sync_id'] = (string) Str::uuid();
        $result = $this->pullBridge($branch, $project, $sales, [$first, $second])->pull($branch);
        $this->assertSame(2, $result['summary']['unresolved']);
        $this->assertTrue(SalesLeadLifecycleReconciliationItem::where('branch_id', $branch->id)->where('issue_code', 'duplicate_id_lead')->exists());
    }

    public function test_remote_claim_writes_uuid_without_nested_lock_and_retry_does_not_duplicate(): void
    {
        [$branch, $project, $sales] = $this->context('Claim', false);
        $this->enable($branch, 'bidirectional');
        $row = $this->newRow($project, $sales, 'REMOTE-CLAIM');
        $contract = $this->contract($branch, $project, $sales);
        $google = Mockery::mock(GoogleSheetsApiService::class);
        $google->shouldReceive('readLeadRows')->twice()->andReturnUsing(function () use (&$row, $contract): array {
            return ['headers' => $contract->headers, 'rows' => [$row]];
        });
        $writer = Mockery::mock(SalesLeadSpreadsheetWriter::class);
        $writer->shouldReceive('setSyncIdByRow')->once()->withArgs(function (SalesLead $lead, int $number, string $uuid, bool $manageLock) use (&$row): bool {
            $row['oasis_sync_id'] = $uuid;

            return $number === 2 && $manageLock === false;
        });
        $bridge = $this->bridge($google, $writer, $contract, $project, $sales);

        $result = $bridge->pull($branch);

        $this->assertSame(1, $result['summary']['claimed']);
        $this->assertSame(1, SalesLead::where('branch_id', $branch->id)->where('external_lead_id', 'REMOTE-CLAIM')->count());
    }

    public function test_remote_claim_unverified_rolls_back_local_and_reconciles(): void
    {
        [$branch, $project, $sales] = $this->context('ClaimUnknown', false);
        $this->enable($branch, 'bidirectional');
        $row = $this->newRow($project, $sales, 'REMOTE-UNKNOWN');
        $contract = $this->contract($branch, $project, $sales);
        $google = Mockery::mock(GoogleSheetsApiService::class);
        $google->shouldReceive('readLeadRows')->twice()->andReturn(['headers' => $contract->headers, 'rows' => [$row]]);
        $writer = Mockery::mock(SalesLeadSpreadsheetWriter::class);
        $writer->shouldReceive('setSyncIdByRow')->once();

        $result = $this->bridge($google, $writer, $contract, $project, $sales)->pull($branch);

        $this->assertSame(0, SalesLead::where('branch_id', $branch->id)->where('external_lead_id', 'REMOTE-UNKNOWN')->count());
        $this->assertSame(1, $result['summary']['unresolved']);
        $this->assertDatabaseHas('sales_lead_lifecycle_reconciliation_items', ['branch_id' => $branch->id, 'issue_code' => 'claim_unverified']);
    }

    public function test_remote_claim_definite_write_failure_rolls_back_local_and_reconciles(): void
    {
        [$branch, $project, $sales] = $this->context('ClaimFail', false);
        $this->enable($branch, 'bidirectional');
        $row = $this->newRow($project, $sales, 'REMOTE-FAIL');
        $contract = $this->contract($branch, $project, $sales);
        $google = Mockery::mock(GoogleSheetsApiService::class);
        $google->shouldReceive('readLeadRows')->once()->andReturn(['headers' => $contract->headers, 'rows' => [$row]]);
        $writer = Mockery::mock(SalesLeadSpreadsheetWriter::class);
        $writer->shouldReceive('setSyncIdByRow')->once()->andThrow(new \RuntimeException('write failed'));

        $result = $this->bridge($google, $writer, $contract, $project, $sales)->pull($branch);

        $this->assertSame(0, SalesLead::where('branch_id', $branch->id)->where('external_lead_id', 'REMOTE-FAIL')->count());
        $this->assertSame(1, $result['summary']['unresolved']);
        $this->assertDatabaseHas('sales_lead_lifecycle_reconciliation_items', ['branch_id' => $branch->id, 'issue_code' => 'claim_failed']);
    }

    public function test_dry_run_and_tombstone_rows_do_not_mutate(): void
    {
        [$branch, $project, $sales, $lead] = $this->context('Dry');
        $this->enable($branch, 'bidirectional');
        $row = $this->row($lead, 'REMOTE-DRY');
        $row['nama_konsumen'] = 'Would Change';
        $result = $this->pullBridge($branch, $project, $sales, [$row])->pull($branch, null, true);
        $this->assertSame('Local Lead', $lead->fresh()->customer_name);
        $this->assertDatabaseCount('sales_lead_lifecycle_reconciliation_items', 0);
        $row['oasis_deleted_at'] = now()->toIso8601String();
        $result = $this->pullBridge($branch, $project, $sales, [$row])->pull($branch);
        $this->assertSame(1, $result['summary']['ignored_deleted']);
        $this->assertNotNull($lead->fresh());
    }

    public function test_delete_tombstones_before_soft_delete_and_failure_keeps_pending(): void
    {
        [$branch, $project, $sales, $lead] = $this->context('Delete');
        $this->enable($branch, 'push_only');
        $lead->update(['last_synced_at' => now(), 'remote_target_branch_id' => $branch->id, 'sync_status' => 'synced']);
        $bridge = Mockery::mock(SalesLeadBridgeService::class);
        $bridge->shouldReceive('tombstone')->once()->andReturn(new SalesLeadSpreadsheetWriteResult($branch->sheet_id, 'lead', 2, $lead->external_sync_id));
        $service = new SalesLeadService(app(PhoneNormalizationService::class), app(SalesLeadLifecycleService::class), app(SalesLeadBridgeModeService::class), $bridge);
        $service->delete($lead, $sales);
        $this->assertSoftDeleted('sales_leads', ['id' => $lead->id]);

        [, , $salesTwo, $failed] = $this->context('DeleteFail');
        $this->enable($failed->branch, 'push_only');
        $failed->unsetRelation('branch');
        $failed->update(['last_synced_at' => now(), 'remote_target_branch_id' => $failed->branch_id, 'sync_status' => 'synced']);
        $this->assertTrue(app(SalesLeadBridgeModeService::class)->isPushEnabled($failed->branch));
        $bridge = Mockery::mock(SalesLeadBridgeService::class);
        $bridge->shouldReceive('tombstone')->once()->andThrow(new \RuntimeException('down'));
        $service = new SalesLeadService(app(PhoneNormalizationService::class), app(SalesLeadLifecycleService::class), app(SalesLeadBridgeModeService::class), $bridge);
        try {
            $service->delete($failed, $salesTwo);
            $this->fail('Delete should fail.');
        } catch (\DomainException) {
        }
        $this->assertDatabaseHas('sales_leads', ['id' => $failed->id, 'deleted_at' => null, 'sync_status' => 'pending_delete']);

        [, , $salesThree, $changed] = $this->context('DeleteChanged');
        $this->enable($changed->branch, 'push_only');
        $changed->unsetRelation('branch');
        $changed->update(['last_synced_at' => now(), 'remote_target_branch_id' => $changed->branch_id, 'sync_status' => 'synced']);
        $bridge = Mockery::mock(SalesLeadBridgeService::class);
        $bridge->shouldReceive('tombstone')->once()->andReturnUsing(function () use ($changed): SalesLeadSpreadsheetWriteResult {
            SalesLead::whereKey($changed->id)->update(['customer_name' => 'Concurrent Change']);

            return new SalesLeadSpreadsheetWriteResult($changed->branch->sheet_id, 'lead', 2, $changed->external_sync_id);
        });
        $service = new SalesLeadService(app(PhoneNormalizationService::class), app(SalesLeadLifecycleService::class), app(SalesLeadBridgeModeService::class), $bridge);
        try {
            $service->delete($changed, $salesThree);
            $this->fail('Concurrent change should block delete.');
        } catch (\DomainException) {
        }
        $this->assertDatabaseHas('sales_leads', ['id' => $changed->id, 'deleted_at' => null, 'sync_status' => 'pending_delete']);
    }

    public function test_formula_guard_escapes_text_only(): void
    {
        [$branch, $project, $sales] = $this->context('Formula', false);
        $contract = $this->contract($branch, $project, $sales);
        $service = new SalesLeadSpreadsheetContract(Mockery::mock(GoogleSheetsApiService::class));
        foreach (['=', '+', '-', '@'] as $prefix) {
            $this->assertSame("'{$prefix}payload", $service->valueForWrite($contract, 'nama_konsumen', $prefix.'payload'));
        }
        $this->assertSame('2026-08-31', $service->valueForWrite($contract, 'tanggal_lead', '2026-08-31'));
        $this->assertSame('No Respon', $service->valueForWrite($contract, 'status_lead', 'No Respon'));
    }

    private function context(string $suffix, bool $withLead = true): array
    {
        $branch = Branch::create(['name' => 'Branch '.$suffix, 'code' => strtoupper(substr($suffix, 0, 6)).Str::random(3), 'sheet_id' => 'sheet-'.$suffix, 'is_active' => true]);
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Project '.$suffix, 'sheet_project_name' => 'Project '.$suffix, 'is_active' => true]);
        $sales = User::factory()->create(['name' => 'Sales '.$suffix, 'branch_id' => $branch->id, 'role_id' => Role::where('slug', 'sales')->value('id'), 'password_changed_at' => now()]);
        $sales->assignedProjects()->attach($project->id, ['is_active' => true, 'is_primary' => true]);
        $lead = $withLead ? SalesLead::create([
            'branch_id' => $branch->id,
            'project_id' => $project->id,
            'sales_user_id' => $sales->id,
            'lead_date' => '2026-08-31',
            'customer_name' => 'Local Lead',
            'phone' => '08123456789',
            'source' => 'Referral',
            'source_name_snapshot' => 'Referral',
            'platform' => 'WhatsApp',
            'campaign_name' => 'Follow Up',
            'current_status' => SalesLeadStatus::NoResponse,
            'external_sync_id' => (string) Str::uuid(),
            'sync_status' => 'pending_create',
            'created_by' => $sales->id,
            'updated_by' => $sales->id,
        ]) : null;

        return [$branch, $project, $sales, $lead];
    }

    private function enable(Branch $branch, string $mode): void
    {
        SalesLeadBridgeSetting::create(['branch_id' => $branch->id, 'mode' => $mode, 'status' => 'active', 'last_preflight_at' => now(), 'last_preflight_hash' => str_repeat('a', 64), 'enabled_at' => now()]);
        $branch->unsetRelation('bridgeSetting');
    }

    private function contract(Branch $branch, LeadMaster $project, User $sales): ResolvedSalesLeadSpreadsheetContract
    {
        $headers = ['id_lead', 'nama_promo', 'tanggal_lead', 'sumber_lead', 'kanal_masuk', 'aktivitas_lead', 'nama_konsumen', 'no_hp', 'proyek', 'sales_pic', 'status_lead', 'keterangan', 'oasis_sync_id', 'oasis_deleted_at', 'oasis_deleted_by'];

        return new ResolvedSalesLeadSpreadsheetContract(
            $branch->sheet_id,
            new SalesLeadSheetDefinition('lead', array_slice($headers, 0, 12)),
            1,
            $headers,
            array_flip($headers),
            [],
            2,
            ['proyek' => [$project->sheet_project_name], 'sales_pic' => [$sales->name], 'status_lead' => ['No Respon', 'Diskusi']],
        );
    }

    private function bridge($google, $writer, $contract, ?LeadMaster $project = null, ?User $sales = null): SalesLeadBridgeService
    {
        $contracts = Mockery::mock(SalesLeadSpreadsheetContract::class);
        $contracts->shouldReceive('resolve')->andReturn($contract);
        $contracts->shouldReceive('resolveForBranch')->andReturn($contract);
        $identities = Mockery::mock(SalesSheetIdentityService::class);
        if ($project && $sales) {
            $identities->shouldReceive('reverseSales')->andReturn([$sales, null]);
        }

        return new SalesLeadBridgeService(app(SalesLeadBridgeModeService::class), $google, $contracts, $writer, $identities, app(SyncLockService::class), new PhoneNormalizationService);
    }

    private function pullBridge(Branch $branch, LeadMaster $project, User $sales, array $rows): SalesLeadBridgeService
    {
        $contract = $this->contract($branch, $project, $sales);
        $google = Mockery::mock(GoogleSheetsApiService::class);
        $google->shouldReceive('readLeadRows')->andReturn(['headers' => $contract->headers, 'rows' => $rows]);
        $writer = Mockery::mock(SalesLeadSpreadsheetWriter::class);

        return $this->bridge($google, $writer, $contract, $project, $sales);
    }

    private function row(SalesLead $lead, string $externalId): array
    {
        $lead->loadMissing(['project', 'sales']);

        return [
            '_row_number' => 2,
            'id_lead' => $externalId,
            'nama_promo' => '',
            'tanggal_lead' => $lead->lead_date->format('Y-m-d'),
            'sumber_lead' => $lead->source,
            'kanal_masuk' => $lead->platform,
            'aktivitas_lead' => $lead->campaign_name,
            'nama_konsumen' => $lead->customer_name,
            'no_hp' => $lead->phone,
            'proyek' => $lead->project->sheet_project_name,
            'sales_pic' => $lead->sales->name,
            'status_lead' => $lead->current_status->spreadsheetValue(),
            'keterangan' => $lead->notes ?? '',
            'oasis_sync_id' => $lead->external_sync_id,
            'oasis_deleted_at' => '',
            'oasis_deleted_by' => '',
        ];
    }

    private function newRow(LeadMaster $project, User $sales, string $externalId): array
    {
        return [
            '_row_number' => 2,
            'id_lead' => $externalId,
            'nama_promo' => '',
            'tanggal_lead' => '2026-08-31',
            'sumber_lead' => 'Referral',
            'kanal_masuk' => 'WhatsApp',
            'aktivitas_lead' => 'Follow Up',
            'nama_konsumen' => 'Remote Claim',
            'no_hp' => '08123456789',
            'proyek' => $project->sheet_project_name,
            'sales_pic' => $sales->name,
            'status_lead' => 'No Respon',
            'keterangan' => '',
            'oasis_sync_id' => '',
            'oasis_deleted_at' => '',
            'oasis_deleted_by' => '',
        ];
    }
}
