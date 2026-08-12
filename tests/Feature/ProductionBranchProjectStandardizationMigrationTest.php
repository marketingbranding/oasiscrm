<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductionBranchProjectStandardizationMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_standardizes_by_id_and_is_idempotent_without_creating_or_deleting_records(): void
    {
        $branchNames = [
            1 => 'Malang',
            2 => 'Madiun',
            3 => 'Solo',
            4 => 'Magelang',
            5 => 'Purworejo',
            6 => 'Jepara',
            7 => 'Batang',
            8 => 'Sumedang',
            9 => 'Kantor Pusat',
        ];
        $targetBranchNames = [
            1 => 'KC MALANG',
            2 => 'KC MADIUN',
            3 => 'KC SOLO',
            4 => 'KC MAGELANG',
            5 => 'KC PURWOREJO',
            6 => 'KC JEPARA',
            7 => 'KC BATANG',
            8 => 'KC BANDUNG',
            9 => 'Kantor Pusat',
        ];

        DB::table('branches')->insert(collect($branchNames)->map(fn (string $name, int $id) => [
            'id' => $id,
            'name' => $name,
            'code' => "CODE-{$id}",
            'sheet_id' => "sheet-{$id}",
            'is_active' => $id % 2 === 0,
            'created_at' => now(),
            'updated_at' => now(),
        ])->push([
            'id' => 99,
            'name' => 'Cabang Tidak Dipetakan',
            'code' => 'OTHER',
            'sheet_id' => 'sheet-99',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ])->all());

        $projectNames = [
            2 => 'Wagir',
            3 => 'Madiun 2 Perluasan',
            5 => 'Jeruksawit 4',
            6 => 'Wonogiri',
            7 => 'Sragen',
            11 => 'Wonosari',
            12 => 'Wonosari Kios',
            14 => 'Jonggrangan',
            19 => 'Cawang',
            20 => 'Mutiara Garden 5',
            21 => 'Mlonggo 1',
            22 => 'Mlonggo 2',
            23 => 'Kuwasen',
            25 => 'Pati',
            27 => 'Kandeman Batang',
            28 => 'Tegal',
            32 => 'Majalaya 2',
            33 => 'Sumedang',
        ];
        $targetProjectNames = [
            2 => 'Marison Regency Wagir Malang',
            3 => 'Madiun Perluasan',
            5 => 'Jeruksawit 4',
            6 => 'Marison Wonogiri',
            7 => 'Marison Sragen',
            11 => 'Marison Karangrejek Wonosari',
            12 => 'Wonosari Kios',
            14 => 'Marison Kalinegoro',
            19 => 'Cawang',
            20 => 'Mutiara Garden 5',
            21 => 'Marison Jepara',
            22 => 'Marison Jepara Perluasan',
            23 => 'Marison Kuwasen',
            25 => 'Marison Kedungbulus',
            27 => 'Marison Kandeman',
            28 => 'Marison Tegal',
            32 => 'Marison Cipaku Perluasan',
            33 => 'Sumedang',
        ];
        $projectIds = array_keys($projectNames);

        DB::table('lead_master')->whereIn('id', $projectIds)->delete();
        DB::table('lead_master')->insert(collect($projectNames)->map(fn (string $name, int $id) => [
            'id' => $id,
            'branch_id' => (($id - 1) % 9) + 1,
            'project_name' => $name,
            'sheet_project_name' => $id === 14 ? 'Marison Kalinegoro' : null,
            'is_active' => $id % 2 === 0,
            'created_at' => now(),
            'updated_at' => now(),
        ])->push([
            'id' => 99,
            'branch_id' => 99,
            'project_name' => 'Proyek Tidak Dipetakan',
            'sheet_project_name' => null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ])->all());

        $branchCount = DB::table('branches')->count();
        $projectCount = DB::table('lead_master')->count();
        $branchSnapshot = DB::table('branches')->orderBy('id')->get(['id', 'code', 'sheet_id', 'is_active'])->map(fn ($row) => (array) $row)->all();
        $projectSnapshot = DB::table('lead_master')->orderBy('id')->get(['id', 'branch_id', 'is_active'])->map(fn ($row) => (array) $row)->all();
        $migration = require database_path('migrations/2026_08_12_000003_standardize_production_branches_and_projects.php');

        $migration->up();
        $firstRunBranches = DB::table('branches')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $firstRunProjects = DB::table('lead_master')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $migration->up();

        foreach ($targetBranchNames as $id => $name) {
            $this->assertDatabaseHas('branches', ['id' => $id, 'name' => $name]);
        }
        foreach ($targetProjectNames as $id => $name) {
            $expectedSheetName = $id === 14 ? 'Marison Kalinegoro' : ($projectNames[$id] === $name ? null : $projectNames[$id]);
            $this->assertDatabaseHas('lead_master', ['id' => $id, 'project_name' => $name, 'sheet_project_name' => $expectedSheetName]);
        }

        $this->assertDatabaseHas('branches', ['id' => 99, 'name' => 'Cabang Tidak Dipetakan', 'code' => 'OTHER', 'sheet_id' => 'sheet-99', 'is_active' => true]);
        $this->assertDatabaseHas('lead_master', ['id' => 99, 'branch_id' => 99, 'project_name' => 'Proyek Tidak Dipetakan', 'sheet_project_name' => null, 'is_active' => true]);
        $this->assertSame($branchSnapshot, DB::table('branches')->orderBy('id')->get(['id', 'code', 'sheet_id', 'is_active'])->map(fn ($row) => (array) $row)->all());
        $this->assertSame($projectSnapshot, DB::table('lead_master')->orderBy('id')->get(['id', 'branch_id', 'is_active'])->map(fn ($row) => (array) $row)->all());
        $this->assertSame($firstRunBranches, DB::table('branches')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertSame($firstRunProjects, DB::table('lead_master')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertSame($branchCount, DB::table('branches')->count());
        $this->assertSame($projectCount, DB::table('lead_master')->count());
        $this->assertSame(1, DB::table('changelogs')->whereNull('version')->where('title', 'Standardisasi Cabang & Proyek Produksi')->count());
        $this->assertDatabaseHas('changelogs', ['title' => 'Standardisasi Cabang & Proyek Produksi', 'category' => 'changed']);
    }

    public function test_unexpected_branch_name_fails_with_context_and_rolls_back(): void
    {
        DB::table('branches')->insert([
            ['id' => 1, 'name' => 'Malang', 'code' => 'MAL', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'Unexpected Madiun', 'code' => 'MAD', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $changelogCount = DB::table('changelogs')->whereNull('version')->where('title', 'Standardisasi Cabang & Proyek Produksi')->count();
        $migration = require database_path('migrations/2026_08_12_000003_standardize_production_branches_and_projects.php');

        try {
            $migration->up();
            $this->fail('Migration should reject an unexpected branch name.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString("Branch id 2: expected old 'Madiun', actual 'Unexpected Madiun', target 'KC MADIUN'", $exception->getMessage());
        }

        $this->assertDatabaseHas('branches', ['id' => 1, 'name' => 'Malang']);
        $this->assertSame($changelogCount, DB::table('changelogs')->whereNull('version')->where('title', 'Standardisasi Cabang & Proyek Produksi')->count());
    }

    public function test_unexpected_project_name_fails_with_context_and_rolls_back(): void
    {
        DB::table('lead_master')->where('id', 2)->delete();
        DB::table('branches')->insert([
            'id' => 1,
            'name' => 'Malang',
            'code' => 'MAL',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('lead_master')->insert([
            'id' => 2,
            'branch_id' => 1,
            'project_name' => 'Unexpected Wagir',
            'sheet_project_name' => null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $changelogCount = DB::table('changelogs')->whereNull('version')->where('title', 'Standardisasi Cabang & Proyek Produksi')->count();
        $migration = require database_path('migrations/2026_08_12_000003_standardize_production_branches_and_projects.php');

        try {
            $migration->up();
            $this->fail('Migration should reject an unexpected project name.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString("Project id 2: expected old 'Wagir', actual 'Unexpected Wagir', target 'Marison Regency Wagir Malang'", $exception->getMessage());
        }

        $this->assertDatabaseHas('branches', ['id' => 1, 'name' => 'Malang']);
        $this->assertDatabaseHas('lead_master', ['id' => 2, 'project_name' => 'Unexpected Wagir']);
        $this->assertSame($changelogCount, DB::table('changelogs')->whereNull('version')->where('title', 'Standardisasi Cabang & Proyek Produksi')->count());
    }

    public function test_null_sheet_name_uses_expected_old_name_and_existing_sheet_name_is_preserved(): void
    {
        DB::table('lead_master')->whereIn('id', [2, 3])->delete();
        DB::table('branches')->insert([
            'id' => 1,
            'name' => 'Malang',
            'code' => 'MAL',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('lead_master')->insert([
            [
                'id' => 2,
                'branch_id' => 1,
                'project_name' => 'Wagir',
                'sheet_project_name' => null,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 3,
                'branch_id' => 1,
                'project_name' => 'Madiun 2 Perluasan',
                'sheet_project_name' => 'Nama Sheet Tetap',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $migration = require database_path('migrations/2026_08_12_000003_standardize_production_branches_and_projects.php');
        $migration->up();

        $this->assertDatabaseHas('lead_master', ['id' => 2, 'project_name' => 'Marison Regency Wagir Malang', 'sheet_project_name' => 'Wagir']);
        $this->assertDatabaseHas('lead_master', ['id' => 3, 'project_name' => 'Madiun Perluasan', 'sheet_project_name' => 'Nama Sheet Tetap']);

        $migration->up();

        $this->assertDatabaseHas('lead_master', ['id' => 2, 'project_name' => 'Marison Regency Wagir Malang', 'sheet_project_name' => 'Wagir']);
        $this->assertDatabaseHas('lead_master', ['id' => 3, 'project_name' => 'Madiun Perluasan', 'sheet_project_name' => 'Nama Sheet Tetap']);
    }
}
