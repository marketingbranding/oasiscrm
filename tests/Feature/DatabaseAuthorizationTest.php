<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DatabaseSheetRecord;
use App\Models\Role;
use App\Models\User;
use App\Services\DatabaseSheetWriteService;
use App\Services\GoogleSheetsApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class DatabaseAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_hidden_sheets_are_rejected_at_every_direct_database_boundary(): void
    {
        $branch = Branch::query()->create(['name' => 'Magelang', 'code' => 'MGL', 'is_active' => true, 'sheet_id' => 'sheet-mgl']);
        $user = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'pusat')->firstOrFail()->id,
            'branch_id' => $branch->id,
            'password_changed_at' => now(),
        ]);
        $record = DatabaseSheetRecord::query()->create([
            'branch_id' => $branch->id,
            'sheet_id' => $branch->sheet_id,
            'sheet_name' => 'Leads',
            'row_number' => 2,
            'oasis_sync_id' => 'hidden-sync-id',
            'headers' => ['nama_konsumen'],
            'row_data' => ['nama_konsumen' => 'Hidden'],
            'formula_columns' => [],
            'column_metadata' => [],
        ]);
        $this->actingAs($user)->getJson(route('database.sheet', ['branchId' => $branch->id, 'sheetName' => 'Leads']))->assertNotFound();
        $this->actingAs($user)->putJson(route('database.records.update', 999999), [
            'expected_sync_id' => 'hidden-sync-id',
            'expected_updated_at' => $record->updated_at->utc()->format('Y-m-d H:i:s'),
        ])->assertNotFound();
        $this->actingAs($user)->putJson(route('database.records.update', $record), ['expected_updated_at' => $record->updated_at->utc()->format('Y-m-d H:i:s')])->assertNotFound();
        $this->actingAs($user)->delete(route('database.records.destroy', $record))->assertNotFound();
        $this->actingAs($user)->postJson(route('database.records.store'), ['branch_id' => $branch->id, 'sheet_name' => 'Leads'])->assertUnprocessable();
    }

    public function test_explicit_unauthorized_branch_is_denied_across_database_endpoints(): void
    {
        $authorized = Branch::query()->create(['name' => 'Magelang', 'code' => 'MGL', 'is_active' => true, 'sheet_id' => 'sheet-mgl']);
        $unauthorized = Branch::query()->create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true, 'sheet_id' => 'sheet-slo']);
        $user = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'admin')->firstOrFail()->id,
            'branch_id' => $authorized->id,
            'password_changed_at' => now(),
        ]);
        $record = DatabaseSheetRecord::query()->create([
            'branch_id' => $unauthorized->id,
            'sheet_id' => $unauthorized->sheet_id,
            'sheet_name' => 'Leads',
            'row_number' => 2,
            'headers' => ['nama_konsumen'],
            'row_data' => ['nama_konsumen' => 'Tidak boleh terlihat'],
            'formula_columns' => [],
            'column_metadata' => [],
        ]);

        $google = Mockery::mock(GoogleSheetsApiService::class);
        $google->shouldNotReceive('sheetTitles');
        $this->app->instance(GoogleSheetsApiService::class, $google);
        $writer = Mockery::mock(DatabaseSheetWriteService::class);
        $writer->shouldNotReceive('createRecord');
        $writer->shouldNotReceive('updateRecord');
        $writer->shouldNotReceive('softDelete');
        $this->app->instance(DatabaseSheetWriteService::class, $writer);

        $this->actingAs($user)->get(route('database.index', ['branch_id' => $unauthorized->id]))->assertForbidden();
        $this->actingAs($user)->getJson(route('database.sheet', ['branchId' => $unauthorized->id, 'sheetName' => 'Leads']))->assertForbidden();
        $this->actingAs($user)->postJson(route('database.sync'), ['branch_id' => $unauthorized->id])->assertForbidden();
        $this->actingAs($user)->getJson(route('database.sync-status', ['branch_id' => $unauthorized->id]))->assertForbidden();
        $this->actingAs($user)->post(route('database.records.store'), ['branch_id' => $unauthorized->id, 'sheet_name' => 'data_konsumen'])->assertForbidden();
        $this->actingAs($user)->putJson(route('database.records.update', $record), ['expected_updated_at' => $record->updated_at->utc()->format('Y-m-d H:i:s')])->assertNotFound();
        $this->actingAs($user)->delete(route('database.records.destroy', $record))->assertNotFound();
    }
}
