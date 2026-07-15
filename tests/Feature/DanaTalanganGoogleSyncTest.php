<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DanaTalangan;
use App\Models\Kavling;
use App\Models\LeadMaster;
use App\Models\User;
use App\Services\DanaTalanganGoogleService;
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
            'services.google_sheets.dana_talangan_template_sheet' => 'Juli',
        ]);
    }

    public function test_month_sheet_names_use_july_template_and_year_after_2026(): void
    {
        $service = new DanaTalanganGoogleService(Mockery::mock(GoogleSheetsApiService::class));

        $this->assertNull($service->sheetNameForDate('2026-06-30'));
        $this->assertSame('Juli', $service->sheetNameForDate('2026-07-01'));
        $this->assertSame('Desember', $service->sheetNameForDate('2026-12-01'));
        $this->assertSame('Januari 2027', $service->sheetNameForDate('2027-01-01'));
        $this->assertSame(['2027-02-01', '2027-02-28'], $service->dateRangeForSheet('Februari 2027'));
    }

    public function test_dry_run_reports_unsynced_local_july_record_without_writing(): void
    {
        [$branch, $user] = $this->makeBranchAndUser();
        $record = $this->makeRecord($branch, $user);

        $google = Mockery::mock(GoogleSheetsApiService::class);
        $google->shouldReceive('sheetIds')->once()->andReturn(['Juli' => 123]);
        $google->shouldReceive('quoteSheetName')->once()->with('Juli')->andReturn("'Juli'");
        $google->shouldReceive('batchGetRaw')->once()->andReturn([
            'Juli' => [DanaTalanganGoogleService::VISIBLE_HEADERS],
        ]);

        $result = (new DanaTalanganGoogleService($google))->sync($user->id, true);

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['summary']['pushed']);
        $this->assertNull($record->fresh()->oasis_sync_id);
    }

    public function test_push_writes_record_to_first_available_july_row(): void
    {
        [$branch, $user] = $this->makeBranchAndUser();
        $record = $this->makeRecord($branch, $user);
        $headers = array_merge(DanaTalanganGoogleService::VISIBLE_HEADERS, DanaTalanganGoogleService::META_HEADERS);

        $google = Mockery::mock(GoogleSheetsApiService::class);
        $google->shouldReceive('sheetIds')->once()->andReturn(['Juli' => 123]);
        $google->shouldReceive('quoteSheetName')->times(3)->with('Juli')->andReturn("'Juli'");
        $google->shouldReceive('hideColumns')->once()->with('spreadsheet-id', 123, 14, 17);
        $google->shouldReceive('updateRange')->once()->with(
            'spreadsheet-id',
            "'Juli'!O1:Q1",
            [DanaTalanganGoogleService::META_HEADERS]
        );
        $google->shouldReceive('batchGetRaw')->once()->andReturn([
            'Juli' => [$headers, [1, '', '', '', '', '', '', '', '', '', '', '', false, '', '', '', '']],
        ]);
        $google->shouldReceive('updateRange')->once()->withArgs(function ($spreadsheetId, $range, $values) {
            return $spreadsheetId === 'spreadsheet-id'
                && $range === "'Juli'!A2:Q2"
                && $values[0][2] === 'Konsumen Test'
                && $values[0][14] !== '';
        });

        $this->assertTrue((new DanaTalanganGoogleService($google))->push($record, $user->id));
        $record->refresh();
        $this->assertSame('Juli', $record->sheet_name);
        $this->assertSame(2, $record->sheet_row_number);
        $this->assertSame('synced', $record->sync_status);
        $this->assertNotNull($record->oasis_sync_id);
    }

    public function test_branch_user_can_open_month_tab_with_add_and_edit_modals(): void
    {
        [$branch, $user] = $this->makeBranchAndUser();
        $project = LeadMaster::create([
            'branch_id' => $branch->id,
            'project_name' => 'Proyek Test',
            'is_active' => true,
        ]);
        Kavling::create(['project_id' => $project->id, 'kavling_code' => 'A-01', 'name' => 'A-01']);
        $this->makeRecord($branch, $user);

        $response = $this->actingAs($user)->get(route('dana-talangan.index', ['month' => 'Juli']));

        $response
            ->assertOk()
            ->assertSee('Juli')
            ->assertSee('Sync Sekarang')
            ->assertSee('Tambah Dana Talangan')
            ->assertSee('Konsumen Test');
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
            'month' => 'Juli',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('dana_talangans', [
            'nama_konsumen' => 'Konsumen Baru',
            'branch_id' => $branch->id,
        ]);
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
