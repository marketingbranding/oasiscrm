<?php

namespace Tests\Feature;

use App\Http\Controllers\Crm\DatabaseController;
use App\Models\Branch;
use App\Models\Changelog;
use App\Models\DatabaseSheetRecord;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\GoogleSheetsApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class DatabaseUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_renders_canonical_header_scope_toolbar_and_authorized_write_actions(): void
    {
        [$branch, $record] = $this->databaseRecord();
        $user = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'pusat')->firstOrFail()->id,
            'branch_id' => $branch->id,
            'password_changed_at' => now(),
        ]);
        $this->mockSheetTitles($branch);

        $response = $this->actingAs($user)->get(route('database.index', ['branch_id' => $branch->id]))
            ->assertOk()
            ->assertViewHas('canEdit', true);

        $html = $response->getContent();
        $this->assertStringContainsString('crm-page-header', $html);
        $this->assertStringContainsString('database-page-header', $html);
        $this->assertStringContainsString('aria-label="Ruang kerja Database"', $html);
        $this->assertStringContainsString('Cabang: Magelang', $html);
        $this->assertStringContainsString('8 sheet Proses Penjualan', $html);
        $this->assertStringContainsString('aria-label="Submodule Database"', $html);
        $this->assertStringContainsString('aria-current="page">Proses Penjualan', $html);
        $this->assertStringContainsString('Ringkasan jumlah konsumen berdasarkan posisi proses terkini.', $html);
        $this->assertStringContainsString('aria-label="Tahap Proses Penjualan"', $html);
        $this->assertStringNotContainsString('Sales / Database', $html);
        $this->assertStringNotContainsString('"Leads"', $html);
        $this->assertStringNotContainsString('"data_kav"', $html);
        $tabLabels = ['Data Konsumen', 'BI Checking', 'PSJB', 'Pemberkasan', 'Proses Bank', 'PPJB Developer', 'Akad', 'BAST'];
        $position = -1;
        foreach ($tabLabels as $label) {
            $next = strpos($html, $label, $position + 1);
            $this->assertNotFalse($next, "Missing Database tab {$label}");
            $this->assertGreaterThan($position, $next);
            $position = $next;
        }
        $this->assertStringContainsString('id="database-sync-state-title"', $html);
        $this->assertStringContainsString('Data yang sudah tersedia tetap dapat digunakan ketika pembaruan gagal.', $html);
        $this->assertSame(1, substr_count($html, 'x-data="crmSyncStatus('));
        $this->assertStringContainsString('Tabel belum diperbarui', $html);
        $this->assertStringContainsString('aria-label="Pencarian dan tindakan sheet"', $html);
        $this->assertStringContainsString('Cari konsumen', $html);
        $this->assertStringContainsString('Nama, kontak, kavling, atau data lain...', $html);
        $this->assertStringContainsString('Pencarian: ', $html);
        $this->assertStringContainsString('Tidak ada konsumen yang cocok', $html);
        $this->assertStringContainsString('role="tablist"', $html, 'Missing tab list semantics.');
        $this->assertStringContainsString('role="tabpanel"', $html, 'Missing tab panel semantics.');
        $this->assertStringContainsString(':aria-sort="sortAria(h)"', $html, 'Missing active sort semantics.');
        $this->assertStringContainsString('class="database-sort-button"', $html, 'Missing keyboard sort control.');
        $this->assertStringContainsString('Bekukan ID Kavling', $html, 'Missing named freeze control.');
        $this->assertStringContainsString('freezeEligible(name)', $html, 'Freeze control must require ID Kavling as first visible data column.');
        $this->assertStringContainsString('effectiveFrozen(name)', $html, 'Sticky classes must use effective eligibility.');
        $this->assertStringNotContainsString('database-freeze-toggle', $html, 'Freeze icon must not remain in table header.');
        $this->assertStringContainsString('<caption class="sr-only"', $html, 'Missing table caption.');
        $this->assertStringContainsString('crm-row-num', $html, 'Missing canonical row-number column.');
        $this->assertStringContainsString('id="crm-modal-database-edit-title"', $html);
        $this->assertStringContainsString('id="crm-modal-database-add-title"', $html);
        $this->assertStringContainsString('data-database-edit-form', $html);
        $this->assertStringContainsString('fieldLabelId(', $html);
        $this->assertStringContainsString('@oasis:modal-closed.window', $html);
        $this->assertStringContainsString('@oasis-form-error.window', $html);
        $this->assertMatchesRegularExpression('/<th[^>]*>Aksi<\/th>/', $html);
        $this->assertStringContainsString('editRecord(rec, $el)', $html);
        $this->assertStringContainsString('database/records/'.$record->id, route('database.records.update', $record));
    }

    public function test_database_hides_write_actions_from_view_only_user(): void
    {
        [$branch] = $this->databaseRecord();
        $role = Role::query()->create([
            'name' => 'Database Viewer',
            'slug' => 'database_viewer',
            'is_superadmin' => false,
            'is_active' => true,
        ]);
        $role->permissions()->sync(Permission::query()
            ->whereIn('slug', ['database.view', 'database.view_all'])
            ->pluck('id'));
        $user = User::factory()->create([
            'role_id' => $role->id,
            'branch_id' => $branch->id,
            'password_changed_at' => now(),
        ]);
        $this->mockSheetTitles($branch);

        $response = $this->actingAs($user)->get(route('database.index', ['branch_id' => $branch->id]))
            ->assertOk()
            ->assertViewHas('canEdit', false);

        $html = $response->getContent();
        $this->assertDoesNotMatchRegularExpression('/<th[^>]*>Aksi<\/th>/', $html);
        $this->assertStringNotContainsString('>Edit</button>', $html);
        $this->assertStringNotContainsString('>Hapus</button>', $html);
    }

    public function test_database_write_actions_require_manage_scope_and_branch_edit_right(): void
    {
        [$branch] = $this->databaseRecord();
        $viewerRole = $this->roleWithPermissions('database_scoped_viewer', [
            'database.view', 'database.edit', 'database.view_branch',
        ]);
        $viewer = User::factory()->create([
            'role_id' => $viewerRole->id,
            'branch_id' => $branch->id,
            'password_changed_at' => now(),
        ]);
        $viewer->branches()->syncWithoutDetaching([$branch->id => ['can_view' => true, 'can_edit' => true]]);

        $operatorRole = $this->roleWithPermissions('database_scoped_operator', [
            'database.view', 'database.edit', 'database.view_branch', 'database.manage_branch',
        ]);
        $operator = User::factory()->create([
            'role_id' => $operatorRole->id,
            'branch_id' => $branch->id,
            'password_changed_at' => now(),
        ]);
        $operator->branches()->syncWithoutDetaching([$branch->id => ['can_view' => true, 'can_edit' => false]]);

        $google = Mockery::mock(GoogleSheetsApiService::class);
        $google->shouldNotReceive('sheetTitles');
        $this->app->instance(GoogleSheetsApiService::class, $google);

        $this->actingAs($viewer)->get(route('database.index', ['branch_id' => $branch->id]))
            ->assertOk()->assertViewHas('canEdit', false);
        $this->actingAs($operator)->get(route('database.index', ['branch_id' => $branch->id]))
            ->assertOk()->assertViewHas('canEdit', false);

        $operator->branches()->updateExistingPivot($branch->id, ['can_edit' => true]);
        $this->actingAs($operator)->get(route('database.index', ['branch_id' => $branch->id]))
            ->assertOk()->assertViewHas('canEdit', true);
    }

    public function test_database_deep_links_require_exact_canonical_sheet(): void
    {
        [$branch] = $this->databaseRecord();
        $user = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'pusat')->firstOrFail()->id,
            'branch_id' => $branch->id,
            'password_changed_at' => now(),
        ]);

        $this->actingAs($user)->get(route('database.index', [
            'branch_id' => $branch->id,
            'sheet' => 'lead',
            'add' => 1,
        ]))->assertOk()
            ->assertViewHas('requestSheet', null)
            ->assertViewHas('requestAdd', false);

        $response = $this->actingAs($user)->get(route('database.index', [
            'branch_id' => $branch->id,
            'sheet' => 'PSJB',
            'add' => 1,
        ]))->assertOk()
            ->assertViewHas('requestSheet', 'PSJB')
            ->assertViewHas('requestAdd', true);

        $html = $response->getContent();
        $this->assertStringContainsString('databaseTabs(', $html);
        $this->assertStringContainsString('switchTabWithAdd(match, config.requestAdd)', $html);
        $this->assertStringContainsString('this.openAdd(this.tab)', $html);
    }

    public function test_database_index_only_hydrates_records_for_the_initial_sheet(): void
    {
        [$branch] = $this->databaseRecord();
        DatabaseSheetRecord::query()->create([
            'branch_id' => $branch->id,
            'sheet_id' => $branch->sheet_id,
            'sheet_name' => 'Archive',
            'row_number' => 2,
            'headers' => ['id_kavling'],
            'row_data' => ['id_kavling' => 'OLD-01'],
            'formula_columns' => [],
            'column_metadata' => [],
        ]);
        $user = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'pusat')->firstOrFail()->id,
            'branch_id' => $branch->id,
            'password_changed_at' => now(),
        ]);
        $google = Mockery::mock(GoogleSheetsApiService::class);
        $google->shouldNotReceive('sheetTitles');
        $this->app->instance(GoogleSheetsApiService::class, $google);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->actingAs($user)->get(route('database.index', ['branch_id' => $branch->id]))
            ->assertOk();

        $recordQueries = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $query) => str_contains($query, 'database_sheet_records'))
            ->values();

        $this->assertCount(2, $recordQueries);
        $this->assertTrue($recordQueries->contains(fn (string $query) => str_contains($query, '"sheet_name" = ?')));
        $this->assertTrue($recordQueries->contains(fn (string $query) => str_contains($query, 'row_data') && str_contains($query, 'sheet_name')));
        $this->assertSame(array_keys(DatabaseController::SHEET_MODULES), $response->viewData('sheetNames'));
        $this->assertCount(1, $response->viewData('records')['data_konsumen']);
        $this->assertSame([], $response->viewData('records')['PSJB']);
        $this->assertArrayNotHasKey('Archive', $response->viewData('records'));
    }

    public function test_database_client_query_state_and_responsive_contract_remain_local(): void
    {
        $view = file_get_contents(resource_path('views/crm/database/index.blade.php'));
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('filterText: \'\'', $view);
        $this->assertStringContainsString('sortColumn: null', $view);
        $this->assertStringContainsString('let records = [...data.records]', $view);
        $this->assertStringContainsString('visibleHeaders.some', $view);
        $this->assertStringContainsString('this.cache[sheet] = data', $view);
        $this->assertStringNotContainsString('this.filterText = \'\';\n                this.cache[sheet]', $view);
        $this->assertStringContainsString('@media (max-width: 767px)', $css);
        $this->assertStringContainsString('.database-sheet-toolbar .crm-toolbar-actions', $css);
        $this->assertStringContainsString('.crm-table-scroll { max-width: 100%; max-height: 60dvh; }', $css);
    }

    public function test_database_2_changelog_is_idempotent_and_visible(): void
    {
        $title = 'Ruang kerja Database diperbarui';
        $migration = require database_path('migrations/2026_07_30_000001_add_database_2_changelog.php');
        $migration->up();
        $migration->up();
        $superadmin = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'superadmin')->firstOrFail()->id,
            'password_changed_at' => now(),
        ]);

        $this->assertSame(1, Changelog::query()->whereNull('version')->where('title', $title)->count());
        $this->actingAs($superadmin)->get(route('changelogs.index'))->assertOk()->assertSee($title);
    }

    public function test_database_sales_process_focus_changelog_is_idempotent_and_visible(): void
    {
        $title = 'Database Kini Berfokus pada Proses Penjualan';
        $migration = require database_path('migrations/2026_08_24_000011_focus_database_sales_process_changelog.php');
        $migration->up();
        $migration->up();
        $superadmin = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'superadmin')->firstOrFail()->id,
            'password_changed_at' => now(),
        ]);

        $this->assertSame(1, Changelog::query()->whereNull('version')->where('title', $title)->count());
        $this->actingAs($superadmin)->get(route('changelogs.index'))->assertOk()->assertSee($title);
    }

    public function test_database_memory_fix_changelog_is_idempotent_and_visible(): void
    {
        $title = 'Database Cabang Besar Lebih Stabil';
        $migration = require database_path('migrations/2026_08_03_000003_add_database_memory_fix_changelog.php');
        $migration->up();
        $migration->up();
        $superadmin = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'superadmin')->firstOrFail()->id,
            'password_changed_at' => now(),
        ]);

        $this->assertSame(1, Changelog::query()->whereNull('version')->where('title', $title)->count());
        $this->actingAs($superadmin)->get(route('changelogs.index'))->assertOk()->assertSee($title);
    }

    private function databaseRecord(): array
    {
        $branch = Branch::query()->create([
            'name' => 'Magelang',
            'code' => 'MGL',
            'is_active' => true,
            'sheet_id' => 'spreadsheet-id',
        ]);
        $record = DatabaseSheetRecord::query()->create([
            'branch_id' => $branch->id,
            'sheet_id' => $branch->sheet_id,
            'sheet_name' => 'data_konsumen',
            'row_number' => 2,
            'headers' => ['id_kavling', 'nama_konsumen'],
            'row_data' => ['id_kavling' => 'A-01', 'nama_konsumen' => 'Siti'],
            'formula_columns' => [],
            'column_metadata' => [],
        ]);

        return [$branch, $record];
    }

    private function mockSheetTitles(Branch $branch): void
    {
        $google = Mockery::mock(GoogleSheetsApiService::class);
        $google->shouldNotReceive('sheetTitles');
        $this->app->instance(GoogleSheetsApiService::class, $google);
    }

    private function roleWithPermissions(string $slug, array $permissionSlugs): Role
    {
        $role = Role::query()->create([
            'name' => str($slug)->replace('_', ' ')->title()->toString(),
            'slug' => $slug,
            'is_superadmin' => false,
            'is_active' => true,
        ]);
        $role->permissions()->sync(Permission::query()->whereIn('slug', $permissionSlugs)->pluck('id'));

        return $role;
    }
}
