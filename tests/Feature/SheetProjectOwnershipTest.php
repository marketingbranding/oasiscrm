<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DatabaseSheetRecord;
use App\Models\LeadMaster;
use App\Models\ProjectTransferBatch;
use App\Models\Role;
use App\Models\User;
use App\Services\SheetProjectOwnershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SheetProjectOwnershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_br31_sheet_rows_for_foreign_project_are_excluded_and_counted(): void
    {
        $branch = $this->branch('Asal', 'ASL', 'sheet-asal');
        $foreignBranch = $this->branch('Lain', 'LAIN', 'sheet-lain');
        $project = LeadMaster::create(['branch_id' => $foreignBranch->id, 'project_name' => 'Proyek Lain', 'sheet_project_name' => 'Alias Lain', 'is_active' => true]);

        $result = app(SheetProjectOwnershipService::class)->filterRows($branch, [
            ['project_name' => 'Proyek Lain'],
            ['project_name' => 'Tidak Terpetakan'],
            ['nama' => 'Tanpa proyek'],
        ]);

        $this->assertCount(2, $result['rows']);
        $this->assertSame(1, $result['foreign_project_rows']);
        $this->assertSame(1, $result['unmapped_project_rows']);
        $this->assertSame('Proyek Lain', $project->project_name);
        $this->assertSame(['foreign', 'unmapped', 'unmapped'], $result['statuses']);
    }

    public function test_br31_foreign_rows_are_retained_only_in_audit_output(): void
    {
        $branch = $this->branch('Asal', 'ASL', 'sheet-asal');
        $foreignBranch = $this->branch('Lain', 'LAIN', 'sheet-lain');
        LeadMaster::create(['branch_id' => $foreignBranch->id, 'project_name' => 'Proyek Lain', 'is_active' => true]);

        $result = app(SheetProjectOwnershipService::class)->filterRows($branch, [['project_name' => 'Proyek Lain']]);

        $this->assertSame([], $result['rows']);
        $this->assertSame([['project_name' => 'Proyek Lain']], $result['all_rows']);
        $this->assertSame(['foreign'], $result['statuses']);
    }

    public function test_br31_ambiguous_project_label_is_excluded_from_operational_rows(): void
    {
        $branch = $this->branch('Asal', 'ASL', 'sheet-asal');
        $otherBranch = $this->branch('Lain', 'LAIN', 'sheet-lain');
        LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Nama Sama', 'is_active' => true]);
        LeadMaster::create(['branch_id' => $otherBranch->id, 'project_name' => 'Nama Sama', 'is_active' => true]);

        $result = app(SheetProjectOwnershipService::class)->filterRows($branch, [['project_name' => 'Nama Sama']]);

        $this->assertSame([], $result['rows']);
        $this->assertSame(1, $result['foreign_project_rows']);
    }

    public function test_br31_unlabeled_row_is_not_operationally_owned_when_catalog_is_branch_scoped(): void
    {
        $branch = $this->branch('Asal', 'ASL', 'sheet-asal');
        LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Proyek Asal', 'is_active' => true]);

        $this->assertFalse(app(SheetProjectOwnershipService::class)->isOperationallyOwned($branch, ['nama' => 'Tanpa proyek']));
    }

    public function test_br31_unknown_project_label_is_not_operationally_owned_when_catalog_is_branch_scoped(): void
    {
        $branch = $this->branch('Asal', 'ASL', 'sheet-asal');
        $otherBranch = $this->branch('Lain', 'LAIN', 'sheet-lain');
        LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Proyek Asal', 'is_active' => true]);
        LeadMaster::create(['branch_id' => $otherBranch->id, 'project_name' => 'Proyek Lain', 'is_active' => true]);

        $this->assertFalse(app(SheetProjectOwnershipService::class)->isOperationallyOwned($branch, ['project_name' => 'Belum Terdaftar']));
    }

    public function test_br31_unique_project_ignores_stale_cache_status_for_current_owner(): void
    {
        $branch = $this->branch('Asal', 'ASL', 'sheet-asal');
        LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Proyek Asal', 'is_active' => true]);

        $service = app(SheetProjectOwnershipService::class);

        $this->assertTrue($service->allowsOperationalRow($branch, 'foreign', ['project_name' => 'Proyek Asal']));
        $this->assertTrue($service->allowsOperationalRow($branch, 'unmapped', ['project_name' => 'Proyek Asal']));
    }

    public function test_br31_active_transfer_uses_latest_owner_not_cache_status(): void
    {
        $source = $this->branch('Asal', 'ASL', 'sheet-asal');
        $target = $this->branch('Tujuan', 'TJN', 'sheet-tujuan');
        $actor = User::factory()->create(['role_id' => Role::query()->where('slug', 'superadmin')->value('id')]);
        $project = LeadMaster::create(['branch_id' => $target->id, 'project_name' => 'Proyek Pindah', 'is_active' => true]);
        ProjectTransferBatch::query()->create([
            'source_branch_id' => $source->id,
            'target_branch_id' => $target->id,
            'created_by' => $actor->id,
            'project_ids' => [$project->id],
            'user_mapping' => [],
            'status' => 'cache_failed',
            'executed_at' => now(),
        ]);

        $service = app(SheetProjectOwnershipService::class);

        $this->assertFalse($service->allowsOperationalRow($source, 'owned', ['project_name' => 'Proyek Pindah']));
        $this->assertTrue($service->allowsOperationalRow($target, 'unmapped', ['project_name' => 'Proyek Pindah']));
        $this->assertTrue($service->allowsOperationalRow($target, 'foreign', ['project_name' => 'Proyek Pindah']));
    }

    public function test_br31_sequential_transfers_use_latest_active_transfer(): void
    {
        $firstSource = $this->branch('Asal 1', 'AS1', 'sheet-asal-1');
        $secondSource = $this->branch('Asal 2', 'AS2', 'sheet-asal-2');
        $target = $this->branch('Tujuan', 'TJN', 'sheet-tujuan');
        $actor = User::factory()->create(['role_id' => Role::query()->where('slug', 'superadmin')->value('id')]);
        $project = LeadMaster::create(['branch_id' => $target->id, 'project_name' => 'Proyek Berantai', 'is_active' => true]);
        ProjectTransferBatch::query()->create([
            'source_branch_id' => $firstSource->id,
            'target_branch_id' => $target->id,
            'created_by' => $actor->id,
            'project_ids' => [$project->id],
            'user_mapping' => [],
            'status' => 'completed',
            'executed_at' => now()->subMinute(),
        ]);
        ProjectTransferBatch::query()->create([
            'source_branch_id' => $target->id,
            'target_branch_id' => $secondSource->id,
            'created_by' => $actor->id,
            'project_ids' => [$project->id],
            'user_mapping' => [],
            'status' => 'committed',
            'executed_at' => now(),
        ]);

        $service = app(SheetProjectOwnershipService::class);

        $this->assertFalse($service->allowsOperationalRow($target, 'owned', ['project_name' => 'Proyek Berantai']));
        $this->assertTrue($service->allowsOperationalRow($secondSource, 'foreign', ['project_name' => 'Proyek Berantai']));
    }

    public function test_br31_rolled_back_transfer_uses_current_project_owner(): void
    {
        $source = $this->branch('Asal', 'ASL', 'sheet-asal');
        $target = $this->branch('Tujuan', 'TJN', 'sheet-tujuan');
        $actor = User::factory()->create(['role_id' => Role::query()->where('slug', 'superadmin')->value('id')]);
        $project = LeadMaster::create(['branch_id' => $source->id, 'project_name' => 'Proyek Kembali', 'is_active' => true]);
        ProjectTransferBatch::query()->create([
            'source_branch_id' => $source->id,
            'target_branch_id' => $target->id,
            'created_by' => $actor->id,
            'project_ids' => [$project->id],
            'user_mapping' => [],
            'status' => 'rolled_back',
            'executed_at' => now(),
            'rolled_back_at' => now(),
        ]);

        $service = app(SheetProjectOwnershipService::class);

        $this->assertTrue($service->allowsOperationalRow($source, 'foreign', ['project_name' => 'Proyek Kembali']));
        $this->assertFalse($service->allowsOperationalRow($target, 'owned', ['project_name' => 'Proyek Kembali']));
    }

    public function test_br31_unknown_and_ambiguous_labels_do_not_get_guessed(): void
    {
        $branch = $this->branch('Asal', 'ASL', 'sheet-asal');
        $otherBranch = $this->branch('Lain', 'LAIN', 'sheet-lain');
        LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Nama Sama', 'is_active' => true]);
        LeadMaster::create(['branch_id' => $otherBranch->id, 'project_name' => 'Nama Sama', 'is_active' => true]);
        $service = app(SheetProjectOwnershipService::class);

        $this->assertFalse($service->allowsOperationalRow($branch, 'unmapped', ['project_name' => 'Tidak Terdaftar']));
        $this->assertFalse($service->allowsOperationalRow($branch, 'foreign', ['project_name' => 'Nama Sama']));
    }

    public function test_br31_database_sheet_reads_use_current_project_ownership_not_stale_status(): void
    {
        $branch = $this->branch('Asal', 'ASL', 'sheet-asal');
        LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Proyek Asal', 'is_active' => true]);
        DatabaseSheetRecord::create([
            'branch_id' => $branch->id,
            'sheet_id' => $branch->sheet_id,
            'sheet_name' => 'data_konsumen',
            'row_number' => 2,
            'row_data' => ['project_name' => 'Proyek Asal'],
            'headers' => ['project_name'],
            'formula_columns' => [],
            'column_metadata' => [],
            'ownership_status' => 'unmapped',
        ]);
        $user = User::factory()->create(['role_id' => Role::query()->where('slug', 'superadmin')->value('id'), 'password_changed_at' => now()]);

        $response = $this->actingAs($user)->getJson(route('database.sheet', ['branchId' => $branch->id, 'sheetName' => 'data_konsumen']));

        $response->assertOk()->assertJsonCount(1, 'records');
    }

    public function test_br31_database_sheet_and_pipeline_reads_exclude_unmapped_and_foreign_rows(): void
    {
        $branch = $this->branch('Asal', 'ASL', 'sheet-asal');
        $foreignBranch = $this->branch('Lain', 'LAIN', 'sheet-lain');
        LeadMaster::create(['branch_id' => $foreignBranch->id, 'project_name' => 'Proyek Lain', 'is_active' => true]);
        DatabaseSheetRecord::create([
            'branch_id' => $branch->id,
            'sheet_id' => $branch->sheet_id,
            'sheet_name' => 'data_konsumen',
            'row_number' => 2,
            'row_data' => ['project_name' => 'Proyek Lain'],
            'headers' => ['project_name'],
            'formula_columns' => [],
            'column_metadata' => [],
            'ownership_status' => 'foreign',
        ]);
        DatabaseSheetRecord::create([
            'branch_id' => $branch->id,
            'sheet_id' => $branch->sheet_id,
            'sheet_name' => 'data_konsumen',
            'row_number' => 3,
            'row_data' => ['nama' => 'Tidak terpetakan'],
            'headers' => ['nama'],
            'formula_columns' => [],
            'column_metadata' => [],
            'ownership_status' => 'unmapped',
        ]);

        $user = User::factory()->create(['role_id' => Role::query()->where('slug', 'superadmin')->value('id'), 'password_changed_at' => now()]);
        $response = $this->actingAs($user)->getJson(route('database.sheet', ['branchId' => $branch->id, 'sheetName' => 'data_konsumen']));

        $response->assertOk()->assertJsonCount(0, 'records');
    }

    private function branch(string $name, string $code, string $sheetId): Branch
    {
        return Branch::create(['name' => $name, 'code' => $code, 'sheet_id' => $sheetId, 'is_active' => true]);
    }
}
