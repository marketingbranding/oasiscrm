<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DatabaseSheetRecord;
use App\Models\Role;
use App\Models\User;
use App\Services\DatabaseSheetSyncService;
use App\Services\DatabaseSheetWriteService;
use App\Services\GoogleSheetsApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class DatabaseSheetMetadataTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_maps_google_column_metadata_to_headers(): void
    {
        $branch = Branch::create([
            'name' => 'Test Branch',
            'code' => 'TEST',
            'sheet_id' => 'spreadsheet-id',
            'is_active' => true,
        ]);
        $ranges = ["'Leads'!A:ZZ"];
        $rows = [
            ['nama', 'status', 'tanggal'],
            ['Idam', 'Aktif', '15/07/2026'],
        ];

        $googleSheets = Mockery::mock(GoogleSheetsApiService::class);
        $googleSheets->shouldReceive('sheetTitles')->once()->with('spreadsheet-id')->andReturn(['Leads']);
        $googleSheets->shouldReceive('quoteSheetName')->once()->with('Leads')->andReturn("'Leads'");
        $googleSheets->shouldReceive('batchGetRaw')->once()
            ->with('spreadsheet-id', $ranges, 'FORMATTED_VALUE')->andReturn(['Leads' => $rows]);
        $googleSheets->shouldReceive('batchGetRaw')->once()
            ->with('spreadsheet-id', $ranges, 'FORMULA')->andReturn(['Leads' => $rows]);
        $googleSheets->shouldReceive('columnMetadata')->once()->with('spreadsheet-id', ['Leads'])->andReturn([
            'Leads' => [
                1 => ['type' => 'select', 'options' => ['Aktif', 'Tidak Aktif'], 'strict' => true],
                2 => ['type' => 'date'],
            ],
        ]);

        $result = (new DatabaseSheetSyncService($googleSheets))->syncBranch($branch);

        $this->assertTrue($result['ok'], $result['message']);
        $record = DatabaseSheetRecord::firstOrFail();
        $this->assertSame('select', $record->column_metadata['status']['type']);
        $this->assertSame(['Aktif', 'Tidak Aktif'], $record->column_metadata['status']['options']);
        $this->assertSame('date', $record->column_metadata['tanggal']['type']);
    }

    public function test_sheet_endpoint_returns_column_metadata(): void
    {
        $role = Role::create([
            'name' => 'Admin',
            'slug' => 'admin',
            'is_superadmin' => false,
        ]);
        $branch = Branch::create([
            'name' => 'Test Branch',
            'code' => 'TEST',
            'sheet_id' => 'spreadsheet-id',
            'is_active' => true,
        ]);
        $user = User::factory()->create([
            'role_id' => $role->id,
            'branch_id' => $branch->id,
            'password_changed_at' => now(),
        ]);
        DatabaseSheetRecord::create([
            'branch_id' => $branch->id,
            'sheet_id' => $branch->sheet_id,
            'sheet_name' => 'Leads',
            'row_number' => 2,
            'headers' => ['nama', 'status'],
            'row_data' => ['nama' => 'Idam', 'status' => 'Aktif'],
            'formula_columns' => [],
            'column_metadata' => [
                'status' => ['type' => 'select', 'options' => ['Aktif', 'Tidak Aktif'], 'strict' => true],
            ],
        ]);

        $response = $this->actingAs($user)->get(route('database.sheet', [
            'branchId' => $branch->id,
            'sheetName' => 'Leads',
        ]));

        $response
            ->assertOk()
            ->assertJsonPath('column_metadata.status.type', 'select')
            ->assertJsonPath('column_metadata.status.options.1', 'Tidak Aktif');
    }

    public function test_update_rejects_value_outside_strict_dropdown_options(): void
    {
        $role = Role::create([
            'name' => 'Admin',
            'slug' => 'admin',
            'is_superadmin' => false,
        ]);
        $branch = Branch::create([
            'name' => 'Test Branch',
            'code' => 'TEST',
            'sheet_id' => 'spreadsheet-id',
            'is_active' => true,
        ]);
        $user = User::factory()->create([
            'role_id' => $role->id,
            'branch_id' => $branch->id,
            'password_changed_at' => now(),
        ]);
        $record = DatabaseSheetRecord::create([
            'branch_id' => $branch->id,
            'sheet_id' => $branch->sheet_id,
            'sheet_name' => 'Leads',
            'row_number' => 2,
            'headers' => ['status'],
            'row_data' => ['status' => 'Aktif'],
            'formula_columns' => [],
            'column_metadata' => [
                'status' => ['type' => 'select', 'options' => ['Aktif', 'Tidak Aktif'], 'strict' => true],
            ],
        ]);

        $writeService = Mockery::mock(DatabaseSheetWriteService::class);
        $writeService->shouldNotReceive('updateRecord');
        $this->app->instance(DatabaseSheetWriteService::class, $writeService);

        $response = $this->actingAs($user)->put(route('database.records.update', $record), [
            'status' => 'Pilihan Tidak Sah',
        ]);

        $response->assertSessionHasErrors('status');
    }

    public function test_create_copies_row_format_and_column_metadata(): void
    {
        $branch = Branch::create([
            'name' => 'Test Branch',
            'code' => 'TEST',
            'sheet_id' => 'spreadsheet-id',
            'is_active' => true,
        ]);
        DatabaseSheetRecord::create([
            'branch_id' => $branch->id,
            'sheet_id' => $branch->sheet_id,
            'sheet_name' => 'Leads',
            'row_number' => 2,
            'headers' => ['status'],
            'row_data' => ['status' => 'Aktif'],
            'formula_columns' => [],
            'column_metadata' => [
                'status' => ['type' => 'select', 'options' => ['Aktif', 'Tidak Aktif'], 'strict' => true],
            ],
        ]);

        $googleSheets = Mockery::mock(GoogleSheetsApiService::class);
        $googleSheets->shouldReceive('sheetIds')->once()->with('spreadsheet-id')->andReturn(['Leads' => 123]);
        $googleSheets->shouldReceive('copyRowFormat')->once()->with('spreadsheet-id', 123, 2, 3);
        $googleSheets->shouldReceive('copyRowFormulas')->once()->with('spreadsheet-id', 123, 2, 3);
        $googleSheets->shouldReceive('quoteSheetName')->once()->with('Leads')->andReturn("'Leads'");
        $googleSheets->shouldReceive('updateRange')->once()
            ->with('spreadsheet-id', "'Leads'!A3", [['Tidak Aktif']]);
        $syncService = Mockery::mock(DatabaseSheetSyncService::class);
        $syncService->shouldReceive('columnLetter')->once()->with(1)->andReturn('A');

        $created = (new DatabaseSheetWriteService($googleSheets, $syncService))
            ->createRecord($branch, 'Leads', ['status' => 'Tidak Aktif']);

        $this->assertTrue($created);
        $record = DatabaseSheetRecord::where('row_number', 3)->firstOrFail();
        $this->assertSame('select', $record->column_metadata['status']['type']);
    }
}
