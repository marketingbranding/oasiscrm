<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DanaTalangan;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\User;
use App\Services\DanaTalanganGoogleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class DanaTalanganInteractionTest extends TestCase
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

    private function makeBranchAndUser(): array
    {
        $branch = Branch::create(['name' => 'Cabang Test', 'code' => 'TEST', 'is_active' => true]);
        $user = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'admin')->value('id'),
            'branch_id' => $branch->id,
            'password_changed_at' => now(),
        ]);

        return [$branch, $user];
    }

    private function makeProject(Branch $branch, string $name = 'Proyek Test'): LeadMaster
    {
        return LeadMaster::create(['branch_id' => $branch->id, 'project_name' => $name, 'is_active' => true]);
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

    public function test_page_alpine_attribute_is_complete_and_does_not_render_javascript_as_text(): void
    {
        [$branch, $user] = $this->makeBranchAndUser();
        $this->makeProject($branch);
        $this->makeRecord($branch, $user);

        $response = $this->actingAs($user)->get(route('dana-talangan.index'));
        $html = $response->getContent();

        $response->assertOk();
        $this->assertMatchesRegularExpression('/<h1 class="crm-page-header-title">\s*Dana Talangan\s*<\/h1>/', $html);
        $this->assertMatchesRegularExpression('/<div x-data="danaTalanganPage\(crmDetailModal\([^\"]+\)\)">/', $html);
        $this->assertStringContainsString('"modalFocusSelector"', $html);

        $visibleHtml = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);
        $visibleText = html_entity_decode(strip_tags($visibleHtml));
        $this->assertStringNotContainsString('modalFocusSelector:', $visibleText);
        $this->assertStringNotContainsString('lockModalScroll()', $visibleText);
        $this->assertStringNotContainsString('async loadEditKavlings', $visibleText);
    }

    public function test_rendering_fix_changelog_is_unique_and_visible(): void
    {
        [, $user] = $this->makeBranchAndUser();

        $this->assertSame(1, app('db')->table('changelogs')
            ->whereNull('version')
            ->where('title', 'Tampilan Dana Talangan Kembali Normal')
            ->count());

        $this->actingAs($user)->get(route('changelogs.index'))
            ->assertOk()
            ->assertSeeText('Tampilan Dana Talangan Kembali Normal');
    }

    public function test_add_modal_wires_focus_trap_and_escape_close(): void
    {
        [$branch, $user] = $this->makeBranchAndUser();
        $this->makeProject($branch);
        $this->makeRecord($branch, $user);

        $html = $this->actingAs($user)->get(route('dana-talangan.index'))->getContent();

        $this->assertStringContainsString('x-ref="addModalPanel"', $html);
        $this->assertStringContainsString('@keydown.tab="trapModalFocus($event, \'addModalPanel\')"', $html);
        $this->assertStringContainsString('@keydown.escape.window="closeAddModal()"', $html);
        $this->assertStringContainsString("openModal('addModalPanel', \$refs.addTrigger)", $html);
    }

    public function test_edit_modal_wires_focus_trap_and_escape_close(): void
    {
        [$branch, $user] = $this->makeBranchAndUser();
        $this->makeProject($branch);
        $this->makeRecord($branch, $user);

        $html = $this->actingAs($user)->get(route('dana-talangan.index'))->getContent();

        $this->assertStringContainsString('x-ref="editModalPanel"', $html);
        $this->assertStringContainsString('@keydown.tab="trapModalFocus($event, \'editModalPanel\')"', $html);
        $this->assertStringContainsString('@keydown.escape.window="closeEditModal()"', $html);
        $this->assertMatchesRegularExpression('/@click="openEdit\(.+?\$event\.currentTarget\)"/', $html);
    }

    public function test_filter_modal_wires_focus_trap_and_escape_close(): void
    {
        [$branch, $user] = $this->makeBranchAndUser();
        $this->makeProject($branch);
        $this->makeRecord($branch, $user);

        $html = $this->actingAs($user)->get(route('dana-talangan.index'))->getContent();

        $this->assertStringContainsString('x-ref="filterModalPanel"', $html);
        $this->assertStringContainsString('@keydown.tab="trapModalFocus($event, \'filterModalPanel\')"', $html);
        $this->assertStringContainsString('@keydown.escape.window="closeFilterModal()"', $html);
        $this->assertStringContainsString("openModal('filterModalPanel', \$refs.filterTrigger)", $html);
    }

    public function test_shared_scroll_lock_and_trigger_focus_restoration_contracts(): void
    {
        [$branch, $user] = $this->makeBranchAndUser();
        $this->makeProject($branch);
        $this->makeRecord($branch, $user);

        $html = $this->actingAs($user)->get(route('dana-talangan.index'))->getContent();
        $source = file_get_contents(resource_path('js/dana-talangan.js'));

        $this->assertStringContainsString('danaTalanganPage', $html);
        $this->assertStringContainsString('window.oasisBodyScroll?.lock(this.modalScrollOwner)', $source);
        $this->assertStringContainsString('window.oasisBodyScroll?.unlock(this.modalScrollOwner)', $source);
        $this->assertStringContainsString('modalTriggers', $source);
        $this->assertStringContainsString('trigger?.focus()', $source);
        $this->assertStringContainsString('firstFocusable(panel)', $source);
        $this->assertStringContainsString('if (!this.filterOpen) return;', $source);
        $this->assertStringContainsString('if (!this.adding) return;', $source);
        $this->assertSame(1, substr_count($source, 'openEdit(record, trigger)'));
        $this->assertSame(1, substr_count($source, 'async loadEditKavlings(preserve = false)'));
        $this->assertSame(1, substr_count($source, 'closeEditModal()'));
    }

    public function test_detail_fetch_failure_uses_oasis_feedback_without_native_alert(): void
    {
        [$branch, $user] = $this->makeBranchAndUser();
        $this->makeProject($branch);
        $this->makeRecord($branch, $user);

        $html = $this->actingAs($user)->get(route('dana-talangan.index'))->getContent();

        $this->assertStringNotContainsString("alert('Gagal memuat detail.')", $html);
        $this->assertStringContainsString("window.oasisToast?.('Gagal memuat detail. Silakan coba lagi.', 'error')", $html);
        $this->assertStringContainsString('x-show="error"', $html);
        $this->assertStringContainsString('Coba Lagi', $html);
        $this->assertStringContainsString('retry()', $html);
    }

    public function test_bulk_confirmation_modal_replaces_native_confirm(): void
    {
        [$branch, $user] = $this->makeBranchAndUser();
        $this->makeProject($branch);
        $this->makeRecord($branch, $user);

        $html = $this->actingAs($user)->get(route('dana-talangan.index'))->getContent();

        $this->assertStringContainsString("crmModal('bulk-confirm', false)", $html);
        $this->assertStringContainsString('id="bulk-confirm-message"', $html);
        $this->assertStringContainsString('id="bulk-confirm-ok"', $html);
        $this->assertStringContainsString('window.CrmBulk.confirmPending()', $html);
        $this->assertStringContainsString('window.CrmBulk.cancelConfirm()', $html);
        $this->assertStringContainsString('onclick="CrmBulk.destroy(', $html);

        $bulkSource = file_get_contents(resource_path('js/crm-bulk.js'));
        $this->assertStringNotContainsString('confirm(', $bulkSource);
        $this->assertStringContainsString('confirmModalName: \'bulk-confirm\'', $bulkSource);
        $this->assertStringContainsString('oasis:modal-open', $bulkSource);
        $this->assertStringContainsString('this.pendingConfirm = null;', $bulkSource);
    }

    public function test_bulk_update_endpoint_payload_unchanged(): void
    {
        [$branch, $user] = $this->makeBranchAndUser();
        $this->makeProject($branch);
        $record = $this->makeRecord($branch, $user);

        $googleService = Mockery::mock(DanaTalanganGoogleService::class);
        $googleService->shouldReceive('push')->once()->withArgs(fn ($pushed, $actorId) => $pushed->is($record) && $actorId === $user->id)->andReturnTrue();
        $this->app->instance(DanaTalanganGoogleService::class, $googleService);

        $this->actingAs($user)->post(route('dana-talangan.bulk-update'), [
            'selected_ids' => (string) $record->id,
            'new_status' => 'lunas',
        ])->assertRedirect();

        $this->assertDatabaseHas('dana_talangans', ['id' => $record->id, 'status' => 'lunas']);
    }

    public function test_bulk_destroy_endpoint_payload_unchanged(): void
    {
        [$branch, $user] = $this->makeBranchAndUser();
        $this->makeProject($branch);
        $record = $this->makeRecord($branch, $user);

        $googleService = Mockery::mock(DanaTalanganGoogleService::class);
        $googleService->shouldReceive('delete')->once()->andReturnUsing(function ($deletedRecord, $actorId) use ($record, $user) {
            $this->assertTrue($deletedRecord->is($record));
            $this->assertSame($user->id, $actorId);
            $deletedRecord->delete();

            return true;
        });
        $this->app->instance(DanaTalanganGoogleService::class, $googleService);

        $this->actingAs($user)->post(route('dana-talangan.bulk-destroy'), [
            'selected_ids' => (string) $record->id,
        ])->assertRedirect();

        $this->assertSoftDeleted('dana_talangans', ['id' => $record->id]);
    }

    public function test_unauthorized_roles_still_denied_index(): void
    {
        [$branch] = $this->makeBranchAndUser();
        $this->makeProject($branch);

        foreach (['sales', 'sales_coordinator'] as $roleSlug) {
            $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
            $user = User::factory()->create([
                'role_id' => $role->id,
                'branch_id' => $branch->id,
                'password_changed_at' => now(),
            ]);

            $this->actingAs($user)->get(route('dana-talangan.index'))->assertForbidden();
        }
    }

    public function test_bulk_endpoint_rejects_unauthorized_branch(): void
    {
        [$branch, $user] = $this->makeBranchAndUser();
        $otherBranch = Branch::create(['name' => 'Cabang Lain', 'code' => 'OTHER', 'is_active' => true]);
        $record = $this->makeRecord($otherBranch, $user);

        $this->actingAs($user)->post(route('dana-talangan.bulk-update'), [
            'selected_ids' => (string) $record->id,
            'new_status' => 'lunas',
        ])->assertForbidden();
    }
}
