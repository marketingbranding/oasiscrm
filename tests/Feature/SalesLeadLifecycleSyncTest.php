<?php

namespace Tests\Feature;

use App\Enums\SalesLeadStatus;
use App\Models\Branch;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\SalesLead;
use App\Models\SalesLeadAkadLink;
use App\Models\SalesLeadConsumerLink;
use App\Models\SalesLeadLifecycleSyncStatus;
use App\Models\SalesSheetIdentity;
use App\Models\User;
use App\Services\GoogleSheetsApiService;
use App\Services\SalesLeadLifecycleSyncService;
use App\Services\SalesLeadSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class SalesLeadLifecycleSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_lead_id_is_isolated_per_branch_and_statuses_are_independent(): void
    {
        [$firstBranch, $firstProject, $firstSales] = $this->context('sheet-one', 'Project One', 'Sales One');
        [$secondBranch, $secondProject, $secondSales] = $this->context('sheet-two', 'Project Two', 'Sales Two');
        $google = Mockery::mock(GoogleSheetsApiService::class);
        $this->expectSheets($google, 'sheet-one', ['lead' => $this->leadSheet('ID-1', $firstProject->project_name, $firstSales->name, 'Diskusi', 'SYNC-ONE')]);
        $this->expectSheets($google, 'sheet-two', ['lead' => $this->leadSheet('ID-1', $secondProject->project_name, $secondSales->name, 'No Respon', 'SYNC-TWO')]);
        $this->app->instance(GoogleSheetsApiService::class, $google);

        $service = app(SalesLeadLifecycleSyncService::class);
        $this->assertTrue($service->sync($firstBranch)['ok']);
        $this->assertTrue($service->sync($secondBranch)['ok']);

        $this->assertDatabaseHas('sales_leads', ['branch_id' => $firstBranch->id, 'external_lead_id' => 'ID-1', 'external_sync_id' => 'SYNC-ONE', 'current_status' => 'discussion']);
        $this->assertDatabaseHas('sales_leads', ['branch_id' => $secondBranch->id, 'external_lead_id' => 'ID-1', 'external_sync_id' => 'SYNC-TWO', 'current_status' => 'no_response']);
        $this->assertSame(2, SalesLeadLifecycleSyncStatus::query()->where('status', 'partial_success')->count());
    }

    public function test_reference_google_date_format_and_lead_business_identity_are_stable(): void
    {
        [$branch, $project, $sales] = $this->context('sheet-reference-date', 'Reference Project', 'Reference Sales');
        $google = Mockery::mock(GoogleSheetsApiService::class);
        $this->expectSheets($google, 'sheet-reference-date', [
            'lead' => [
                ['id_lead', 'tanggal_lead', 'nama_konsumen', 'proyek', 'sales_pic', 'status_lead', 'sumber_lead', 'kanal_masuk', 'aktivitas_lead'],
                ['260722-ON-TI-01', '22-Jul-2026', 'Reference Consumer', $project->project_name, $sales->name, 'Diskusi', 'Online', 'Instagram', 'Agustus'],
            ],
        ]);
        $this->app->instance(GoogleSheetsApiService::class, $google);

        $this->assertTrue(app(SalesLeadLifecycleSyncService::class)->sync($branch)['ok']);
        $lead = SalesLead::query()->where('branch_id', $branch->id)->where('external_lead_id', '260722-ON-TI-01')->firstOrFail();

        $this->assertSame('2026-07-22', $lead->lead_date->toDateString());
        $this->assertDatabaseHas('sales_lead_status_histories', [
            'sales_lead_id' => $lead->id,
            'source' => 'lead_sheet_sync',
            'source_id' => 'lead:260722-ON-TI-01',
            'status' => 'discussion',
        ]);
    }

    public function test_pull_captures_source_snapshot_once_and_ignores_deleted_remote_leads(): void
    {
        [$branch, $project, $sales] = $this->context('sheet-source', 'Source Project', 'Source Sales');
        $google = Mockery::mock(GoogleSheetsApiService::class);
        $first = $this->leadSheet('SOURCE-1', $project->project_name, $sales->name, 'No Respon', 'SYNC-SOURCE');
        $first[1][6] = 'Workbook Awal';
        $this->expectSheets($google, 'sheet-source', ['lead' => $first]);
        $this->app->instance(GoogleSheetsApiService::class, $google);

        app(SalesLeadLifecycleSyncService::class)->sync($branch);
        $lead = SalesLead::query()->where('external_sync_id', 'SYNC-SOURCE')->firstOrFail();
        $this->assertSame('Workbook Awal', $lead->source);
        $this->assertSame('Workbook Awal', $lead->source_name_snapshot);

        $updatedGoogle = Mockery::mock(GoogleSheetsApiService::class);
        $updated = $this->leadSheet('SOURCE-1', $project->project_name, $sales->name, 'Diskusi', 'SYNC-SOURCE');
        $updated[1][6] = 'Workbook Kini';
        $deleted = $this->leadSheet('SOURCE-1', $project->project_name, $sales->name, 'Akad', 'SYNC-SOURCE');
        $header = $deleted[0];
        $header[] = 'oasis_deleted_at';
        $deleted[1][] = '2026-08-04 09:00:00';
        $updated[1][] = '';
        $this->expectSheets($updatedGoogle, 'sheet-source', ['lead' => [$header, $updated[1], $deleted[1]]]);
        $this->app->instance(GoogleSheetsApiService::class, $updatedGoogle);

        $result = app(SalesLeadLifecycleSyncService::class)->sync($branch);
        $this->assertSame('Workbook Kini', $lead->fresh()->source);
        $this->assertSame('Workbook Awal', $lead->source_name_snapshot);
        $this->assertSame(1, $result['summary']['ignored_deleted']);
        $this->assertSame(SalesLeadStatus::Discussion, $lead->fresh()->current_status);
    }

    public function test_pull_maps_physical_lead_headers_into_internal_fields(): void
    {
        [$branch, $project, $sales] = $this->context('sheet-read-map', 'Read Map Project', 'Read Map Sales');
        $google = Mockery::mock(GoogleSheetsApiService::class);
        $this->expectSheets($google, 'sheet-read-map', ['lead' => [
            ['id_lead', 'tanggal_lead', 'nama_konsumen', 'proyek', 'sales_pic', 'status_lead', 'sumber_lead', 'kanal_masuk', 'aktivitas_lead'],
            ['READ-MAP-1', '2026-08-03', 'Consumer Read', $project->project_name, $sales->name, 'No Respon', 'Online', 'Instagram', 'Agustus'],
        ]]);
        $this->app->instance(GoogleSheetsApiService::class, $google);

        app(SalesLeadLifecycleSyncService::class)->sync($branch);

        $lead = SalesLead::query()->where('branch_id', $branch->id)->where('external_lead_id', 'READ-MAP-1')->firstOrFail();
        $this->assertSame('Online', $lead->source);
        $this->assertSame('Instagram', $lead->platform);
        $this->assertSame('Agustus', $lead->campaign_name);
    }

    public function test_pull_ignores_helper_column_duplicates_after_the_first_occurrence(): void
    {
        [$branch, $project, $sales] = $this->context('sheet-helper-dup', 'Helper Project', 'Helper Sales');
        $google = Mockery::mock(GoogleSheetsApiService::class);
        $this->expectSheets($google, 'sheet-helper-dup', ['lead' => [
            ['id_lead', 'tanggal_lead', 'nama_konsumen', 'proyek', 'sales_pic', 'status_lead', 'sumber_lead', 'kanal_masuk', 'aktivitas_lead', '', 'sumber_lead', 'kanal_masuk', 'aktivitas_lead'],
            ['HELP-1', '2026-08-03', 'Helper Consumer', $project->project_name, $sales->name, 'No Respon', 'Online', 'Instagram', 'Agustus', '', 'Referral', 'IG Ads', 'Agustus'],
        ]]);
        $this->app->instance(GoogleSheetsApiService::class, $google);

        $result = app(SalesLeadLifecycleSyncService::class)->sync($branch);

        $this->assertTrue($result['ok']);
        $lead = SalesLead::query()->where('branch_id', $branch->id)->where('external_lead_id', 'HELP-1')->firstOrFail();
        $this->assertSame('Online', $lead->source);
        $this->assertSame('Instagram', $lead->platform);
        $this->assertSame('Agustus', $lead->campaign_name);
    }

    public function test_missing_optional_sheet_disables_capability_without_fallback_and_bad_lead_header_fails(): void
    {
        [$branch, $project, $sales] = $this->context('sheet-capability', 'Capability Project', 'Capability Sales');
        $google = Mockery::mock(GoogleSheetsApiService::class);
        $this->expectSheets($google, 'sheet-capability', ['lead' => $this->leadSheet('CAP-1', $project->project_name, $sales->name, 'No Respon')]);
        $this->app->instance(GoogleSheetsApiService::class, $google);

        $result = app(SalesLeadLifecycleSyncService::class)->sync($branch);

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['summary']['capabilities']['akad']);
        $this->assertDatabaseHas('sales_lead_lifecycle_reconciliation_items', ['branch_id' => $branch->id, 'entity_type' => 'capability', 'identity_key' => 'akad', 'issue_code' => 'sheet_missing', 'status' => 'open']);

        [$badBranch] = $this->context('sheet-bad', 'Bad Project', 'Bad Sales');
        $badGoogle = Mockery::mock(GoogleSheetsApiService::class);
        $this->expectSheets($badGoogle, 'sheet-bad', ['lead' => [['id_lead'], ['BAD-1']]]);
        $this->app->instance(GoogleSheetsApiService::class, $badGoogle);

        $this->assertFalse(app(SalesLeadLifecycleSyncService::class)->sync($badBranch)['ok']);
        $this->assertSame('failed', SalesLeadLifecycleSyncStatus::query()->where('branch_id', $badBranch->id)->value('status'));

        $missingSpreadsheet = Branch::query()->create(['name' => 'Missing Spreadsheet', 'code' => 'MISS', 'is_active' => true]);
        $this->assertFalse(app(SalesLeadLifecycleSyncService::class)->sync($missingSpreadsheet)['ok']);
        $this->assertSame('Spreadsheet cabang belum dikonfigurasi.', SalesLeadLifecycleSyncStatus::query()->where('branch_id', $missingSpreadsheet->id)->value('message'));
    }

    public function test_sync_is_success_only_when_all_relevant_capabilities_are_healthy_and_resolved(): void
    {
        [$branch, $project, $sales] = $this->context('sheet-healthy', 'Healthy Project', 'Healthy Sales');
        $google = Mockery::mock(GoogleSheetsApiService::class);
        $this->expectSheets($google, 'sheet-healthy', [
            'lead' => $this->leadSheet('HEALTHY-1', $project->project_name, $sales->name, 'No Respon'),
            'data_konsumen' => [['id_kavling', 'no_ktp', 'nama_konsumen']],
            'data_konsumen_nup' => [['nup', 'no_ktp', 'nama_konsumen']],
            'bi_checking' => [['id_kavling', 'tanggal_slik', 'hasil_slik']],
            'akad' => [['id_kavling', 'tanggal_akad']],
            'data_sales' => [['nik_sales', 'nama_sales']],
            'data_ceklok' => [['nama_konsumen', 'tanggal_ceklok', 'status_ceklok']],
        ]);
        $this->app->instance(GoogleSheetsApiService::class, $google);

        $result = app(SalesLeadLifecycleSyncService::class)->sync($branch);

        $this->assertSame('success', $result['status']);
        $this->assertSame(0, $result['summary']['unresolved']);
        $this->assertDatabaseHas('sales_lead_lifecycle_sync_statuses', ['branch_id' => $branch->id, 'status' => 'success']);
        $this->assertNotNull(SalesLeadLifecycleSyncStatus::query()->where('branch_id', $branch->id)->value('duration_ms'));
    }

    public function test_ambiguous_project_rows_remain_unlinked(): void
    {
        [$branch, $project, $sales] = $this->context('sheet-ambiguous', 'Duplicate Project', 'Assigned Sales');
        LeadMaster::query()->create(['branch_id' => $branch->id, 'project_name' => $project->project_name, 'is_active' => true]);
        $google = Mockery::mock(GoogleSheetsApiService::class);
        $this->expectSheets($google, 'sheet-ambiguous', ['lead' => $this->leadSheet('AMB-1', $project->project_name, $sales->name, 'Diskusi')]);
        $this->app->instance(GoogleSheetsApiService::class, $google);

        $this->assertTrue(app(SalesLeadLifecycleSyncService::class)->sync($branch)['ok']);
        $this->assertDatabaseMissing('sales_leads', ['branch_id' => $branch->id, 'external_lead_id' => 'AMB-1']);
        $this->assertDatabaseHas('sales_lead_lifecycle_reconciliation_items', ['branch_id' => $branch->id, 'issue_code' => 'project_ambiguous', 'status' => 'open']);
    }

    public function test_project_and_sales_sheet_mappings_are_branch_scoped_and_preferred_on_pull(): void
    {
        [$branch, $project, $sales] = $this->context('sheet-mapped', 'Internal Project', 'Internal Sales');
        [$otherBranch, , $otherSales] = $this->context('sheet-other-mapped', 'Other Project', 'Other Sales');
        $project->update(['sheet_project_name' => 'PROJECT-SHEET']);
        SalesSheetIdentity::query()->create(['branch_id' => $branch->id, 'user_id' => $sales->id, 'spreadsheet_value' => 'SALES-SHEET']);
        SalesSheetIdentity::query()->create(['branch_id' => $otherBranch->id, 'user_id' => $otherSales->id, 'spreadsheet_value' => 'OTHER-SHEET']);
        $google = Mockery::mock(GoogleSheetsApiService::class);
        $this->expectSheets($google, 'sheet-mapped', ['lead' => $this->leadSheet('MAP-1', 'PROJECT-SHEET', 'SALES-SHEET', 'Diskusi')]);
        $this->app->instance(GoogleSheetsApiService::class, $google);

        $this->assertTrue(app(SalesLeadLifecycleSyncService::class)->sync($branch)['ok']);

        $this->assertDatabaseHas('sales_leads', ['branch_id' => $branch->id, 'external_lead_id' => 'MAP-1', 'project_id' => $project->id, 'sales_user_id' => $sales->id]);
        $this->assertDatabaseMissing('sales_leads', ['branch_id' => $otherBranch->id, 'external_lead_id' => 'MAP-1']);
    }

    public function test_cec_silk_requires_branch_linked_bi_attempt_and_never_cross_links(): void
    {
        [$branch, $project, $sales] = $this->context('sheet-slik', 'SLIK Project', 'SLIK Sales');
        [$otherBranch] = $this->context('sheet-other', 'Other Project', 'Other Sales');
        $lead = $this->lead($branch, $project, $sales, 'SLIK-1', 'SYNC-SLIK');
        $otherLead = SalesLead::query()->create([
            'branch_id' => $otherBranch->id,
            'project_id' => LeadMaster::query()->where('branch_id', $otherBranch->id)->value('id'),
            'sales_user_id' => User::query()->where('branch_id', $otherBranch->id)->value('id'),
            'lead_date' => '2026-08-03',
            'customer_name' => 'Other Consumer',
        ]);
        SalesLeadConsumerLink::query()->create(['sales_lead_id' => $otherLead->id, 'branch_id' => $otherBranch->id, 'status' => 'completed', 'sheet_type' => 'data_konsumen', 'nik' => '999', 'id_kavling' => 'KAV-1']);
        $consumer = SalesLeadConsumerLink::query()->create(['sales_lead_id' => $lead->id, 'branch_id' => $branch->id, 'status' => 'completed', 'sheet_type' => 'data_konsumen', 'nik' => '111', 'id_kavling' => 'KAV-1']);
        $google = Mockery::mock(GoogleSheetsApiService::class);
        $this->expectSheets($google, 'sheet-slik', [
            'lead' => $this->leadSheet('SLIK-1', $project->project_name, $sales->name, 'Cek Silk', 'SYNC-SLIK'),
            'bi_checking' => [
                ['id_kavling', 'tanggal_slik', 'hasil_slik', 'oasis_sync_id'],
                ['KAV-1', '2026-08-03', '', 'BI-ONE'],
            ],
        ]);
        $this->app->instance(GoogleSheetsApiService::class, $google);

        $this->assertTrue(app(SalesLeadLifecycleSyncService::class)->sync($branch)['ok']);
        $this->assertSame(SalesLeadStatus::SlikCheck, $lead->fresh()->current_status);
        $this->assertDatabaseHas('sales_lead_slik_attempts', ['branch_id' => $branch->id, 'consumer_link_id' => $consumer->id, 'oasis_sync_id' => 'BI-ONE']);
        $this->assertDatabaseMissing('sales_lead_slik_attempts', ['branch_id' => $otherBranch->id, 'oasis_sync_id' => 'BI-ONE']);
    }

    public function test_akad_requires_valid_date_is_idempotent_and_does_not_downgrade(): void
    {
        [$branch, $project, $sales] = $this->context('sheet-akad', 'Akad Project', 'Akad Sales');
        $lead = $this->lead($branch, $project, $sales, 'AKAD-1', 'SYNC-AKAD');
        $consumer = SalesLeadConsumerLink::query()->create(['sales_lead_id' => $lead->id, 'branch_id' => $branch->id, 'status' => 'completed', 'sheet_type' => 'data_konsumen', 'nik' => '123', 'id_kavling' => 'KAV-A']);
        $google = Mockery::mock(GoogleSheetsApiService::class);
        $sheets = [
            'lead' => $this->leadSheet('AKAD-1', $project->project_name, $sales->name, 'Akad', 'SYNC-AKAD'),
            'akad' => [
                ['id_kavling', 'tanggal_akad', 'no_ppjb_akad', 'oasis_sync_id'],
                ['KAV-A', '2026-08-03', 'PPJB-1', 'AKAD-REMOTE'],
                ['KAV-B', 'bukan-tanggal', 'PPJB-2', 'AKAD-BAD'],
            ],
        ];
        $this->expectSheets($google, 'sheet-akad', $sheets, 2);
        $this->app->instance(GoogleSheetsApiService::class, $google);
        $service = app(SalesLeadLifecycleSyncService::class);

        $this->assertTrue($service->sync($branch)['ok']);
        $this->assertTrue($service->sync($branch)['ok']);

        $this->assertSame(1, SalesLeadAkadLink::query()->where('consumer_link_id', $consumer->id)->count());
        $this->assertSame(SalesLeadStatus::Akad, $lead->fresh()->current_status);
        $this->assertDatabaseHas('sales_lead_lifecycle_reconciliation_items', ['branch_id' => $branch->id, 'identity_key' => 'AKAD-BAD', 'issue_code' => 'akad_date_invalid', 'status' => 'open']);
        $this->assertSame(1, $lead->statusHistories()->where('status', 'akad')->where('source', 'akad_sync')->count());

        $lead->update(['current_status' => SalesLeadStatus::Akad]);
        $lowerSheets = ['lead' => $this->leadSheet('AKAD-1', $project->project_name, $sales->name, 'Diskusi', 'SYNC-AKAD')];
        $lowerGoogle = Mockery::mock(GoogleSheetsApiService::class);
        $this->expectSheets($lowerGoogle, 'sheet-akad', $lowerSheets);
        $this->app->instance(GoogleSheetsApiService::class, $lowerGoogle);
        $this->assertTrue(app(SalesLeadLifecycleSyncService::class)->sync($branch)['ok']);
        $this->assertSame(SalesLeadStatus::Akad, $lead->fresh()->current_status);
    }

    public function test_permissions_are_primary_role_only_and_command_continues_after_branch_failure(): void
    {
        [$goodBranch, $project, $sales] = $this->context('sheet-command-good', 'Command Project', 'Command Sales');
        [$badBranch] = $this->context('sheet-command-bad', 'Bad Command Project', 'Bad Command Sales');
        $salesUser = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'sales')->value('id'),
            'branch_id' => $goodBranch->id,
            'password_changed_at' => now(),
        ]);
        $salesUser->roles()->attach(Role::query()->where('slug', 'pusat')->firstOrFail());
        $salesUser->assignedProjects()->attach($project, ['is_active' => true]);
        $this->assertTrue($salesUser->hasPermission('sales_pocketbook.sync'));
        foreach (['supervisor', 'manager', 'branch_manager', 'pusat', 'admin'] as $roleSlug) {
            $this->assertTrue(Role::query()->where('slug', $roleSlug)->firstOrFail()->permissions()->where('slug', 'sales_pocketbook.sync')->exists(), $roleSlug);
            $this->assertTrue(Role::query()->where('slug', $roleSlug)->firstOrFail()->permissions()->where('slug', 'sales_pocketbook.reconcile')->exists(), $roleSlug);
        }
        $this->assertTrue(Role::query()->where('slug', 'sales')->firstOrFail()->permissions()->where('slug', 'sales_pocketbook.sync')->exists());
        $this->assertFalse(Role::query()->where('slug', 'sales')->firstOrFail()->permissions()->where('slug', 'sales_pocketbook.reconcile')->exists());
        foreach (['sales_coordinator', 'staff'] as $roleSlug) {
            $this->assertFalse(Role::query()->where('slug', $roleSlug)->firstOrFail()->permissions()->whereIn('slug', ['sales_pocketbook.sync', 'sales_pocketbook.reconcile'])->exists(), $roleSlug);
        }

        $google = Mockery::mock(GoogleSheetsApiService::class);
        $google->shouldReceive('sheetTitles')->once()->with('sheet-command-bad')->andReturn([]);
        $this->expectSheets($google, 'sheet-command-good', ['lead' => $this->leadSheet('CMD-1', $project->project_name, $sales->name, 'No Respon')]);
        $this->app->instance(GoogleSheetsApiService::class, $google);

        $this->artisan('sales-lead-lifecycle:sync')->assertFailed();
        $this->assertDatabaseHas('sales_leads', ['branch_id' => $goodBranch->id, 'external_lead_id' => 'CMD-1']);
        $this->assertDatabaseHas('sales_lead_lifecycle_sync_statuses', ['branch_id' => $badBranch->id, 'status' => 'failed']);
        $this->assertDatabaseHas('sales_lead_lifecycle_sync_statuses', ['branch_id' => $goodBranch->id, 'status' => 'partial_success']);
    }

    public function test_primary_sales_syncs_only_current_project_branch_and_scoped_viewers_can_read_status(): void
    {
        [$branch, $project] = $this->context('sheet-sales-sync', 'Sales Sync Project', 'Assigned Sales');
        [$otherBranch] = $this->context('sheet-sales-other', 'Other Sync Project', 'Other Sales');
        $salesUser = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'sales')->value('id'),
            'branch_id' => $branch->id,
            'password_changed_at' => now(),
        ]);
        $salesUser->assignedProjects()->attach($project, ['is_active' => true, 'assignment_start_date' => today()->subDay()]);
        SalesLeadLifecycleSyncStatus::query()->create([
            'branch_id' => $branch->id,
            'scope' => SalesLeadSyncService::userScope($salesUser->id),
            'status' => 'partial_success',
            'summary' => ['imported' => 1, 'updated' => 0, 'linked' => 0, 'unresolved' => 1, 'capabilities' => ['lead' => true]],
        ]);
        $service = Mockery::mock(SalesLeadSyncService::class);
        $service->shouldReceive('sync')->once()->withArgs(fn (Branch $candidate, User $actor) => $candidate->is($branch) && $actor->is($salesUser))
            ->andReturn(['ok' => true, 'status' => 'partial_success', 'summary' => ['unresolved' => 1]]);
        $this->app->instance(SalesLeadSyncService::class, $service);

        $this->actingAs($salesUser)->getJson(route('sales-pocketbook.lifecycle-sync.status', ['branch_id' => $branch->id]))
            ->assertOk()->assertJsonPath('status', 'partial_success');
        $this->actingAs($salesUser)->postJson(route('sales-pocketbook.lifecycle-sync'), ['branch_id' => $branch->id])
            ->assertOk()->assertJsonPath('status', 'partial_success');
        $this->actingAs($salesUser)->postJson(route('sales-pocketbook.lifecycle-sync'), ['branch_id' => $otherBranch->id])->assertForbidden();
        $this->actingAs($salesUser)->getJson(route('sales-pocketbook.lifecycle-reconciliations.index', ['branch_id' => $branch->id]))->assertForbidden();
        $this->assertFalse($salesUser->hasPermission('database.sync'));

        $supplemental = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'sales_coordinator')->value('id'),
            'branch_id' => $branch->id,
            'password_changed_at' => now(),
        ]);
        $supplemental->roles()->attach(Role::query()->where('slug', 'pusat')->firstOrFail());
        $this->assertFalse($supplemental->hasPermission('sales_pocketbook.sync'));
        $this->actingAs($supplemental)->postJson(route('sales-pocketbook.lifecycle-sync'), ['branch_id' => $branch->id])->assertForbidden();
    }

    private function context(string $sheetId, string $projectName, string $salesName): array
    {
        $branch = Branch::query()->create(['name' => 'Branch '.Str::random(6), 'code' => strtoupper(Str::random(6)), 'sheet_id' => $sheetId, 'is_active' => true]);
        $project = LeadMaster::query()->create(['branch_id' => $branch->id, 'project_name' => $projectName, 'is_active' => true]);
        $sales = User::factory()->create(['name' => $salesName, 'branch_id' => $branch->id]);
        $sales->assignedProjects()->attach($project->id, ['is_active' => true, 'is_primary' => true]);

        return [$branch, $project, $sales];
    }

    private function lead(Branch $branch, LeadMaster $project, User $sales, string $externalId, string $syncId): SalesLead
    {
        return SalesLead::query()->create([
            'branch_id' => $branch->id,
            'project_id' => $project->id,
            'sales_user_id' => $sales->id,
            'lead_date' => '2026-08-03',
            'customer_name' => 'Consumer '.$externalId,
            'external_lead_id' => $externalId,
            'external_sync_id' => $syncId,
        ]);
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
