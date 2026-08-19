<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DatabaseSheetRecord;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\DatabaseSheetWriteService;
use App\Services\GoogleSheetsApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class DatabaseImportCopasTest extends TestCase
{
    use RefreshDatabase;

    private function setupBranchWithTemplate(array $headers = ['id_kavling', 'nama_konsumen', 'tanggal_lahir'], array $columnMetadata = [], array $formulaColumns = []): array
    {
        $branch = Branch::query()->create([
            'name' => 'Magelang',
            'code' => 'MGL',
            'is_active' => true,
            'sheet_id' => 'spreadsheet-id',
        ]);
        $record = DatabaseSheetRecord::query()->create([
            'branch_id' => $branch->id,
            'sheet_id' => $branch->sheet_id,
            'sheet_name' => 'data_konsumen',
            'row_number' => 2,
            'headers' => $headers,
            'row_data' => ['id_kavling' => 'A-01', 'nama_konsumen' => 'Siti'],
            'formula_columns' => $formulaColumns,
            'column_metadata' => $columnMetadata,
        ]);

        return [$branch, $record];
    }

    private function editor(Branch $branch, array $extraPermissions = []): User
    {
        $role = Role::query()->create([
            'name' => 'Database Editor',
            'slug' => 'db_editor_'.uniqid(),
            'is_superadmin' => false,
            'is_active' => true,
        ]);
        $slugs = array_merge(['database.view', 'database.edit', 'database.view_branch', 'database.manage_branch'], $extraPermissions);
        $role->permissions()->sync(Permission::query()->whereIn('slug', $slugs)->pluck('id'));
        $user = User::factory()->create([
            'role_id' => $role->id,
            'branch_id' => $branch->id,
            'password_changed_at' => now(),
        ]);
        $user->branches()->syncWithoutDetaching([$branch->id => ['can_view' => true, 'can_edit' => true]]);

        return $user;
    }

    public function test_import_copas_button_hidden_for_read_only_user(): void
    {
        [$branch] = $this->setupBranchWithTemplate();
        $role = Role::query()->create([
            'name' => 'Database Viewer',
            'slug' => 'db_viewer_'.uniqid(),
            'is_superadmin' => false,
            'is_active' => true,
        ]);
        $role->permissions()->sync(Permission::query()->whereIn('slug', ['database.view', 'database.view_branch'])->pluck('id'));
        $user = User::factory()->create([
            'role_id' => $role->id,
            'branch_id' => $branch->id,
            'password_changed_at' => now(),
        ]);
        $user->branches()->syncWithoutDetaching([$branch->id => ['can_view' => true]]);

        $google = Mockery::mock(GoogleSheetsApiService::class);
        $google->shouldReceive('sheetTitles')->once()->with($branch->sheet_id)->andReturn(['data_konsumen']);
        $this->app->instance(GoogleSheetsApiService::class, $google);

        $response = $this->actingAs($user)->get(route('database.index', ['branch_id' => $branch->id]))
            ->assertOk()
            ->assertViewHas('canEdit', false);

        $this->assertStringNotContainsString('Import Copas', $response->getContent());
    }

    public function test_import_preview_rejects_unknown_header(): void
    {
        [$branch] = $this->setupBranchWithTemplate();
        $user = $this->editor($branch);
        $this->fakeGoogleSheets();

        $response = $this->actingAs($user)->postJson(route('database.import.preview'), [
            'sheet_name' => 'data_konsumen',
            'branch_id' => $branch->id,
            'raw' => "id_kavling\tnama_konsumen\tabc\nA-02\tBudi\tx",
        ])->assertOk();

        $response->assertJsonPath('error', 'Kolom tidak dikenal: abc');
    }

    public function test_import_preview_rejects_formula_and_metadata_columns_as_not_writable(): void
    {
        [$branch] = $this->setupBranchWithTemplate(
            ['id_kavling', 'nama_konsumen', 'total'],
            [],
            ['total'],
        );
        $user = $this->editor($branch);
        $this->fakeGoogleSheets();

        $response = $this->actingAs($user)->postJson(route('database.import.preview'), [
            'sheet_name' => 'data_konsumen',
            'branch_id' => $branch->id,
            'raw' => "id_kavling\tnama_konsumen\nA-02\tBudi",
        ])->assertOk();

        $this->assertArrayNotHasKey('error', $response->json());
        $this->assertSame(['id_kavling', 'nama_konsumen'], $response->json('headers'));
    }

    public function test_import_preview_marks_valid_and_invalid_rows(): void
    {
        [$branch] = $this->setupBranchWithTemplate([
            'id_kavling',
            'nama_konsumen',
            'status',
        ], [
            'status' => ['type' => 'select', 'options' => ['Aktif', 'Tidak Aktif'], 'strict' => true],
        ]);
        $user = $this->editor($branch);
        $this->fakeGoogleSheets();

        $response = $this->actingAs($user)->postJson(route('database.import.preview'), [
            'sheet_name' => 'data_konsumen',
            'branch_id' => $branch->id,
            'raw' => "id_kavling\tnama_konsumen\tstatus\nA-02\tBudi\tAktif\nA-03\tCaca\tSalah",
        ])->assertOk();

        $json = $response->json();
        $this->assertSame(1, $json['valid_count']);
        $this->assertSame(1, $json['invalid_count']);
        $this->assertSame('VALID', $json['rows'][0]['status']);
        $this->assertSame('ERROR', $json['rows'][1]['status']);
    }

    public function test_import_preview_rejects_over_200_rows(): void
    {
        [$branch] = $this->setupBranchWithTemplate();
        $user = $this->editor($branch);
        $this->fakeGoogleSheets();

        $lines = ["id_kavling\tnama_konsumen"];
        for ($i = 0; $i < 201; $i++) {
            $lines[] = "A-{$i}\tNama {$i}";
        }

        $response = $this->actingAs($user)->postJson(route('database.import.preview'), [
            'sheet_name' => 'data_konsumen',
            'branch_id' => $branch->id,
            'raw' => implode("\n", $lines),
        ])->assertOk();

        $response->assertJsonPath('error', 'Maksimal 200 baris per import.');
    }

    public function test_import_save_writes_only_valid_rows_via_write_service(): void
    {
        [$branch] = $this->setupBranchWithTemplate([
            'id_kavling',
            'nama_konsumen',
            'status',
        ], [
            'status' => ['type' => 'select', 'options' => ['Aktif', 'Tidak Aktif'], 'strict' => true],
        ]);
        $user = $this->editor($branch);

        $raw = "id_kavling\tnama_konsumen\tstatus\nA-02\tBudi\tAktif\nA-03\tCaca\tSalah";

        $writeService = Mockery::mock(DatabaseSheetWriteService::class);
        $writeService->shouldReceive('editableHeaders')->andReturnUsing(fn ($headers, $formulas) => $headers);
        $writeService->shouldReceive('createRecord')->once()
            ->with(Mockery::on(fn ($b) => $b->id === $branch->id), 'data_konsumen', [
                'id_kavling' => 'A-02',
                'nama_konsumen' => 'Budi',
                'status' => 'Aktif',
            ])->andReturn(true);
        $this->app->instance(DatabaseSheetWriteService::class, $writeService);

        $response = $this->actingAs($user)->postJson(route('database.import.save'), [
            'sheet_name' => 'data_konsumen',
            'branch_id' => $branch->id,
            'raw' => $raw,
        ])->assertOk();

        $json = $response->json();
        $this->assertSame(1, $json['saved']);
        $this->assertSame(1, $json['failed']);
    }

    public function test_import_save_writes_nothing_when_all_rows_invalid(): void
    {
        [$branch] = $this->setupBranchWithTemplate([
            'id_kavling',
            'nama_konsumen',
            'status',
        ], [
            'status' => ['type' => 'select', 'options' => ['Aktif', 'Tidak Aktif'], 'strict' => true],
        ]);
        $user = $this->editor($branch);

        $raw = "id_kavling\tnama_konsumen\tstatus\nA-02\tBudi\tSalah\nA-03\tCaca\tJuga Salah";

        $writeService = Mockery::mock(DatabaseSheetWriteService::class);
        $writeService->shouldReceive('editableHeaders')->andReturnUsing(fn ($headers, $formulas) => $headers);
        $writeService->shouldNotReceive('createRecord');
        $this->app->instance(DatabaseSheetWriteService::class, $writeService);

        $response = $this->actingAs($user)->postJson(route('database.import.save'), [
            'sheet_name' => 'data_konsumen',
            'branch_id' => $branch->id,
            'raw' => $raw,
        ])->assertOk();

        $json = $response->json();
        $this->assertSame(0, $json['saved']);
        $this->assertSame(2, $json['failed']);
    }

    public function test_import_enforces_branch_scope(): void
    {
        [$authorized] = $this->setupBranchWithTemplate();
        $unauthorized = Branch::query()->create([
            'name' => 'Solo',
            'code' => 'SLO',
            'is_active' => true,
            'sheet_id' => 'spreadsheet-solo',
        ]);
        $user = $this->editor($authorized);
        $this->fakeGoogleSheets();

        $this->actingAs($user)->postJson(route('database.import.preview'), [
            'sheet_name' => 'data_konsumen',
            'branch_id' => $unauthorized->id,
            'raw' => "id_kavling\tnama_konsumen\nA-02\tBudi",
        ])->assertForbidden();

        $this->actingAs($user)->postJson(route('database.import.save'), [
            'sheet_name' => 'data_konsumen',
            'branch_id' => $unauthorized->id,
            'raw' => "id_kavling\tnama_konsumen\nA-02\tBudi",
        ])->assertForbidden();
    }

    public function test_system_identity_fields_are_not_editable_per_sheet(): void
    {
        $this->fakeGoogleSheets();
        $service = app(DatabaseSheetWriteService::class);
        $this->assertNotContains('nama_konsumen', $service->editableHeaders(['id_kavling', 'nama_konsumen', 'no_ktp', 'tanggal_slik'], [], 'bi_checking'));
        $this->assertNotContains('no_ktp', $service->editableHeaders(['id_kavling', 'nama_konsumen', 'no_ktp', 'tanggal_slik'], [], 'bi_checking'));
        $this->assertNotContains('id_psjb', $service->editableHeaders(['id_kavling', 'id_psjb', 'tanggal_terima_bank'], [], 'pemberkasan'));
        $this->assertNotContains('id_berkas', $service->editableHeaders(['id_kavling', 'id_berkas', 'no_sp3k'], [], 'proses_bank'));
        $this->assertContains('tanggal_slik', $service->editableHeaders(['id_kavling', 'nama_konsumen', 'no_ktp', 'tanggal_slik'], [], 'bi_checking'));
    }

    public function test_import_does_not_update_local_cache_when_remote_write_fails(): void
    {
        [$branch] = $this->setupBranchWithTemplate();
        $user = $this->editor($branch);

        $raw = "id_kavling\tnama_konsumen\nA-02\tBudi";

        $writeService = Mockery::mock(DatabaseSheetWriteService::class);
        $writeService->shouldReceive('editableHeaders')->andReturnUsing(fn ($headers, $formulas) => $headers);
        $writeService->shouldReceive('createRecord')->once()
            ->with(Mockery::on(fn ($b) => $b->id === $branch->id), 'data_konsumen', [
                'id_kavling' => 'A-02',
                'nama_konsumen' => 'Budi',
            ])->andReturn(false);
        $this->app->instance(DatabaseSheetWriteService::class, $writeService);

        $response = $this->actingAs($user)->postJson(route('database.import.save'), [
            'sheet_name' => 'data_konsumen',
            'branch_id' => $branch->id,
            'raw' => $raw,
        ])->assertOk();

        $json = $response->json();
        $this->assertSame(0, $json['saved']);
        $this->assertSame(1, $json['failed']);
        $this->assertDatabaseCount('database_sheet_records', 1);
    }
}
