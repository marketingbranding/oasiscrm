<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DatabaseSheetRecord;
use App\Models\LeadMaster;
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

    public function test_br31_unknown_project_label_is_not_operationally_owned_when_catalog_is_branch_scoped(): void
    {
        $branch = $this->branch('Asal', 'ASL', 'sheet-asal');
        $otherBranch = $this->branch('Lain', 'LAIN', 'sheet-lain');
        LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Proyek Asal', 'is_active' => true]);
        LeadMaster::create(['branch_id' => $otherBranch->id, 'project_name' => 'Proyek Lain', 'is_active' => true]);

        $this->assertFalse(app(SheetProjectOwnershipService::class)->isOperationallyOwned($branch, ['project_name' => 'Belum Terdaftar']));
    }

    public function test_br31_database_sheet_and_pipeline_reads_keep_unmapped_rows_without_foreign_rows(): void
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
        ]);

        $user = User::factory()->create(['role_id' => Role::query()->where('slug', 'superadmin')->value('id'), 'password_changed_at' => now()]);
        $response = $this->actingAs($user)->getJson(route('database.sheet', ['branchId' => $branch->id, 'sheetName' => 'data_konsumen']));

        $response->assertOk()->assertJsonCount(1, 'records')->assertJsonPath('records.0.row_number', 3);
    }

    private function branch(string $name, string $code, string $sheetId): Branch
    {
        return Branch::create(['name' => $name, 'code' => $code, 'sheet_id' => $sheetId, 'is_active' => true]);
    }
}
