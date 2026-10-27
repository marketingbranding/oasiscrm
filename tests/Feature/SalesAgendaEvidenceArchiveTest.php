<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ContentItem;
use App\Models\Role;
use App\Models\SalesAgendaEvidence;
use App\Models\SalesAgendaEvidenceArchive;
use App\Models\SalesDailyPlan;
use App\Models\SalesDailyPlanItem;
use App\Models\SalesDailyPlanItemEvidence;
use App\Models\User;
use App\Services\SalesAgendaEvidenceArchiveService;
use App\Services\SyncLockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class SalesAgendaEvidenceArchiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_build_groups_week_and_branch_verifies_manifest_then_safe_purge_retains_metadata(): void
    {
        Storage::fake('agenda_evidence');
        Storage::fake('agenda_evidence_archives');
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SO', 'is_active' => true]);
        $user = User::factory()->create(['branch_id' => $branch->id]);
        $agenda = ContentItem::create(['branch_id' => $branch->id, 'item_type' => 'agenda', 'agenda_type' => ContentItem::SALES_AGENDA_TYPE, 'title' => 'Visit', 'scheduled_date' => '2026-05-04', 'status' => 'done', 'owner_user_id' => $user->id, 'created_by' => $user->id]);
        Storage::disk('agenda_evidence')->put('sales-agenda-evidence/a.webp', 'photo');
        $evidence = SalesAgendaEvidence::create(['content_item_id' => $agenda->id, 'uploaded_by_user_id' => $user->id, 'storage_path' => 'sales-agenda-evidence/a.webp', 'original_name' => 'a.jpg', 'mime_type' => 'image/webp', 'width' => 10, 'height' => 10, 'size_bytes' => 5, 'sha256' => hash('sha256', 'photo'), 'created_at' => '2026-05-04']);
        $archive = app(SalesAgendaEvidenceArchiveService::class)->build($branch, '2026-05-04');
        $this->assertSame('ready', $archive->status);
        $this->assertCount(1, $archive->manifest['files']);
        $evidence->update(['created_at' => now()->subDays(61)]);
        $this->assertSame(1, app(SalesAgendaEvidenceArchiveService::class)->purge(now()->subDays(60)));
        $this->assertNotNull($evidence->fresh()->purged_at);
        $this->assertNull($evidence->fresh()->storage_path);
    }

    public function test_archive_build_uses_the_shared_archive_lock(): void
    {
        Storage::fake('agenda_evidence');
        Storage::fake('agenda_evidence_archives');
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SO', 'is_active' => true]);
        $locks = Mockery::mock(SyncLockService::class);
        $locks->shouldReceive('runOrThrow')->once()->withArgs(fn (string $key): bool => $key === 'sales-agenda-evidence-archive')->andReturnUsing(fn (string $key, callable $callback, int $seconds): mixed => $callback());
        $this->app->instance(SyncLockService::class, $locks);

        app(SalesAgendaEvidenceArchiveService::class)->build($branch, '2026-05-04');
    }

    public function test_tampered_archive_blocks_purge_and_failed_rebuild_preserves_verified_archive(): void
    {
        Storage::fake('agenda_evidence');
        Storage::fake('agenda_evidence_archives');
        $branch = Branch::create(['name' => 'Pati', 'code' => 'PA', 'is_active' => true]);
        $user = User::factory()->create(['branch_id' => $branch->id]);
        $agenda = ContentItem::create(['branch_id' => $branch->id, 'item_type' => 'agenda', 'agenda_type' => ContentItem::SALES_AGENDA_TYPE, 'title' => 'Visit', 'scheduled_date' => now()->subDays(70), 'status' => 'done', 'owner_user_id' => $user->id, 'created_by' => $user->id]);
        Storage::disk('agenda_evidence')->put('sales-agenda-evidence/proof.webp', 'proof');
        $evidence = SalesAgendaEvidence::create(['content_item_id' => $agenda->id, 'uploaded_by_user_id' => $user->id, 'storage_path' => 'sales-agenda-evidence/proof.webp', 'original_name' => 'proof.jpg', 'mime_type' => 'image/webp', 'width' => 10, 'height' => 10, 'size_bytes' => 5, 'sha256' => hash('sha256', 'proof'), 'created_at' => now()->subDays(70)]);
        $service = app(SalesAgendaEvidenceArchiveService::class);
        $archive = $service->build($branch, $agenda->scheduled_date->toDateString());
        Storage::disk('agenda_evidence_archives')->put($archive->storage_path, 'tampered');
        $this->assertSame(0, $service->purge(now()->subDays(60)));
        $this->assertNull($evidence->fresh()->purged_at);
        $oldPath = $archive->storage_path;
        Storage::disk('agenda_evidence')->delete($evidence->storage_path);
        $rebuilt = $service->build($branch, $agenda->scheduled_date->toDateString());
        $this->assertSame('ready', $rebuilt->status);
        $this->assertSame($oldPath, $rebuilt->storage_path);
        $this->assertNotNull($rebuilt->error);
    }

    public function test_build_and_purge_includes_daily_plan_item_evidence(): void
    {
        Storage::fake('agenda_evidence');
        Storage::fake('agenda_evidence_archives');
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SO', 'is_active' => true]);
        $user = User::factory()->create(['branch_id' => $branch->id]);
        $plan = SalesDailyPlan::create([
            'sales_user_id' => $user->id,
            'branch_id' => $branch->id,
            'work_date' => '2026-05-04',
            'status' => 'submitted',
        ]);
        $item = SalesDailyPlanItem::create([
            'sales_daily_plan_id' => $plan->id,
            'position' => 0,
            'activity' => 'Kunjungan calon konsumen',
            'duration_minutes' => 420,
            'start_time' => '09:00:00',
            'end_time' => '17:00:00',
            'status' => 'completed',
        ]);
        Storage::disk('agenda_evidence')->put('sales-daily-plan-evidence/daily.webp', 'daily');
        $evidence = SalesDailyPlanItemEvidence::create([
            'sales_daily_plan_item_id' => $item->id,
            'uploaded_by_user_id' => $user->id,
            'storage_path' => 'sales-daily-plan-evidence/daily.webp',
            'original_name' => 'daily.jpg',
            'mime_type' => 'image/webp',
            'width' => 10,
            'height' => 10,
            'size_bytes' => 5,
            'sha256' => hash('sha256', 'daily'),
        ]);
        $evidence->forceFill(['created_at' => now()->subDays(61)])->save();

        $archive = app(SalesAgendaEvidenceArchiveService::class)->build($branch, '2026-05-04');

        $this->assertSame('ready', $archive->status);
        $this->assertSame('daily_plan', $archive->manifest['files'][0]['source']);
        $this->assertSame($evidence->id, $archive->manifest['files'][0]['evidence_id']);
        $this->assertSame($archive->id, $evidence->fresh()->archive_id);
        $this->assertSame('archived', $evidence->fresh()->archive_status);
        $this->assertCount(1, $archive->dailyPlanEvidence()->get());
        $this->assertTrue($evidence->fresh()->created_at->lte(now()->subDays(60)));
        $this->assertSame(1, app(SalesAgendaEvidenceArchiveService::class)->purge(now()->subDays(60)));
        $this->assertNotNull($evidence->fresh()->purged_at);
        $this->assertNull($evidence->fresh()->storage_path);
    }

    public function test_daily_plan_evidence_retention_changelog_is_unique_and_rendered(): void
    {
        $title = 'Retensi Bukti Agenda Harian';
        $role = Role::firstOrCreate(['slug' => 'superadmin'], ['name' => 'Super Admin', 'is_superadmin' => true]);
        $superadmin = User::factory()->create([
            'role_id' => $role->id,
            'password_changed_at' => now(),
        ]);

        $this->assertSame(1, DB::table('changelogs')->whereNull('version')->where('title', $title)->count());
        $this->actingAs($superadmin)->get(route('changelogs.index'))->assertOk()->assertSeeText($title);
    }

    public function test_rebuilding_archive_does_not_purge_evidence_removed_from_current_manifest(): void
    {
        Storage::fake('agenda_evidence');
        Storage::fake('agenda_evidence_archives');
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SO', 'is_active' => true]);
        $user = User::factory()->create(['branch_id' => $branch->id]);
        $agenda = ContentItem::create(['branch_id' => $branch->id, 'item_type' => 'agenda', 'agenda_type' => ContentItem::SALES_AGENDA_TYPE, 'title' => 'Visit', 'scheduled_date' => '2026-05-04', 'status' => 'done', 'owner_user_id' => $user->id, 'created_by' => $user->id]);
        Storage::disk('agenda_evidence')->put('sales-agenda-evidence/orphan.webp', 'orphan');
        $evidence = SalesAgendaEvidence::create(['content_item_id' => $agenda->id, 'uploaded_by_user_id' => $user->id, 'storage_path' => 'sales-agenda-evidence/orphan.webp', 'original_name' => 'orphan.jpg', 'mime_type' => 'image/webp', 'width' => 10, 'height' => 10, 'size_bytes' => 6, 'sha256' => hash('sha256', 'orphan')]);
        $service = app(SalesAgendaEvidenceArchiveService::class);
        $service->build($branch, '2026-05-04');

        $agenda->update(['branch_id' => Branch::create(['name' => 'Pati', 'code' => 'PA', 'is_active' => true])->id]);
        $service->build($branch, '2026-05-04');

        $this->assertNull($evidence->fresh()->purged_at);
        Storage::disk('agenda_evidence')->assertExists('sales-agenda-evidence/orphan.webp');
    }

    public function test_build_after_purge_archives_late_daily_plan_evidence_in_the_same_week(): void
    {
        Storage::fake('agenda_evidence');
        Storage::fake('agenda_evidence_archives');
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SO', 'is_active' => true]);
        $user = User::factory()->create(['branch_id' => $branch->id]);
        $plan = SalesDailyPlan::create(['sales_user_id' => $user->id, 'branch_id' => $branch->id, 'work_date' => '2026-05-04', 'status' => 'submitted']);
        $item = SalesDailyPlanItem::create(['sales_daily_plan_id' => $plan->id, 'position' => 0, 'activity' => 'Kunjungan', 'duration_minutes' => 420, 'start_time' => '09:00:00', 'end_time' => '17:00:00', 'status' => 'completed']);
        Storage::disk('agenda_evidence')->put('sales-daily-plan-evidence/first.webp', 'first');
        $first = SalesDailyPlanItemEvidence::create(['sales_daily_plan_item_id' => $item->id, 'uploaded_by_user_id' => $user->id, 'storage_path' => 'sales-daily-plan-evidence/first.webp', 'original_name' => 'first.jpg', 'mime_type' => 'image/webp', 'width' => 10, 'height' => 10, 'size_bytes' => 5, 'sha256' => hash('sha256', 'first')]);
        $first->forceFill(['created_at' => now()->subDays(61)])->save();
        $service = app(SalesAgendaEvidenceArchiveService::class);
        $archive = $service->build($branch, '2026-05-04');
        $this->assertSame(1, $service->purge(now()->subDays(60)));

        Storage::disk('agenda_evidence')->put('sales-daily-plan-evidence/late.webp', 'late');
        $late = SalesDailyPlanItemEvidence::create(['sales_daily_plan_item_id' => $item->id, 'uploaded_by_user_id' => $user->id, 'storage_path' => 'sales-daily-plan-evidence/late.webp', 'original_name' => 'late.jpg', 'mime_type' => 'image/webp', 'width' => 10, 'height' => 10, 'size_bytes' => 4, 'sha256' => hash('sha256', 'late')]);

        $rebuilt = $service->build($branch, '2026-05-04');

        $this->assertSame($archive->id, $rebuilt->id);
        $this->assertSame([$first->id, $late->id], collect($rebuilt->manifest['files'])->pluck('evidence_id')->all());
        $this->assertSame($rebuilt->id, $late->fresh()->archive_id);
    }

    public function test_rebuild_fails_closed_when_purged_evidence_is_missing_from_the_manifest(): void
    {
        Storage::fake('agenda_evidence');
        Storage::fake('agenda_evidence_archives');
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SO', 'is_active' => true]);
        $user = User::factory()->create(['branch_id' => $branch->id]);
        $agenda = ContentItem::create(['branch_id' => $branch->id, 'item_type' => 'agenda', 'agenda_type' => ContentItem::SALES_AGENDA_TYPE, 'title' => 'Visit', 'scheduled_date' => '2026-05-04', 'status' => 'done', 'owner_user_id' => $user->id, 'created_by' => $user->id]);
        Storage::disk('agenda_evidence')->put('sales-agenda-evidence/orphan.webp', 'orphan');
        $evidence = SalesAgendaEvidence::create(['content_item_id' => $agenda->id, 'uploaded_by_user_id' => $user->id, 'storage_path' => 'sales-agenda-evidence/orphan.webp', 'original_name' => 'orphan.jpg', 'mime_type' => 'image/webp', 'width' => 10, 'height' => 10, 'size_bytes' => 6, 'sha256' => hash('sha256', 'orphan')]);
        $service = app(SalesAgendaEvidenceArchiveService::class);
        $archive = $service->build($branch, '2026-05-04');
        $evidence->forceFill(['storage_path' => null, 'purged_at' => now(), 'archive_status' => 'purged'])->save();
        $archive->forceFill(['manifest' => ['files' => []]])->save();

        try {
            $service->build($branch, '2026-05-04');
            $this->fail('A purged evidence record missing from the manifest must stop rebuild.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Bukti yang sudah dipurge tidak ditemukan dalam manifest arsip.', $exception->getMessage());
        }

        Storage::disk('agenda_evidence_archives')->assertExists($archive->storage_path);
    }

    public function test_daily_plan_evidence_stays_with_the_plan_branch_after_sales_branch_transfer(): void
    {
        Storage::fake('agenda_evidence');
        Storage::fake('agenda_evidence_archives');
        $originalBranch = Branch::create(['name' => 'Solo', 'code' => 'SO', 'is_active' => true]);
        $newBranch = Branch::create(['name' => 'Pati', 'code' => 'PA', 'is_active' => true]);
        $user = User::factory()->create(['branch_id' => $originalBranch->id]);
        $plan = SalesDailyPlan::create(['sales_user_id' => $user->id, 'branch_id' => $originalBranch->id, 'work_date' => '2026-05-04', 'status' => 'submitted']);
        $item = SalesDailyPlanItem::create(['sales_daily_plan_id' => $plan->id, 'position' => 0, 'activity' => 'Kunjungan', 'duration_minutes' => 420, 'start_time' => '09:00:00', 'end_time' => '17:00:00', 'status' => 'completed']);
        Storage::disk('agenda_evidence')->put('sales-daily-plan-evidence/branch.webp', 'branch');
        $evidence = SalesDailyPlanItemEvidence::create(['sales_daily_plan_item_id' => $item->id, 'uploaded_by_user_id' => $user->id, 'storage_path' => 'sales-daily-plan-evidence/branch.webp', 'original_name' => 'branch.jpg', 'mime_type' => 'image/webp', 'width' => 10, 'height' => 10, 'size_bytes' => 6, 'sha256' => hash('sha256', 'branch')]);
        $service = app(SalesAgendaEvidenceArchiveService::class);
        $service->build($originalBranch, '2026-05-04');
        $user->update(['branch_id' => $newBranch->id]);

        $archive = $service->build($originalBranch, '2026-05-04');

        $this->assertSame($evidence->id, $archive->manifest['files'][0]['evidence_id']);
        $this->assertSame($archive->id, $evidence->fresh()->archive_id);
    }

    public function test_daily_plan_evidence_retention_migration_refuses_rollback_after_archive_metadata_is_used(): void
    {
        $archive = SalesAgendaEvidenceArchive::create([
            'branch_id' => Branch::create(['name' => 'Solo', 'code' => 'SO', 'is_active' => true])->id,
            'week_start' => '2026-05-04',
            'status' => 'ready',
        ]);
        $user = User::factory()->create();
        $plan = SalesDailyPlan::create(['sales_user_id' => $user->id, 'work_date' => '2026-05-04', 'status' => 'draft']);
        $item = SalesDailyPlanItem::create(['sales_daily_plan_id' => $plan->id, 'position' => 0, 'activity' => 'Kunjungan', 'duration_minutes' => 60, 'start_time' => '09:00:00', 'end_time' => '10:00:00']);
        SalesDailyPlanItemEvidence::create([
            'sales_daily_plan_item_id' => $item->id,
            'archive_id' => $archive->id,
            'original_name' => 'archived.jpg',
            'mime_type' => 'image/jpeg',
            'width' => 10,
            'height' => 10,
            'size_bytes' => 5,
            'sha256' => hash('sha256', 'photo'),
            'archive_entry_path' => 'evidence/archived.webp',
            'archived_at' => now(),
        ]);
        $migration = require database_path('migrations/2026_10_26_180000_integrate_daily_plan_evidence_retention.php');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Rollback ditolak');
        $migration->down();
    }

    public function test_daily_plan_branch_snapshot_migration_refuses_rollback_after_snapshot_is_used(): void
    {
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SO', 'is_active' => true]);
        $user = User::factory()->create(['branch_id' => $branch->id]);
        SalesDailyPlan::create(['sales_user_id' => $user->id, 'branch_id' => $branch->id, 'work_date' => '2026-05-04', 'status' => 'draft']);
        $migration = require database_path('migrations/2026_10_27_090000_add_branch_snapshot_to_sales_daily_plans.php');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Rollback ditolak');
        $migration->down();
    }
}
