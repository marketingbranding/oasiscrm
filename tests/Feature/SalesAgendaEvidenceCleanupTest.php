<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\ContentItem;
use App\Models\Role;
use App\Models\SalesAgendaEvidence;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SalesAgendaEvidenceCleanupTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_cleanup_deletes_agenda_and_zero_or_two_local_evidence_files(): void
    {
        Storage::fake('agenda_evidence');
        $superadmin = $this->user('superadmin');

        foreach ([0, 2] as $count) {
            $agenda = $this->agenda($superadmin, "Agenda {$count}");
            $paths = [];
            for ($index = 0; $index < $count; $index++) {
                $paths[] = "evidence/{$count}-{$index}.jpg";
                Storage::disk('agenda_evidence')->put(end($paths), 'photo');
                $this->evidence($agenda, end($paths));
            }

            $this->actingAs($superadmin)->post(route('sales-agendas.cleanup', $agenda), [
                'reason' => 'Retensi selesai', 'confirmation' => '1',
            ])->assertRedirect();

            $this->assertDatabaseMissing('content_items', ['id' => $agenda->id]);
            $this->assertSame(0, SalesAgendaEvidence::where('content_item_id', $agenda->id)->count());
            foreach ($paths as $path) {
                Storage::disk('agenda_evidence')->assertMissing($path);
            }
        }
    }

    public function test_archived_evidence_returns_409_and_preserves_archive_and_agenda(): void
    {
        Storage::fake('agenda_evidence');
        $superadmin = $this->user('superadmin');
        $agenda = $this->agenda($superadmin, 'Arsip');
        $archivePath = 'archives/week.zip';
        $evidence = $this->evidence($agenda, 'evidence/archive.jpg');
        $evidence->update(['archived_at' => now(), 'archive_status' => 'ready']);
        Storage::disk('agenda_evidence')->put($archivePath, 'zip');

        $this->actingAs($superadmin)->post(route('sales-agendas.cleanup', $agenda), [
            'reason' => 'Tidak boleh', 'confirmation' => '1',
        ])->assertStatus(409);

        $this->assertTrue($agenda->fresh()->exists);
        $this->assertDatabaseHas('sales_agenda_evidences', ['id' => $evidence->id, 'archive_status' => 'ready']);
        Storage::disk('agenda_evidence')->assertExists($archivePath);
    }

    public function test_only_primary_superadmin_can_cleanup_and_impersonated_sales_uses_original_actor(): void
    {
        Storage::fake('agenda_evidence');
        $superadmin = $this->user('superadmin');
        $sales = $this->user('sales');
        $agenda = $this->agenda($sales, 'Impersonated');

        $this->actingAs($superadmin)->post(route('sales-agendas.cleanup', $agenda), [
            'reason' => 'Direct cleanup', 'confirmation' => '1',
        ])->assertRedirect();

        $agenda = $this->agenda($sales, 'Impersonated again');
        $this->actingAs($sales)->withSession([
            'impersonation.original_user_id' => $superadmin->id,
            'impersonation.target_user_id' => $sales->id,
            'impersonation.started_at' => now()->timestamp,
        ])->post(route('sales-agendas.cleanup', $agenda), [
            'reason' => 'Cleanup via impersonation', 'confirmation' => '1',
        ])->assertRedirect();

        $audit = ActivityLog::where('event', 'sales_agenda_cleaned_by_superadmin')
            ->whereJsonContains('properties->original_user_id', $superadmin->id)
            ->firstOrFail();
        $this->assertSame($superadmin->id, $audit->causer_id);
        $this->assertSame($sales->id, $audit->properties['target_user_id']);
        $this->assertSame($superadmin->id, $audit->properties['original_user_id']);

        $normalAgenda = $this->agenda($sales, 'Normal target');
        $auditCount = ActivityLog::where('event', 'sales_agenda_cleaned_by_superadmin')->count();
        session()->forget(['impersonation.original_user_id', 'impersonation.target_user_id', 'impersonation.started_at']);
        $this->actingAs($sales)->post(route('sales-agendas.cleanup', $normalAgenda), [
            'reason' => 'Denied', 'confirmation' => '1',
        ])->assertForbidden();
        $this->assertSame($auditCount, ActivityLog::where('event', 'sales_agenda_cleaned_by_superadmin')->count());

        $forged = $this->user('sales');
        $forgedAgenda = $this->agenda($sales, 'Forged');
        $this->actingAs($forged)->withSession([
            'impersonation.original_user_id' => $forged->id,
            'impersonation.target_user_id' => $sales->id,
            'impersonation.started_at' => now()->timestamp,
        ])->post(route('sales-agendas.cleanup', $forgedAgenda), [
            'reason' => 'Forged', 'confirmation' => '1',
        ])->assertForbidden();
        $this->assertSame($auditCount, ActivityLog::where('event', 'sales_agenda_cleaned_by_superadmin')->count());
    }

    public function test_monitoring_views_expose_evidence_links_or_archive_placeholders_without_write_actions(): void
    {
        foreach (['coordinator-leads.blade.php', 'supervisor-monitoring.blade.php', 'admin-monitoring.blade.php'] as $view) {
            $contents = file_get_contents(resource_path("views/crm/sales-pocketbook/{$view}"));
            $this->assertStringContainsString("route('sales-agendas.evidence.show'", $contents);
            $this->assertStringContainsString('Bukti foto telah dipindahkan ke arsip.', $contents);
            $this->assertStringNotContainsString("route('sales-agendas.store')", $contents);
            $this->assertStringNotContainsString("route('sales-agendas.update')", $contents);
            $this->assertStringNotContainsString("route('sales-agendas.evidence.destroy')", $contents);
            $this->assertStringNotContainsString("route('sales-agendas.cleanup')", $contents);
        }
    }

    private function user(string $role): User
    {
        $branch = Branch::firstOrCreate(['code' => 'TST'], ['name' => 'Test', 'is_active' => true]);

        return User::factory()->create([
            'role_id' => Role::where('slug', $role)->value('id'), 'branch_id' => $branch->id,
            'account_status' => 'active', 'is_active' => true, 'email_verified_at' => now(), 'password_changed_at' => now(),
        ]);
    }

    private function agenda(User $owner, string $title): ContentItem
    {
        return ContentItem::create([
            'branch_id' => $owner->branch_id, 'item_type' => 'agenda', 'agenda_type' => ContentItem::SALES_AGENDA_TYPE,
            'title' => $title, 'scheduled_date' => now(), 'status' => 'done', 'owner_user_id' => $owner->id, 'created_by' => $owner->id,
        ]);
    }

    private function evidence(ContentItem $agenda, string $path): SalesAgendaEvidence
    {
        return SalesAgendaEvidence::create([
            'content_item_id' => $agenda->id, 'uploaded_by_user_id' => $agenda->owner_user_id, 'storage_path' => $path,
            'original_name' => basename($path), 'mime_type' => 'image/jpeg', 'width' => 10, 'height' => 10,
            'size_bytes' => 5, 'sha256' => hash('sha256', 'photo'),
        ]);
    }
}
