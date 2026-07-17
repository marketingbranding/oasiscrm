<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DanaTalangan;
use App\Models\DatabaseSheetRecord;
use App\Models\Kavling;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\User;
use App\Services\DanaTalanganGoogleService;
use App\Services\DanaTalanganOptionService;
use App\Services\GoogleSheetsApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class DanaTalanganGoogleSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.google_sheets.dana_talangan_spreadsheet_id' => 'spreadsheet-id',
            'services.google_sheets.dana_talangan_sheet_name' => 'Talangan',
            'services.google_sheets.dana_talangan_project_branches' => [],
        ]);
    }

    public function test_service_uses_single_talangan_sheet(): void
    {
        $service = new DanaTalanganGoogleService(Mockery::mock(GoogleSheetsApiService::class));

        $this->assertSame('Talangan', $service->sheetName());
    }

    public function test_dry_run_reports_unsynced_local_record_without_writing(): void
    {
        [$branch, $user] = $this->makeBranchAndUser();
        $record = $this->makeRecord($branch, $user);

        $google = Mockery::mock(GoogleSheetsApiService::class);
        $google->shouldReceive('sheetIds')->once()->andReturn(['Talangan' => 123]);
        $google->shouldReceive('quoteSheetName')->once()->with('Talangan')->andReturn("'Talangan'");
        $google->shouldReceive('batchGetRaw')->once()->andReturn([
            'Talangan' => [DanaTalanganGoogleService::VISIBLE_HEADERS],
        ]);

        $result = (new DanaTalanganGoogleService($google))->sync($user->id, true);

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['summary']['pushed']);
        $this->assertNull($record->fresh()->oasis_sync_id);
    }

    public function test_push_writes_record_to_first_available_talangan_row(): void
    {
        [$branch, $user] = $this->makeBranchAndUser();
        $record = $this->makeRecord($branch, $user);
        $headers = array_merge(DanaTalanganGoogleService::VISIBLE_HEADERS, DanaTalanganGoogleService::META_HEADERS);

        $google = Mockery::mock(GoogleSheetsApiService::class);
        $google->shouldReceive('sheetIds')->once()->andReturn(['Talangan' => 123]);
        $google->shouldReceive('quoteSheetName')->times(3)->with('Talangan')->andReturn("'Talangan'");
        $google->shouldReceive('hideColumns')->once()->with('spreadsheet-id', 123, 14, 17);
        $google->shouldReceive('updateRange')->once()->with(
            'spreadsheet-id',
            "'Talangan'!O1:Q1",
            [DanaTalanganGoogleService::META_HEADERS]
        );
        $google->shouldReceive('batchGetRaw')->once()->andReturn([
            'Talangan' => [$headers, [1, '', '', '', '', '', '', '', '', '', '', '', false, '', '', '', '']],
        ]);
        $google->shouldReceive('updateRange')->once()->withArgs(function ($spreadsheetId, $range, $values) {
            return $spreadsheetId === 'spreadsheet-id'
                && $range === "'Talangan'!A2:Q2"
                && $values[0][2] === 'Konsumen Test'
                && $values[0][14] !== '';
        });

        $this->assertTrue((new DanaTalanganGoogleService($google))->push($record, $user->id));
        $record->refresh();
        $this->assertSame('Talangan', $record->sheet_name);
        $this->assertSame(2, $record->sheet_row_number);
        $this->assertSame('synced', $record->sync_status);
        $this->assertNotNull($record->oasis_sync_id);
    }

    public function test_branch_user_can_open_tracking_filters_and_modals(): void
    {
        [$branch, $user] = $this->makeBranchAndUser();
        $project = LeadMaster::create([
            'branch_id' => $branch->id,
            'project_name' => 'Proyek Test',
            'is_active' => true,
        ]);
        Kavling::create(['project_id' => $project->id, 'kavling_code' => 'A-01', 'name' => 'A-01']);
        $this->makeRecord($branch, $user);

        $response = $this->actingAs($user)->get(route('dana-talangan.index'));

        $response
            ->assertOk()
            ->assertSee('Rentang Tanggal')
            ->assertSee('Cari Nama Konsumen')
            ->assertSee('Filter Dana Talangan')
            ->assertSee('Terapkan Filter')
            ->assertSee('month-wrapper', false)
            ->assertSee('month-display', false)
            ->assertSee('kavlingOptionsUrl', false)
            ->assertSee('changeAddBranch()', false)
            ->assertSee('changeAddProject()', false)
            ->assertSee('Sync Sekarang')
            ->assertSee('Tambah Dana Talangan')
            ->assertSee('crm-table-scroll', false)
            ->assertSee('crm-data-table', false)
            ->assertSee('crm-boolean-box', false)
            ->assertSee('Tanggal ▲')
            ->assertDontSee('aria-label="Sort Tanggal"', false)
            ->assertSee('Konsumen Test');
        $this->assertMatchesRegularExpression('/<button[^>]+@click="openEdit\(.+?\)"[^>]*>Edit<\/button>/s', $response->getContent());
        $this->assertStringContainsString('color:#0000ee', $response->getContent());
        $this->assertStringContainsString('color:#c0392b', $response->getContent());
    }

    public function test_branch_user_store_pushes_new_record_to_google_service(): void
    {
        [$branch, $user] = $this->makeBranchAndUser();
        LeadMaster::create([
            'branch_id' => $branch->id,
            'project_name' => 'Proyek Test',
            'is_active' => true,
        ]);
        $googleService = Mockery::mock(DanaTalanganGoogleService::class);
        $googleService->shouldReceive('branchIdForProject')->once()->with('Proyek Test')->andReturn($branch->id);
        $googleService->shouldReceive('push')->once()->withArgs(fn ($record, $actorId) => $record instanceof DanaTalangan && $record->nama_konsumen === 'Konsumen Baru' && $actorId === $user->id
        )->andReturnTrue();
        $this->app->instance(DanaTalanganGoogleService::class, $googleService);

        $response = $this->actingAs($user)->post(route('dana-talangan.store'), [
            'tanggal' => '2026-07-15',
            'nama_konsumen' => 'Konsumen Baru',
            'project_name' => 'Proyek Test',
            'status' => 'sanggup',
            'pinjam_nama' => '0',
            'konfirmasi_keuangan' => '0',
        ]);

        $response->assertRedirect(route('dana-talangan.index'));
        $this->assertDatabaseHas('dana_talangans', [
            'nama_konsumen' => 'Konsumen Baru',
            'branch_id' => $branch->id,
        ]);
    }

    public function test_store_rejects_kavling_outside_selected_project(): void
    {
        [$branch, $user] = $this->makeBranchAndUser();
        LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Proyek Test', 'is_active' => true]);
        $googleService = Mockery::mock(DanaTalanganGoogleService::class);
        $googleService->shouldReceive('branchIdForProject')->once()->with('Proyek Test')->andReturn($branch->id);
        $googleService->shouldNotReceive('push');
        $optionService = Mockery::mock(DanaTalanganOptionService::class);
        $optionService->shouldReceive('isValidKavling')->once()->andReturnFalse();
        $this->app->instance(DanaTalanganGoogleService::class, $googleService);
        $this->app->instance(DanaTalanganOptionService::class, $optionService);

        $response = $this->actingAs($user)->post(route('dana-talangan.store'), [
            'tanggal' => '2026-07-15',
            'nama_konsumen' => 'Konsumen Invalid',
            'project_name' => 'Proyek Test',
            'kav' => 'ZZ99',
            'status' => 'sanggup',
        ]);

        $response->assertSessionHasErrors('kav');
        $this->assertDatabaseMissing('dana_talangans', ['nama_konsumen' => 'Konsumen Invalid']);
    }

    public function test_branch_user_can_update_and_delete_from_action_column(): void
    {
        [$branch, $user] = $this->makeBranchAndUser();
        LeadMaster::create([
            'branch_id' => $branch->id,
            'project_name' => 'Proyek Test',
            'is_active' => true,
        ]);
        $record = $this->makeRecord($branch, $user);
        $googleService = Mockery::mock(DanaTalanganGoogleService::class);
        $googleService->shouldReceive('branchIdForProject')->once()->with('Proyek Test')->andReturn($branch->id);
        $googleService->shouldReceive('push')->once()->andReturnTrue();
        $googleService->shouldReceive('delete')->once()->withArgs(fn ($deletedRecord, $actorId) => $deletedRecord->is($record) && $actorId === $user->id
        )->andReturnTrue();
        $this->app->instance(DanaTalanganGoogleService::class, $googleService);

        $this->actingAs($user)->put(route('dana-talangan.update', $record), [
            'tanggal' => '2026-07-15',
            'nama_konsumen' => 'Konsumen Diperbarui',
            'project_name' => 'Proyek Test',
            'status' => 'sanggup',
            'pinjam_nama' => '0',
            'konfirmasi_keuangan' => '0',
        ])->assertRedirect(route('dana-talangan.index'));

        $this->assertDatabaseHas('dana_talangans', ['id' => $record->id, 'nama_konsumen' => 'Konsumen Diperbarui']);
        $this->actingAs($user)->delete(route('dana-talangan.destroy', $record))->assertRedirect();
    }

    public function test_search_counts_same_name_case_insensitively_within_date_range(): void
    {
        [$branch, $user] = $this->makeBranchAndUser();
        $first = $this->makeRecord($branch, $user);
        $first->update(['nama_konsumen' => 'Konsumen Test', 'tanggal' => '2026-01-10']);
        $second = $this->makeRecord($branch, $user);
        $second->update(['nama_konsumen' => 'konsumen   test', 'tanggal' => '2026-03-10']);

        $response = $this->actingAs($user)->get(route('dana-talangan.index', [
            'search' => 'KONSUMEN',
            'filter_mode' => 'date',
            'date_from' => '2026-03-01',
            'date_to' => '2026-03-31',
        ]));

        $response
            ->assertOk()
            ->assertSee('2 kali')
            ->assertSee('1 dalam rentang aktif')
            ->assertSee('Filter aktif:')
            ->assertSee('Tanggal: 2026-03-01 - 2026-03-31');
    }

    public function test_month_range_filters_by_submission_date(): void
    {
        [$branch, $user] = $this->makeBranchAndUser();
        $january = $this->makeRecord($branch, $user);
        $january->update(['nama_konsumen' => 'Pengajuan Januari', 'tanggal' => '2026-01-10']);
        $march = $this->makeRecord($branch, $user);
        $march->update(['nama_konsumen' => 'Pengajuan Maret', 'tanggal' => '2026-03-10']);

        $response = $this->actingAs($user)->get(route('dana-talangan.index', [
            'filter_mode' => 'month',
            'month_from' => '2026-03',
            'month_to' => '2026-03',
        ]));

        $response->assertOk();
        $names = collect($response->viewData('records')->items())->pluck('nama_konsumen')->all();
        $this->assertSame(['Pengajuan Maret'], $names);
    }

    public function test_dry_run_repairs_stale_metadata_and_infers_project_from_history(): void
    {
        [$branch, $user] = $this->makeBranchAndUser();
        LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Mlonggo 1', 'is_active' => true]);
        $mainRow = [1, '30/06/2026', 'SISKA AULIA FIRNANDA', '', '', 'TIDAK', '', '', '', '', '10/07/2026', '', '', 'SANGGUP', 'old-id', 'deleted', '1'];
        $historyRow = [1, '30/06/2026', 'SISKA AULIA FIRNANDA', 'Z-07', 'Mlonggo'];

        $google = Mockery::mock(GoogleSheetsApiService::class);
        $google->shouldReceive('sheetIds')->once()->andReturn(['Juni' => 456, 'Talangan' => 123]);
        $google->shouldReceive('quoteSheetName')->twice()->andReturnUsing(fn ($name) => "'{$name}'");
        $google->shouldReceive('batchGetRaw')->once()->andReturn([
            'Talangan' => [DanaTalanganGoogleService::VISIBLE_HEADERS, $mainRow],
            'Juni' => [['No', 'Tanggal', 'Nama Konsumen', 'Kav', 'Proyek'], $historyRow],
        ]);

        $result = (new DanaTalanganGoogleService($google))->sync($user->id, true);

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['summary']['imported']);
        $this->assertSame(1, $result['summary']['inferred_projects']);
        $this->assertSame(1, $result['summary']['repaired_metadata']);
        $this->assertSame([], $result['summary']['warnings']);
    }

    public function test_kavling_options_use_cached_data_kav_for_oasis_project(): void
    {
        [$branch, $user] = $this->makeBranchAndUser();
        DatabaseSheetRecord::create([
            'branch_id' => $branch->id,
            'sheet_id' => 'sheet-id',
            'sheet_name' => 'data_kav',
            'row_number' => 2,
            'headers' => ['proyek', 'kode_kavling'],
            'row_data' => ['proyek' => 'Marison Regency Kuwasen', 'kode_kavling' => 'D05'],
            'formula_columns' => [],
        ]);
        DatabaseSheetRecord::create([
            'branch_id' => $branch->id,
            'sheet_id' => 'sheet-id',
            'sheet_name' => 'data_kav',
            'row_number' => 3,
            'headers' => ['proyek', 'kode_kavling'],
            'row_data' => ['proyek' => 'Marison Regency Kuwasen', 'kode_kavling' => 'D12'],
            'formula_columns' => [],
        ]);

        $service = new DanaTalanganOptionService(Mockery::mock(GoogleSheetsApiService::class));

        $this->assertSame(['D05', 'D12'], $service->kavlings($branch, 'Kuwasen'));
        $this->assertTrue($service->isValidKavling($branch, 'Kuwasen', 'D-12'));
    }

    public function test_admin_cannot_request_kavlings_from_another_branch_but_pusat_can(): void
    {
        [$branch, $admin] = $this->makeBranchAndUser();
        $otherBranch = Branch::create(['name' => 'Cabang Lain', 'code' => 'OTHER', 'is_active' => true]);
        $googleService = Mockery::mock(DanaTalanganGoogleService::class);
        $googleService->shouldReceive('branchIdForProject')->once()->with('Proyek Lain')->andReturn($otherBranch->id);
        $optionService = Mockery::mock(DanaTalanganOptionService::class);
        $optionService->shouldReceive('kavlings')->once()->withArgs(fn ($requestedBranch, $project) => $requestedBranch->is($otherBranch) && $project === 'Proyek Lain')->andReturn(['A01']);
        $this->app->instance(DanaTalanganGoogleService::class, $googleService);
        $this->app->instance(DanaTalanganOptionService::class, $optionService);

        $this->actingAs($admin)->getJson(route('dana-talangan.kavling-options', [
            'branch_id' => $otherBranch->id,
            'project_name' => 'Proyek Lain',
        ]))->assertForbidden();

        $pusatRole = Role::create(['name' => 'Pusat', 'slug' => 'pusat', 'is_superadmin' => false]);
        $pusat = User::factory()->create(['role_id' => $pusatRole->id, 'password_changed_at' => now()]);
        $this->actingAs($pusat)->getJson(route('dana-talangan.kavling-options', [
            'branch_id' => $otherBranch->id,
            'project_name' => 'Proyek Lain',
        ]))->assertOk()->assertJsonPath('options.0', 'A01');
    }

    private function makeBranchAndUser(): array
    {
        $branch = Branch::create(['name' => 'Cabang Test', 'code' => 'TEST', 'is_active' => true]);
        $user = User::factory()->create(['branch_id' => $branch->id, 'password_changed_at' => now()]);

        return [$branch, $user];
    }

    private function makeRecord(Branch $branch, User $user): DanaTalangan
    {
        return DanaTalangan::create([
            'tanggal' => '2026-07-07',
            'nama_konsumen' => 'Konsumen Test',
            'project_name' => 'Proyek Test',
            'pinjam_nama' => false,
            'konfirmasi_keuangan' => false,
            'branch_id' => $branch->id,
            'status' => 'sanggup',
            'created_by' => $user->id,
        ]);
    }
}
