<?php

namespace Tests\Feature;

use App\Imports\DanaTalanganImport;
use App\Models\Branch;
use App\Models\DanaTalangan;
use App\Models\LeadMaster;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\OptimisticLockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class DanaTalanganInteractionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeGoogleSheets();
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

    public function test_import_rejects_unknown_status_without_mutation(): void
    {
        [$branch, $user] = $this->makeBranchAndUser();
        $project = $this->makeProject($branch);
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([
            ['No', 'Tanggal', 'Nama Konsumen', 'Kav', 'Proyek', 'Pinjam Nama', 'Pekerjaan', 'Status Kawin', 'Umur', 'Marketing', 'TGL Komitmen', 'Penyelesaian', 'Konfirmasi', 'Status Cicilan'],
            [1, '2026-08-31', 'Status Tidak Valid', '', 'Proyek Test', 'TIDAK', '', '', '', '', '', '', 'TIDAK', 'unknown'],
        ]);
        $path = tempnam(sys_get_temp_dir(), 'dana-import-');
        (new Xlsx($spreadsheet))->save($path);

        $this->actingAs($user);
        $result = DanaTalanganImport::import($path, $branch->id, [], [$branch->id], [$project->id]);
        unlink($path);

        $this->assertSame(0, $result['imported']);
        $this->assertSame(["Baris 2: Status cicilan tidak valid ('unknown')."], $result['errors']);
        $this->assertDatabaseCount('dana_talangans', 0);
    }

    public function test_import_requires_exact_authorized_project_without_mutation(): void
    {
        [$branch, $user] = $this->makeBranchAndUser();
        $project = $this->makeProject($branch);
        $otherBranch = Branch::create(['name' => 'Cabang Lain Dana', 'code' => 'CLD', 'is_active' => true]);
        $otherProject = $this->makeProject($otherBranch, 'Proyek Lain');
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([
            ['No', 'Tanggal', 'Nama Konsumen', 'Kav', 'Proyek', 'Pinjam Nama', 'Pekerjaan', 'Status Kawin', 'Umur', 'Marketing', 'TGL Komitmen', 'Penyelesaian', 'Konfirmasi', 'Status Cicilan'],
            [1, '2026-08-31', 'Tanpa Proyek', '', '', 'TIDAK', '', '', '', '', '', '', 'TIDAK', 'sanggup'],
            [2, '2026-08-31', 'Proyek Asing', '', 'Proyek Tak Ada', 'TIDAK', '', '', '', '', '', '', 'TIDAK', 'sanggup'],
            [3, '2026-08-31', 'Proyek Cabang Lain', '', 'Proyek Lain', 'TIDAK', '', '', '', '', '', '', 'TIDAK', 'sanggup'],
            [4, '2026-08-31', 'Proyek Di Luar Cakupan', '', 'Proyek Test', 'TIDAK', '', '', '', '', '', '', 'TIDAK', 'sanggup'],
        ]);
        $path = tempnam(sys_get_temp_dir(), 'dana-import-');
        (new Xlsx($spreadsheet))->save($path);

        $this->actingAs($user);
        $result = DanaTalanganImport::import($path, $branch->id, [], [$branch->id], [$otherProject->id]);
        unlink($path);

        $this->assertSame(0, $result['imported']);
        $this->assertSame('Baris 2: Proyek wajib diisi.', $result['errors'][0]);
        $this->assertSame('Baris 3: Proyek harus cocok tepat dengan satu proyek aktif pada cabang.', $result['errors'][1]);
        $this->assertSame('Baris 4: Proyek harus cocok tepat dengan satu proyek aktif pada cabang.', $result['errors'][2]);
        $this->assertSame('Baris 5: Proyek tidak termasuk cakupan pengelolaan Anda.', $result['errors'][3]);
        $this->assertDatabaseCount('dana_talangans', 0);

        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([
            ['No', 'Tanggal', 'Nama Konsumen', 'Kav', 'Proyek', 'Pinjam Nama', 'Pekerjaan', 'Status Kawin', 'Umur', 'Marketing', 'TGL Komitmen', 'Penyelesaian', 'Konfirmasi', 'Status Cicilan'],
            [1, '2026-08-31', 'Proyek Diizinkan', '', 'proyek   test', 'YA', '', '', '', '', '', '', 'TIDAK', 'lunas'],
        ]);
        $path = tempnam(sys_get_temp_dir(), 'dana-import-');
        (new Xlsx($spreadsheet))->save($path);

        $result = DanaTalanganImport::import($path, $branch->id, [], [$branch->id], [$project->id]);
        unlink($path);

        $this->assertSame(1, $result['imported'], implode('; ', $result['errors']));
        $record = DanaTalangan::query()->sole();
        $this->assertSame($project->id, $record->project_id);
        $this->assertSame('Proyek Test', $record->project_name);
        $this->assertSame($branch->id, $record->branch_id);
    }

    public function test_import_validates_age_booleans_and_commitment_date_without_mutation(): void
    {
        [$branch, $user] = $this->makeBranchAndUser();
        $project = $this->makeProject($branch);
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([
            ['No', 'Tanggal', 'Nama Konsumen', 'Kav', 'Proyek', 'Pinjam Nama', 'Pekerjaan', 'Status Kawin', 'Umur', 'Marketing', 'TGL Komitmen', 'Penyelesaian', 'Konfirmasi', 'Status Cicilan'],
            [1, '2026-08-31', 'Umur Huruf', '', 'Proyek Test', 'TIDAK', '', '', 'abc', '', '', '', 'TIDAK', 'sanggup'],
            [2, '2026-08-31', 'Umur Terlalu Besar', '', 'Proyek Test', 'TIDAK', '', '', 200, '', '', '', 'TIDAK', 'sanggup'],
            [3, '2026-08-31', 'Pinjam Asing', '', 'Proyek Test', 'MUNGKIN', '', '', '', '', '', '', 'TIDAK', 'sanggup'],
            [4, '2026-08-31', 'Konfirmasi Asing', '', 'Proyek Test', 'TIDAK', '', '', '', '', '', '', 'MUNGKIN', 'sanggup'],
            [5, '2026-08-31', 'Komitmen Rusak', '', 'Proyek Test', 'TIDAK', '', '', '', '', 'segera', '', 'TIDAK', 'sanggup'],
        ]);
        $path = tempnam(sys_get_temp_dir(), 'dana-import-');
        (new Xlsx($spreadsheet))->save($path);

        $this->actingAs($user);
        $result = DanaTalanganImport::import($path, $branch->id, [], [$branch->id], [$project->id]);
        unlink($path);

        $this->assertSame(0, $result['imported']);
        $this->assertSame("Baris 2: Umur harus berupa angka ('abc').", $result['errors'][0]);
        $this->assertSame("Baris 3: Umur harus antara 0 dan 150 ('200').", $result['errors'][1]);
        $this->assertSame("Baris 4: Pinjam Nama hanya boleh YA atau TIDAK ('MUNGKIN').", $result['errors'][2]);
        $this->assertSame("Baris 5: Konfirmasi hanya boleh YA atau TIDAK ('MUNGKIN').", $result['errors'][3]);
        $this->assertSame("Baris 6: TGL Komitmen tidak valid ('segera').", $result['errors'][4]);
        $this->assertDatabaseCount('dana_talangans', 0);

        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([
            ['No', 'Tanggal', 'Nama Konsumen', 'Kav', 'Proyek', 'Pinjam Nama', 'Pekerjaan', 'Status Kawin', 'Umur', 'Marketing', 'TGL Komitmen', 'Penyelesaian', 'Konfirmasi', 'Status Cicilan'],
            [1, '2026-08-31', 'Baris Valid', '', 'Proyek Test', 'YA', '', '', 45, '', '2026-08-30', '', 'TIDAK', 'sanggup'],
        ]);
        $path = tempnam(sys_get_temp_dir(), 'dana-import-');
        (new Xlsx($spreadsheet))->save($path);

        $result = DanaTalanganImport::import($path, $branch->id, [], [$branch->id], [$project->id]);
        unlink($path);

        $this->assertSame(1, $result['imported'], implode('; ', $result['errors']));
        $record = DanaTalangan::query()->sole();
        $this->assertSame(45, $record->umur);
        $this->assertTrue($record->pinjam_nama);
        $this->assertFalse($record->konfirmasi_keuangan);
        $this->assertSame('2026-08-30', $record->tgl_komitmen->format('Y-m-d'));
    }

    public function test_import_rejects_decimal_and_scientific_age_without_mutation(): void
    {
        [$branch, $user] = $this->makeBranchAndUser();
        $project = $this->makeProject($branch);
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([
            ['No', 'Tanggal', 'Nama Konsumen', 'Kav', 'Proyek', 'Pinjam Nama', 'Pekerjaan', 'Status Kawin', 'Umur', 'Marketing', 'TGL Komitmen', 'Penyelesaian', 'Konfirmasi', 'Status Cicilan'],
            [1, '2026-08-31', 'Umur Desimal', '', 'Proyek Test', 'TIDAK', '', '', 45.9, '', '', '', 'TIDAK', 'sanggup'],
            [2, '2026-08-31', 'Umur Ilmiah', '', 'Proyek Test', 'TIDAK', '', '', '1e2', '', '', '', 'TIDAK', 'sanggup'],
        ]);
        $spreadsheet->getActiveSheet()->getCell('I3')->setValueExplicit('1e2', DataType::TYPE_STRING);
        $path = tempnam(sys_get_temp_dir(), 'dana-import-');
        (new Xlsx($spreadsheet))->save($path);

        $this->actingAs($user);
        $result = DanaTalanganImport::import($path, $branch->id, [], [$branch->id], [$project->id]);
        unlink($path);

        $this->assertSame(0, $result['imported']);
        $this->assertSame([
            "Baris 2: Umur harus berupa angka ('45.9').",
            "Baris 3: Umur harus berupa angka ('1e2').",
        ], $result['errors']);
        $this->assertDatabaseCount('dana_talangans', 0);
    }

    public function test_import_accepts_integer_and_blank_age(): void
    {
        [$branch, $user] = $this->makeBranchAndUser();
        $project = $this->makeProject($branch);
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([
            ['No', 'Tanggal', 'Nama Konsumen', 'Kav', 'Proyek', 'Pinjam Nama', 'Pekerjaan', 'Status Kawin', 'Umur', 'Marketing', 'TGL Komitmen', 'Penyelesaian', 'Konfirmasi', 'Status Cicilan'],
            [1, '2026-08-31', 'Umur Bulat', '', 'Proyek Test', 'TIDAK', '', '', 45, '', '', '', 'TIDAK', 'sanggup'],
            [2, '2026-08-31', 'Umur Kosong', '', 'Proyek Test', 'TIDAK', '', '', '', '', '', '', 'TIDAK', 'sanggup'],
        ]);
        $path = tempnam(sys_get_temp_dir(), 'dana-import-');
        (new Xlsx($spreadsheet))->save($path);

        $this->actingAs($user);
        $result = DanaTalanganImport::import($path, $branch->id, [], [$branch->id], [$project->id]);
        unlink($path);

        $this->assertSame(2, $result['imported'], implode('; ', $result['errors']));
        $this->assertSame([], $result['errors']);
        $this->assertSame(45, DanaTalangan::query()->where('nama_konsumen', 'Umur Bulat')->value('umur'));
        $this->assertNull(DanaTalangan::query()->where('nama_konsumen', 'Umur Kosong')->value('umur'));
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

    public function test_import_validation_changelog_is_idempotent_and_visible(): void
    {
        $title = 'Validasi Impor Lebih Ketat';
        $migration = require database_path('migrations/2026_09_10_000006_fix_import_validation_changelog.php');

        $migration->up();
        $migration->up();

        $this->assertSame(1, DB::table('changelogs')->whereNull('version')->where('title', $title)->count());
        [, $user] = $this->makeBranchAndUser();
        $this->actingAs($user)->get(route('changelogs.index'))->assertOk()->assertSeeText($title);
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

    public function test_assigned_scope_blocks_same_branch_other_project_dana_talangan_everywhere(): void
    {
        $branch = Branch::create(['name' => 'Assigned Dana Branch', 'code' => 'ADN', 'is_active' => true]);
        $assignedProject = $this->makeProject($branch, 'Assigned Dana Project');
        $otherProject = $this->makeProject($branch, 'Other Dana Project');
        $role = Role::create(['name' => 'Dana Assigned Test', 'slug' => 'dana_assigned_test', 'is_active' => true]);
        $role->permissions()->sync(Permission::whereIn('slug', [
            'bridge_fund.view', 'bridge_fund.manage', 'bridge_fund.export',
            'bridge_fund.view_assigned', 'bridge_fund.manage_assigned', 'bridge_fund.export_assigned',
        ])->pluck('id'));
        $user = User::factory()->create(['role_id' => $role->id, 'branch_id' => $branch->id, 'password_changed_at' => now()]);
        DB::table('project_user')->insert([
            'user_id' => $user->id, 'project_id' => $assignedProject->id, 'is_primary' => true,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $allowed = $this->makeRecord($branch, $user);
        $allowed->update(['project_id' => $assignedProject->id, 'project_name' => $assignedProject->project_name, 'nama_konsumen' => 'Dana Assigned Visible']);
        $blocked = $this->makeRecord($branch, $user);
        $blocked->update(['project_id' => $otherProject->id, 'project_name' => $otherProject->project_name, 'nama_konsumen' => 'Dana Other Hidden']);
        $historical = $this->makeRecord($branch, $user);
        $historical->update(['project_id' => null, 'nama_konsumen' => 'Dana Historical Hidden']);
        $this->actingAs($user)->get(route('dana-talangan.index', ['month_from' => '2026-07', 'month_to' => '2026-07', 'filter_mode' => 'month']))
            ->assertOk()->assertViewHas('records', fn ($records) => $records->pluck('id')->all() === [$allowed->id]);
        $this->actingAs($user)->getJson(route('dana-talangan.detail', $blocked))->assertForbidden();
        $response = $this->actingAs($user)->get(route('dana-talangan.export'))->assertOk();
        $sheet = IOFactory::load($response->baseResponse->getFile()->getPathname())->getActiveSheet();
        $names = array_column($sheet->rangeToArray('C2:C'.$sheet->getHighestRow()), 0);
        $this->assertContains($allowed->nama_konsumen, $names);
        $this->assertNotContains($blocked->nama_konsumen, $names);
        $this->actingAs($user)->put(route('dana-talangan.update', $blocked), [
            'tanggal' => '2026-07-07', 'nama_konsumen' => $blocked->nama_konsumen,
            'project_name' => $blocked->project_name, 'status' => 'sanggup',
            'expected_updated_at' => app(OptimisticLockService::class)->token($blocked),
        ])->assertForbidden();
    }
}
