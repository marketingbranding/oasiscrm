<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DatabaseSheetSyncStatus;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncStatusPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_reload_renders_persisted_success_as_fresh_authoritative_status(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $finishedAt = now()->startOfMinute();
        DatabaseSheetSyncStatus::create([
            'branch_id' => $branch->id,
            'status' => 'success',
            'started_at' => $finishedAt->copy()->subSeconds(3),
            'finished_at' => $finishedAt,
            'last_successful_at' => $finishedAt,
            'initiated_by' => $user->id,
            'summary' => ['Leads' => 10],
        ]);

        $response = $this->actingAs($user)->get(route('database.index', ['branch_id' => $branch->id]))
            ->assertOk()
            ->assertSee('Terakhir sync '.$finishedAt->translatedFormat('d M Y H:i'))
            ->assertSee('DATA TERBARU')
            ->assertDontSee('DATA PERLU DIPERBARUI')
            ->assertSee($user->name);

        $this->assertSame(1, substr_count($response->getContent(), 'Terakhir sync'));
    }

    public function test_page_reload_renders_running_status_and_badge(): void
    {
        [$branch, $user] = $this->branchAndUser();
        DatabaseSheetSyncStatus::create([
            'branch_id' => $branch->id,
            'status' => 'running',
            'started_at' => now(),
            'initiated_by' => $user->id,
        ]);

        $this->actingAs($user)->get(route('database.index', ['branch_id' => $branch->id]))
            ->assertOk()
            ->assertSee('Sinkronisasi sedang berjalan...')
            ->assertSee('SEDANG SINKRONISASI');
    }

    public function test_page_reload_renders_failed_status_with_safe_message(): void
    {
        [$branch, $user] = $this->branchAndUser();
        DatabaseSheetSyncStatus::create([
            'branch_id' => $branch->id,
            'status' => 'failed',
            'message' => 'RAW GOOGLE secret-token',
            'started_at' => now()->subSecond(),
            'finished_at' => now(),
            'initiated_by' => $user->id,
        ]);

        $this->actingAs($user)->get(route('database.index', ['branch_id' => $branch->id]))
            ->assertOk()
            ->assertSee('Sync terakhir gagal: Sinkronisasi gagal sebelum seluruh data dapat diperbarui.')
            ->assertSee('SYNC GAGAL')
            ->assertDontSee('secret-token');
    }

    public function test_page_reload_marks_old_success_as_stale(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $finishedAt = now()->subMinutes((int) config('services.google_sheets.cache_stale_minutes', 30) + 1);
        DatabaseSheetSyncStatus::create([
            'branch_id' => $branch->id,
            'status' => 'success',
            'started_at' => $finishedAt->copy()->subSecond(),
            'finished_at' => $finishedAt,
            'last_successful_at' => $finishedAt,
            'initiated_by' => $user->id,
        ]);

        $this->actingAs($user)->get(route('database.index', ['branch_id' => $branch->id]))
            ->assertOk()
            ->assertSee('DATA PERLU DIPERBARUI')
            ->assertDontSee('DATA TERBARU');
    }

    public function test_shared_event_is_scope_guarded_and_database_refresh_protects_active_context(): void
    {
        $js = file_get_contents(resource_path('js/crm-sync.js'));
        $control = file_get_contents(resource_path('views/components/crm/sync-control.blade.php'));
        $panel = file_get_contents(resource_path('views/components/crm/sync-status-panel.blade.php'));
        $database = file_get_contents(resource_path('views/crm/database/index.blade.php'));

        foreach (['module_key', 'scope', 'status', 'message', 'started_at', 'finished_at', 'last_successful_sync_at', 'initiated_by', 'summary'] as $field) {
            $this->assertStringContainsString($field, $js);
        }
        $this->assertStringContainsString("new CustomEvent('oasis-sync-updated'", $js);
        $this->assertStringContainsString('detail.module_key !== config.moduleKey', $js);
        $this->assertStringContainsString('eventScope === panelScope', $js);
        $this->assertStringContainsString('@oasis-sync-updated.window="applyEvent($event.detail)"', $panel);
        $this->assertStringNotContainsString('completedAt', $control);
        $this->assertStringNotContainsString('Terakhir sync', $control);

        $this->assertStringContainsString('@oasis-sync-updated.window="handleSyncUpdated($event.detail)"', $database);
        $this->assertStringContainsString("detail.module_key !== 'database' || detail.status !== 'success'", $database);
        $this->assertStringContainsString("String(detail.scope?.id ?? '') !== String(this.branchId)", $database);
        $this->assertStringContainsString('const sheet = this.tab', $database);
        $this->assertStringContainsString('data.sheet_name !== sheet', $database);
        $this->assertStringContainsString('config.sheetDataBaseUrl', $database);
        $this->assertStringContainsString('Sinkronisasi berhasil, tetapi tabel belum dapat dimuat ulang.', $database);
        $this->assertStringContainsString('Coba Muat Ulang Data', $database);
        $this->assertStringContainsString('if (this.editing)', $database);
        $this->assertStringContainsString('Pertahankan Draf', $database);
        $this->assertStringNotContainsString('window.location.reload', $database);
    }

    private function branchAndUser(): array
    {
        $role = Role::firstOrCreate(['slug' => 'pusat'], ['name' => 'Pusat', 'is_superadmin' => false]);
        $branch = Branch::create(['name' => 'Magelang', 'code' => 'MGL', 'sheet_id' => 'sheet-id', 'is_active' => true]);
        $user = User::factory()->create([
            'role_id' => $role->id,
            'branch_id' => $branch->id,
            'password_changed_at' => now(),
        ]);

        return [$branch, $user];
    }
}
