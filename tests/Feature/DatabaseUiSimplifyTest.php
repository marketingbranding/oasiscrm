<?php

namespace Tests\Feature;

use App\Http\Controllers\Crm\DatabaseController;
use App\Models\Branch;
use App\Models\Changelog;
use App\Models\DatabaseSheetRecord;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\DatabaseFieldConfig;
use App\Services\GoogleSheetsApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class DatabaseUiSimplifyTest extends TestCase
{
    use RefreshDatabase;

    public function test_field_config_covers_all_eight_modules(): void
    {
        $config = DatabaseFieldConfig::config();
        $modules = array_keys(DatabaseController::SHEET_MODULES);

        foreach ($modules as $module) {
            $this->assertArrayHasKey($module, $config, "Missing config for module: {$module}");
            $this->assertNotEmpty($config[$module]['table'], "Missing table columns for: {$module}");
            $this->assertNotEmpty($config[$module]['form'], "Missing form fields for: {$module}");
            $this->assertArrayHasKey('labels', $config[$module], "Missing labels for: {$module}");
        }
    }

    public function test_data_konsumen_table_shows_configured_columns_not_every_header(): void
    {
        [$branch] = $this->setupSheet('data_konsumen', [
            'id_kavling', 'no_ktp', 'nama_konsumen', 'tanggal_lahir', 'pekerjaan',
            'umur', 'proses_terakhir', 'status_kelengkapan', 'keterangan',
            'oasis_sync_id',
        ]);

        $user = $this->pusatUser($branch);
        $this->mockSheetTitles($branch, ['data_konsumen']);

        $html = $this->actingAs($user)
            ->get(route('database.index', ['branch_id' => $branch->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('tableHeaders(name)', $html);
        $this->assertStringContainsString('fieldLabel(name, h)', $html);
        $this->assertStringContainsString('formatCell(name, h, rec.row_data[h])', $html);
    }

    public function test_technical_and_formula_columns_hidden_from_form(): void
    {
        $config = DatabaseFieldConfig::config();

        $dkHidden = $config['data_konsumen']['hidden_form'] ?? [];
        $this->assertContains('umur', $dkHidden);
        $this->assertContains('proses_terakhir', $dkHidden);
        $this->assertContains('status_terakhir', $dkHidden);
        $this->assertContains('id_kons', $dkHidden);

        $ppjbHidden = $config['ppjb_dev']['hidden_form'] ?? [];
        $this->assertContains('id_ppjb_dev', $ppjbHidden);

        $bastHidden = $config['bast']['hidden_form'] ?? [];
        $this->assertContains('no_bast', $bastHidden);

        $akadHidden = $config['akad']['hidden_form'] ?? [];
        $this->assertContains('no_ppjb_akad', $akadHidden);

        $pembHidden = $config['pemberkasan']['hidden_form'] ?? [];
        $this->assertContains('id_berkas', $pembHidden);
    }

    public function test_friendly_labels_rendered_in_view_source(): void
    {
        $html = file_get_contents(resource_path('views/crm/database/index.blade.php'));

        $this->assertStringContainsString('fieldLabel(name, h)', $html);
        $this->assertStringContainsString('prettifyLabel', $html);
        $this->assertStringContainsString('sheetLabel(name)', $html);
    }

    public function test_friendly_labels_in_config_match_expected_indonesian(): void
    {
        $config = DatabaseFieldConfig::config();
        $labels = $config['data_konsumen']['labels'];

        $this->assertSame('NIK', $labels['no_ktp']);
        $this->assertSame('Nama Konsumen', $labels['nama_konsumen']);
        $this->assertSame('Tanggal Lahir', $labels['tanggal_lahir']);
        $this->assertSame('No. HP', $labels['no_hp']);
        $this->assertSame('Nama Kontak Darurat', $labels['nama_kondar']);
        $this->assertSame('No. HP Kontak Darurat', $labels['no_hp_kondar']);

        $biLabels = $config['bi_checking']['labels'];
        $this->assertSame('Tanggal SLIK', $biLabels['tanggal_slik']);
        $this->assertSame('Hasil SLIK', $biLabels['hasil_slik']);

        $akadLabels = $config['akad']['labels'];
        $this->assertSame('Status DP Konsumen', $akadLabels['status_dp_konsumen']);

        $bastLabels = $config['bast']['labels'];
        $this->assertSame('Tanggal BAST', $bastLabels['tanggal_bast']);
    }

    public function test_money_and_date_formatting_functions_exist_in_view(): void
    {
        $html = file_get_contents(resource_path('views/crm/database/index.blade.php'));

        $this->assertStringContainsString('formatMoney(value)', $html);
        $this->assertStringContainsString("toLocaleString('id-ID')", $html);
        $this->assertStringContainsString('formatDate(value)', $html);
        $this->assertStringContainsString("Rp '", $html);
        $this->assertStringContainsString("d + '/' + m + '/' + y", $html);
    }

    public function test_money_fields_configured_for_psjb_and_proses_bank(): void
    {
        $config = DatabaseFieldConfig::config();

        $psjbMoney = $config['PSJB']['money'];
        $this->assertContains('harga_unit', $psjbMoney);
        $this->assertContains('utj', $psjbMoney);
        $this->assertContains('dp_all_in', $psjbMoney);
        $this->assertContains('nominal_cicilan', $psjbMoney);
        $this->assertContains('harga_klt_total', $psjbMoney);

        $pbMoney = $config['proses_bank']['money'];
        $this->assertContains('approved_plafond', $pbMoney);

        $pembMoney = $config['pemberkasan']['money'];
        $this->assertContains('request_plafond', $pembMoney);
    }

    public function test_bast_form_remains_simple(): void
    {
        $config = DatabaseFieldConfig::config();
        $bastForm = $config['bast']['form'];

        $this->assertSame(['id_kavling', 'tanggal_bast'], $bastForm);
        $this->assertSame(['id_kavling', 'nama_konsumen', 'tanggal_bast'], $config['bast']['table']);
    }

    public function test_full_width_fields_configured(): void
    {
        $config = DatabaseFieldConfig::config();

        $this->assertContains('alamat', $config['data_konsumen']['full_width']);
        $this->assertContains('keterangan', $config['data_konsumen']['full_width']);
        $this->assertContains('detail_revisi', $config['proses_bank']['full_width']);
        $this->assertContains('kendala', $config['proses_bank']['full_width']);
        $this->assertContains('keterangan_terlambat', $config['akad']['full_width']);
    }

    public function test_create_edit_import_actions_remain_available(): void
    {
        [$branch] = $this->setupSheet('data_konsumen', ['id_kavling', 'nama_konsumen']);
        $user = $this->pusatUser($branch);
        $this->mockSheetTitles($branch, ['data_konsumen']);

        $html = $this->actingAs($user)
            ->get(route('database.index', ['branch_id' => $branch->id]))
            ->assertOk()
            ->assertViewHas('canEdit', true)
            ->getContent();

        $this->assertStringContainsString('Tambah Data', $html);
        $this->assertStringContainsString('Import Copas', $html);
        $this->assertStringContainsString('editRecord(rec, $el)', $html);
        $this->assertStringContainsString('database-edit-form', $html);
        $this->assertStringContainsString('database-add-form', $html);
        $this->assertStringContainsString('database-import-form', $html);
    }

    public function test_authorization_unchanged_for_read_only_user(): void
    {
        [$branch] = $this->setupSheet('data_konsumen', ['id_kavling', 'nama_konsumen']);
        $role = Role::query()->create([
            'name' => 'DB Viewer Simple',
            'slug' => 'db_viewer_simple',
            'is_superadmin' => false,
            'is_active' => true,
        ]);
        $role->permissions()->sync(Permission::query()->whereIn('slug', ['database.view', 'database.view_branch'])->pluck('id'));
        $user = User::factory()->create(['role_id' => $role->id, 'branch_id' => $branch->id, 'password_changed_at' => now()]);
        $user->branches()->syncWithoutDetaching([$branch->id => ['can_view' => true]]);
        $this->mockSheetTitles($branch, ['data_konsumen']);

        $this->actingAs($user)
            ->get(route('database.index', ['branch_id' => $branch->id]))
            ->assertOk()
            ->assertViewHas('canEdit', false);
    }

    public function test_import_helper_text_function_exists(): void
    {
        $html = file_get_contents(resource_path('views/crm/database/index.blade.php'));

        $this->assertStringContainsString('importHelperText(importing)', $html);
        $this->assertStringContainsString('database-import-headers', $html);
    }

    public function test_empty_loading_error_states_use_module_labels(): void
    {
        $html = file_get_contents(resource_path('views/crm/database/index.blade.php'));

        $this->assertStringContainsString("'Belum ada data ' + sheetLabel(name)", $html);
        $this->assertStringContainsString("'Memuat data ' + sheetLabel(name)", $html);
        $this->assertStringContainsString("'Gagal memuat data ' + sheetLabel(name)", $html);
        $this->assertStringContainsString('Data gagal dimuat.', $html);
    }

    public function test_field_config_passed_to_view_and_alpine_config(): void
    {
        [$branch] = $this->setupSheet('data_konsumen', ['id_kavling', 'nama_konsumen']);
        $user = $this->pusatUser($branch);
        $this->mockSheetTitles($branch, ['data_konsumen']);

        $response = $this->actingAs($user)
            ->get(route('database.index', ['branch_id' => $branch->id]))
            ->assertOk()
            ->assertViewHas('fieldConfig');

        $fieldConfig = $response->viewData('fieldConfig');
        $this->assertArrayHasKey('data_konsumen', $fieldConfig);
        $this->assertArrayHasKey('bast', $fieldConfig);

        $html = $response->getContent();
        $this->assertStringContainsString('fieldConfig', $html);
        $this->assertStringContainsString('sheetLabels', $html);
    }

    public function test_changelog_is_idempotent_and_visible(): void
    {
        $title = 'Tampilan Database Lebih Sederhana dan Ringkas';
        $migration = require database_path('migrations/2026_08_23_000002_add_database_ui_simplify_changelog.php');
        $migration->up();
        $migration->up();
        $superadmin = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'superadmin')->firstOrFail()->id,
            'password_changed_at' => now(),
        ]);

        $this->assertSame(1, Changelog::query()->whereNull('version')->where('title', $title)->count());
        $this->actingAs($superadmin)->get(route('changelogs.index'))->assertOk()->assertSee($title);
    }

    public function test_module_config_lookup_uses_exact_key_not_normalized_key(): void
    {
        $html = file_get_contents(resource_path('views/crm/database/index.blade.php'));

        $this->assertStringNotContainsString('this.fieldConfig[this.normalizeKey(name)]', $html, 'moduleConfig must not use normalizeKey for config key lookup — it breaks underscore keys like data_konsumen');
        $this->assertStringContainsString('this.fieldConfig[name]', $html, 'moduleConfig must use exact name lookup first');
    }

    public function test_sort_label_uses_friendly_label_not_raw_header(): void
    {
        $html = file_get_contents(resource_path('views/crm/database/index.blade.php'));

        $this->assertStringContainsString('sortLabel(name, h)', $html, 'sortLabel must receive sheet name to produce friendly label');
        $this->assertStringContainsString('this.fieldLabel(name, header)', $html, 'sortLabel must use fieldLabel for aria-label text');
        $this->assertStringNotContainsString('sortLabel(h)', $html, 'sortLabel must not be called with only raw header');
    }

    public function test_import_preview_headers_use_friendly_labels(): void
    {
        $html = file_get_contents(resource_path('views/crm/database/index.blade.php'));

        $this->assertStringContainsString('fieldLabel(importing, h)', $html, 'Import preview headers must use fieldLabel instead of raw header');
    }

    public function test_format_money_handles_indonesian_thousand_separators(): void
    {
        $html = file_get_contents(resource_path('views/crm/database/index.blade.php'));

        $this->assertStringContainsString("replace(/\\./g, '')", $html, 'formatMoney must remove Indonesian thousand separator dots before parsing');
        $this->assertStringNotContainsString('/[^\\d.-]/g', $html, 'formatMoney must not use the old regex that fails on Indonesian-formatted numbers');
    }

    private function setupSheet(string $sheetName, array $headers): array
    {
        $branch = Branch::query()->create([
            'name' => 'Magelang',
            'code' => 'MGL',
            'is_active' => true,
            'sheet_id' => 'spreadsheet-id',
        ]);
        DatabaseSheetRecord::query()->create([
            'branch_id' => $branch->id,
            'sheet_id' => $branch->sheet_id,
            'sheet_name' => $sheetName,
            'row_number' => 2,
            'headers' => $headers,
            'row_data' => array_fill_keys($headers, ''),
            'formula_columns' => [],
            'column_metadata' => [],
        ]);

        return [$branch];
    }

    private function pusatUser(Branch $branch): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', 'pusat')->firstOrFail()->id,
            'branch_id' => $branch->id,
            'password_changed_at' => now(),
        ]);
    }

    private function mockSheetTitles(Branch $branch, array $titles): void
    {
        $google = Mockery::mock(GoogleSheetsApiService::class);
        $google->shouldReceive('sheetTitles')->once()->with($branch->sheet_id)->andReturn($titles);
        $this->app->instance(GoogleSheetsApiService::class, $google);
    }
}
