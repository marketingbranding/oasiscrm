<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ContentItem;
use App\Models\SalesAgendaEvidence;
use App\Models\User;
use App\Services\SalesAgendaEvidenceArchiveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
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
}
