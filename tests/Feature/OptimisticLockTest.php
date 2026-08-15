<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\ContentItem;
use App\Models\DanaTalangan;
use App\Models\DatabaseSheetRecord;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\User;
use App\Services\DanaTalanganGoogleService;
use App\Services\DatabaseSheetWriteService;
use App\Services\OptimisticLockService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class OptimisticLockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeGoogleSheets();
    }

    public function test_work_planner_status_update_succeeds_with_matching_token_and_conflicts_when_stale(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $item = ContentItem::create([
            'branch_id' => $branch->id, 'item_type' => 'task', 'visibility' => 'team', 'title' => 'Lock Task',
            'deadline_date' => today(), 'scheduled_date' => today(), 'priority' => 'medium', 'status' => 'todo', 'created_by' => $user->id,
        ]);
        $token = app(OptimisticLockService::class)->token($item);
        $this->travel(1)->seconds();
        $success = $this->actingAs($user)->patchJson(route('content-calendar.update-status', $item), [
            'status' => 'completed', 'expected_updated_at' => $token,
        ])->assertOk()->assertJsonPath('status', 'completed');
        $this->assertNotSame($token, $success->json('updated_at'));

        $activityCount = ActivityLog::where('subject_type', ContentItem::class)->where('subject_id', $item->id)->count();
        $this->actingAs($user)->patchJson(route('content-calendar.update-status', $item), [
            'status' => 'todo', 'expected_updated_at' => $token,
        ])->assertConflict()
            ->assertJsonPath('code', 'record_modified')
            ->assertJsonPath('record_type', 'content_item')
            ->assertJsonPath('record_id', $item->id)
            ->assertJsonPath('expected_updated_at', $token)
            ->assertJsonPath('modified_by.user_id', $user->id)
            ->assertJsonPath('modified_by.display_name', $user->name)
            ->assertJsonStructure(['current_updated_at', 'current_updated_label', 'reload_url']);
        $this->assertSame('completed', $item->fresh()->status);
        $this->assertSame($user->id, $item->fresh()->updated_by);
        $this->assertSame($activityCount, ActivityLog::where('subject_type', ContentItem::class)->where('subject_id', $item->id)->count());
    }

    public function test_dana_talangan_conflict_does_not_overwrite_or_push(): void
    {
        [$branch, $user] = $this->branchAndUser();
        LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Proyek Lock', 'is_active' => true]);
        $record = DanaTalangan::create([
            'branch_id' => $branch->id, 'created_by' => $user->id, 'tanggal' => today(),
            'nama_konsumen' => 'Versi Baru', 'project_name' => 'Proyek Lock', 'status' => 'sanggup',
        ]);
        $stale = Carbon::parse($record->updated_at)->subSecond()->utc()->format('Y-m-d H:i:s');
        $google = Mockery::mock(DanaTalanganGoogleService::class);
        $google->shouldNotReceive('branchIdForProject');
        $google->shouldNotReceive('push');
        $this->app->instance(DanaTalanganGoogleService::class, $google);
        $activityCount = ActivityLog::where('subject_type', DanaTalangan::class)->where('subject_id', $record->id)->count();

        $this->actingAs($user)->putJson(route('dana-talangan.update', $record), [
            'tanggal' => today()->toDateString(), 'nama_konsumen' => 'Versi Lama', 'project_name' => 'Proyek Lock',
            'status' => 'sanggup', 'expected_updated_at' => $stale,
        ])->assertConflict()->assertJsonPath('code', 'record_modified');

        $this->assertSame('Versi Baru', $record->fresh()->nama_konsumen);
        $this->assertSame($activityCount, ActivityLog::where('subject_type', DanaTalangan::class)->where('subject_id', $record->id)->count());
    }

    public function test_database_conflict_happens_before_google_write_and_identity_is_checked(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $record = DatabaseSheetRecord::create([
            'branch_id' => $branch->id, 'sheet_id' => 'sheet', 'sheet_name' => 'Leads', 'row_number' => 2,
            'oasis_sync_id' => 'stable-source-id', 'headers' => ['status'], 'row_data' => ['status' => 'Aktif'],
        ]);
        $writer = Mockery::mock(DatabaseSheetWriteService::class);
        $writer->shouldNotReceive('updateRecord');
        $this->app->instance(DatabaseSheetWriteService::class, $writer);

        $this->actingAs($user)->putJson(route('database.records.update', $record), [
            'status' => 'Tidak Aktif',
            'expected_updated_at' => app(OptimisticLockService::class)->token($record),
            'expected_sync_id' => 'different-source-id',
        ])->assertConflict()->assertJsonPath('code', 'record_modified');
        $this->assertSame('Aktif', $record->fresh()->row_data['status']);
    }

    public function test_database_replaced_by_sync_returns_conflict_instead_of_not_found(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $old = DatabaseSheetRecord::create([
            'branch_id' => $branch->id, 'sheet_id' => 'sheet', 'sheet_name' => 'Leads', 'row_number' => 2,
            'oasis_sync_id' => 'stable-replaced-id', 'headers' => ['status'], 'row_data' => ['status' => 'Aktif'],
        ]);
        $oldId = $old->id;
        $oldToken = app(OptimisticLockService::class)->token($old);
        $old->delete();
        $replacement = DatabaseSheetRecord::create([
            'branch_id' => $branch->id, 'sheet_id' => 'sheet', 'sheet_name' => 'Leads', 'row_number' => 2,
            'oasis_sync_id' => 'stable-replaced-id', 'headers' => ['status'], 'row_data' => ['status' => 'Baru'],
        ]);
        $writer = Mockery::mock(DatabaseSheetWriteService::class);
        $writer->shouldNotReceive('updateRecord');
        $this->app->instance(DatabaseSheetWriteService::class, $writer);

        $this->actingAs($user)->putJson(route('database.records.update', ['record' => $oldId]), [
            'status' => 'Tidak Aktif', 'expected_updated_at' => $oldToken, 'expected_sync_id' => 'stable-replaced-id',
        ])->assertConflict()
            ->assertJsonPath('record_type', 'database_sheet_record')
            ->assertJsonPath('record_id', $replacement->id);
    }

    public function test_timezone_equivalent_timestamp_matches_without_false_conflict(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $item = ContentItem::create([
            'branch_id' => $branch->id, 'item_type' => 'task', 'visibility' => 'team', 'title' => 'Timezone',
            'deadline_date' => today(), 'scheduled_date' => today(), 'priority' => 'medium', 'status' => 'todo', 'created_by' => $user->id,
        ]);
        $equivalent = $item->updated_at->copy()->setTimezone('Asia/Jakarta')->toIso8601String();
        $this->assertTrue(app(OptimisticLockService::class)->matches($item, $equivalent));
    }

    public function test_blade_conflict_preserves_stale_token_and_unsaved_input(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $record = DanaTalangan::create([
            'branch_id' => $branch->id, 'created_by' => $user->id, 'tanggal' => today(),
            'nama_konsumen' => 'Versi Saat Ini', 'status' => 'sanggup',
        ]);
        $stale = Carbon::parse($record->updated_at)->subSecond()->utc()->format('Y-m-d H:i:s');
        $google = Mockery::mock(DanaTalanganGoogleService::class);
        $google->shouldNotReceive('branchIdForProject');
        $this->app->instance(DanaTalanganGoogleService::class, $google);

        $this->actingAs($user)->from(route('dana-talangan.edit', $record))->put(route('dana-talangan.update', $record), [
            'tanggal' => today()->toDateString(), 'nama_konsumen' => 'Perubahan Belum Tersimpan',
            'project_name' => 'Proyek Lama', 'status' => 'sanggup', 'expected_updated_at' => $stale,
        ])->assertRedirect(route('dana-talangan.edit', $record))
            ->assertSessionHas('conflict_data.code', 'record_modified')
            ->assertSessionHasInput('expected_updated_at', $stale)
            ->assertSessionHasInput('nama_konsumen', 'Perubahan Belum Tersimpan');
    }

    public function test_unauthorized_user_gets_no_conflict_modifier_details(): void
    {
        [$branch, $owner] = $this->branchAndUser();
        $otherBranch = Branch::create(['name' => 'Pati', 'code' => 'PTI', 'is_active' => true]);
        $outsider = User::factory()->create(['role_id' => $owner->role_id, 'branch_id' => $otherBranch->id, 'password_changed_at' => now()]);
        $record = DanaTalangan::create([
            'branch_id' => $branch->id, 'created_by' => $owner->id, 'updated_by' => $owner->id,
            'tanggal' => today(), 'nama_konsumen' => 'Rahasia Modifier', 'status' => 'sanggup',
        ]);

        $response = $this->actingAs($outsider)->putJson(route('dana-talangan.update', $record), [
            'tanggal' => today()->toDateString(), 'nama_konsumen' => 'Tidak Sah', 'project_name' => 'Tidak Ada',
            'status' => 'sanggup', 'expected_updated_at' => '2000-01-01 00:00:00',
        ])->assertForbidden();

        $this->assertStringNotContainsString($owner->name, $response->getContent());
        $this->assertStringNotContainsString($owner->email, $response->getContent());
    }

    private function branchAndUser(): array
    {
        $role = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin', 'is_superadmin' => false]);
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);
        $user = User::factory()->create(['role_id' => $role->id, 'branch_id' => $branch->id, 'password_changed_at' => now()]);

        return [$branch, $user];
    }
}
