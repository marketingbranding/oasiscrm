<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DatabaseSheetRecord;
use App\Models\User;
use App\Services\DatabaseSheetSyncService;
use App\Services\DatabaseSheetWriteService;
use App\Services\GoogleSheetsApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class GoogleWriteHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_batch_update_preserves_previous_local_business_values(): void
    {
        [$branch, $record] = $this->record();
        $google = Mockery::mock(GoogleSheetsApiService::class);
        $google->shouldReceive('quoteSheetName')->once()->with('Leads')->andReturn("'Leads'");
        $google->shouldReceive('batchUpdateRanges')->once()->andThrow(new RuntimeException('Remote unavailable'));
        $sync = Mockery::mock(DatabaseSheetSyncService::class);
        $sync->shouldReceive('columnLetter')->once()->with(1)->andReturn('A');

        $result = (new DatabaseSheetWriteService($google, $sync))->updateRecord($record, ['status' => 'Tidak Aktif']);

        $this->assertFalse($result);
        $this->assertSame('Aktif', $record->fresh()->row_data['status']);
        $this->assertSame('failed', $record->fresh()->sync_status);
    }

    public function test_delete_writes_remote_tombstone_before_local_deletion_state(): void
    {
        [, $record] = $this->record();
        $user = User::factory()->create();
        $google = Mockery::mock(GoogleSheetsApiService::class);
        $google->shouldReceive('quoteSheetName')->twice()->with('Leads')->andReturn("'Leads'");
        $google->shouldReceive('batchUpdateRanges')->once()->withArgs(function ($sheetId, $ranges) {
            return $sheetId === 'sheet-id'
                && $ranges[0]['range'] === "'Leads'!B2"
                && $ranges[1]['range'] === "'Leads'!C2";
        });
        $sync = Mockery::mock(DatabaseSheetSyncService::class);
        $sync->shouldReceive('columnLetter')->once()->with(2)->andReturn('B');
        $sync->shouldReceive('columnLetter')->once()->with(3)->andReturn('C');

        $result = (new DatabaseSheetWriteService($google, $sync))->softDelete($record, $user->id);

        $this->assertTrue($result);
        $this->assertNotNull($record->fresh()->oasis_deleted_at);
        $this->assertSame($user->id, $record->fresh()->oasis_deleted_by);
    }

    private function record(): array
    {
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SLO', 'sheet_id' => 'sheet-id', 'is_active' => true]);
        $record = DatabaseSheetRecord::create([
            'branch_id' => $branch->id,
            'sheet_id' => 'sheet-id',
            'sheet_name' => 'Leads',
            'row_number' => 2,
            'headers' => ['status', 'oasis_deleted_at', 'oasis_deleted_by'],
            'row_data' => ['status' => 'Aktif', 'oasis_deleted_at' => '', 'oasis_deleted_by' => ''],
            'formula_columns' => [],
            'column_metadata' => [],
            'sync_status' => 'synced',
        ]);

        return [$branch, $record];
    }
}
