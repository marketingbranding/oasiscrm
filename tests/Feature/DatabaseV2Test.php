<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Changelog;
use App\Models\DatabaseV2\Bast;
use App\Models\DatabaseV2\DataKonsumen;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseV2Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::resetRegisteredSlugs();
    }

    public function test_index_renders_with_modules_and_branch(): void
    {
        [$branch, $user] = $this->setupBranchAndUser();
        $html = $this->actingAs($user)
            ->get(route('database-v2.index', ['branch_id' => $branch->id]))
            ->assertOk()
            ->assertViewHas('canEdit', true)
            ->getContent();

        $this->assertStringContainsString('Database V2', $html);
        $this->assertStringContainsString('Data Konsumen', $html);
        $this->assertStringContainsString('BAST', $html);
        $this->assertStringContainsString('databaseV2(', $html);
    }

    public function test_list_returns_records_for_active_branch(): void
    {
        [$branch, $user] = $this->setupBranchAndUser();
        DataKonsumen::create(['branch_id' => $branch->id, 'id_kavling' => 'A01', 'no_ktp' => '3374', 'nama_konsumen' => 'Budi', 'created_by' => $user->id, 'updated_by' => $user->id]);
        DataKonsumen::create(['branch_id' => $branch->id, 'id_kavling' => 'A02', 'no_ktp' => '3375', 'nama_konsumen' => 'Siti', 'created_by' => $user->id, 'updated_by' => $user->id]);

        $response = $this->actingAs($user)
            ->getJson(route('database-v2.list', ['module' => 'data_konsumen', 'branch_id' => $branch->id]))
            ->assertOk();

        $this->assertCount(2, $response->json('records'));
        $this->assertSame(2, $response->json('total'));
    }

    public function test_list_search_filters_by_name(): void
    {
        [$branch, $user] = $this->setupBranchAndUser();
        DataKonsumen::create(['branch_id' => $branch->id, 'nama_konsumen' => 'Budi', 'created_by' => $user->id, 'updated_by' => $user->id]);
        DataKonsumen::create(['branch_id' => $branch->id, 'nama_konsumen' => 'Siti', 'created_by' => $user->id, 'updated_by' => $user->id]);

        $response = $this->actingAs($user)
            ->getJson(route('database-v2.list', ['module' => 'data_konsumen', 'branch_id' => $branch->id, 'search' => 'Budi']))
            ->assertOk();

        $this->assertCount(1, $response->json('records'));
        $this->assertSame('Budi', $response->json('records.0.nama_konsumen'));
    }

    public function test_store_creates_record(): void
    {
        [$branch, $user] = $this->setupBranchAndUser();

        $this->actingAs($user)
            ->postJson(route('database-v2.store', ['module' => 'data_konsumen']), [
                'branch_id' => $branch->id,
                'id_kavling' => 'A01',
                'no_ktp' => '3374',
                'nama_konsumen' => 'Budi',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseHas('db_v2_data_konsumen', ['id_kavling' => 'A01', 'no_ktp' => '3374', 'nama_konsumen' => 'Budi']);
    }

    public function test_update_modifies_record(): void
    {
        [$branch, $user] = $this->setupBranchAndUser();
        $record = DataKonsumen::create(['branch_id' => $branch->id, 'nama_konsumen' => 'Budi', 'created_by' => $user->id, 'updated_by' => $user->id]);

        $this->actingAs($user)
            ->postJson(route('database-v2.update', ['module' => 'data_konsumen', 'id' => $record->id]), [
                'branch_id' => $branch->id,
                'nama_konsumen' => 'Budi Santoso',
                '_method' => 'PUT',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertSame('Budi Santoso', $record->fresh()->nama_konsumen);
    }

    public function test_destroy_archives_record(): void
    {
        [$branch, $user] = $this->setupBranchAndUser();
        $record = DataKonsumen::create(['branch_id' => $branch->id, 'nama_konsumen' => 'Budi', 'created_by' => $user->id, 'updated_by' => $user->id]);

        $this->actingAs($user)
            ->deleteJson(route('database-v2.destroy', ['module' => 'data_konsumen', 'id' => $record->id]), ['branch_id' => $branch->id])
            ->assertOk();

        $this->assertSoftDeleted('db_v2_data_konsumen', ['id' => $record->id]);
    }

    public function test_branch_isolation_prevents_cross_branch_access(): void
    {
        [$branch1, $user] = $this->setupBranchAndUser('MGL', 'MGL');
        $branch2 = Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true, 'sheet_id' => 'sheet-solo']);
        DataKonsumen::create(['branch_id' => $branch2->id, 'nama_konsumen' => 'Other', 'created_by' => $user->id, 'updated_by' => $user->id]);

        $response = $this->actingAs($user)
            ->getJson(route('database-v2.list', ['module' => 'data_konsumen', 'branch_id' => $branch1->id]))
            ->assertOk();

        $this->assertCount(0, $response->json('records'));
        $this->assertSame(0, $response->json('total'));
    }

    public function test_read_only_user_cannot_store(): void
    {
        [$branch, $user] = $this->setupBranchAndUser(readOnly: true);

        $this->actingAs($user)
            ->postJson(route('database-v2.store', ['module' => 'data_konsumen']), [
                'branch_id' => $branch->id,
                'nama_konsumen' => 'Budi',
            ])
            ->assertForbidden();
    }

    public function test_import_preview_parses_tsv(): void
    {
        [$branch, $user] = $this->setupBranchAndUser();

        $response = $this->actingAs($user)
            ->postJson(route('database-v2.import.preview', ['module' => 'data_konsumen']), [
                'raw' => "id_kavling\tno_ktp\tnama_konsumen\nA01\t3374\tBudi\nA02\t3375\tSiti",
            ])
            ->assertOk();

        $this->assertSame(2, $response->json('valid_count'));
        $this->assertSame(0, $response->json('invalid_count'));
    }

    public function test_import_preview_rejects_unknown_header(): void
    {
        [$branch, $user] = $this->setupBranchAndUser();

        $this->actingAs($user)
            ->postJson(route('database-v2.import.preview', ['module' => 'data_konsumen']), [
                'raw' => "id_kavling\tunknown_col\nA01\tx",
            ])
            ->assertOk()
            ->assertJsonPath('error', 'Kolom tidak dikenal: unknown_col');
    }

    public function test_import_preview_rejects_over_1000_rows(): void
    {
        [$branch, $user] = $this->setupBranchAndUser();
        $lines = ["id_kavling\tno_ktp\tnama_konsumen"];
        for ($i = 0; $i < 1001; $i++) {
            $lines[] = "A{$i}\tNIK{$i}\tNama{$i}";
        }

        $this->actingAs($user)
            ->postJson(route('database-v2.import.preview', ['module' => 'data_konsumen']), [
                'raw' => implode("\n", $lines),
            ])
            ->assertOk()
            ->assertJsonPath('error', 'Maksimal 1000 baris per import.');
    }

    public function test_import_save_blocks_when_invalid_rows_exist(): void
    {
        [$branch, $user] = $this->setupBranchAndUser();

        $response = $this->actingAs($user)
            ->postJson(route('database-v2.import.save', ['module' => 'data_konsumen']), [
                'raw' => "id_kavling\tno_ktp\tnama_konsumen\ttanggal_lahir\nA01\t3374\tBudi\t15/07/1990\nA02\t3375\tSiti\tbad-date",
            ])
            ->assertOk();

        $json = $response->json();
        $this->assertSame(0, $json['saved']);
        $this->assertStringContainsString('diblok', $json['message']);
    }

    public function test_import_save_valid_only_inserts_valid_rows(): void
    {
        [$branch, $user] = $this->setupBranchAndUser();

        $this->actingAs($user)
            ->postJson(route('database-v2.import.save', ['module' => 'data_konsumen']), [
                'raw' => "id_kavling\tno_ktp\tnama_konsumen\ttanggal_lahir\nA01\t3374\tBudi\t15/07/1990\nA02\t3375\tSiti\tbad-date",
                'valid_only' => true,
            ])
            ->assertOk()
            ->assertJsonPath('saved', 1);

        $this->assertDatabaseHas('db_v2_data_konsumen', ['id_kavling' => 'A01', 'no_ktp' => '3374']);
    }

    public function test_import_date_normalization_accepts_legacy_formats(): void
    {
        [$branch, $user] = $this->setupBranchAndUser();

        $response = $this->actingAs($user)
            ->postJson(route('database-v2.import.preview', ['module' => 'data_konsumen']), [
                'raw' => "id_kavling\tno_ktp\tnama_konsumen\ttanggal_lahir\nA01\t3374\tBudi\t15/07/1990\nA02\t3375\tSiti\t1990-07-15\nA03\t3376\tJoko\t15-07-90",
            ])
            ->assertOk();

        $rows = $response->json('rows');
        $this->assertSame('1990-07-15', $rows[0]['values']['tanggal_lahir']);
        $this->assertSame('1990-07-15', $rows[1]['values']['tanggal_lahir']);
        $this->assertSame('1990-07-15', $rows[2]['values']['tanggal_lahir']);
    }

    public function test_import_full_tab_psjb_with_legacy_columns_ignored(): void
    {
        [$branch, $user] = $this->setupBranchAndUser();

        $raw = "id_kons\tid_kavling\tno_ktp\tnama_konsumen\tid_psjb\ttanggal_psjb\tnama_koordinator\tnama_sales\tharga_unit\ttanggal_utj\tutj\ttanggal_dp_klt\tdp_all_in\tnominal_cicilan\tjumlah_cicilan\tluas_klt\tharga_klt_m\tharga_klt_total\tcara_pembayaran\tnama_promo"
            ."\nK001\tA01\t3374\tBudi\tPSJB001\t15/01/2026\tAndi\tSiti\t166000000\t10/01/2026\t5000000\t12/01/2026\t20000000\t500000\t12\t60\t2750000\t165000000\tKPR\tPromo A";

        $response = $this->actingAs($user)
            ->postJson(route('database-v2.import.preview', ['module' => 'psjb']), ['raw' => $raw])
            ->assertOk();

        $this->assertArrayNotHasKey('error', $response->json());
        $this->assertSame(1, $response->json('valid_count'));
        $ignored = $response->json('ignored_headers');
        $this->assertContains('id_kons', $ignored);
        $this->assertContains('id_psjb', $ignored);
        $row = $response->json('rows.0.values');
        $this->assertSame('A01', $row['id_kavling']);
        $this->assertSame('Budi', $row['nama_konsumen']);
        $this->assertSame('2026-01-15', $row['tanggal_psjb']);
        $this->assertArrayNotHasKey('id_kons', $row);
        $this->assertArrayNotHasKey('id_psjb', $row);
    }

    public function test_import_full_tab_pemberkasan_with_legacy_ids_ignored(): void
    {
        [$branch, $user] = $this->setupBranchAndUser();

        $raw = "id_kons\tid_kavling\tno_ktp\tnama_konsumen\tid_psjb\tid_berkas\ttanggal_terima_bank\tbank\tkc_unit\trequest_plafond\trequest_tenor\ttipe_pemberkasan"
            ."\nK001\tA01\t3374\tBudi\tPSJB001\tBRK001\t20/01/2026\tBCA\tKC Solo\t150000000\t15\tKPR";

        $response = $this->actingAs($user)
            ->postJson(route('database-v2.import.preview', ['module' => 'pemberkasan']), ['raw' => $raw])
            ->assertOk();

        $this->assertArrayNotHasKey('error', $response->json());
        $this->assertSame(1, $response->json('valid_count'));
        $ignored = $response->json('ignored_headers');
        $this->assertContains('id_kons', $ignored);
        $this->assertContains('id_psjb', $ignored);
        $this->assertContains('id_berkas', $ignored);
        $row = $response->json('rows.0.values');
        $this->assertSame('A01', $row['id_kavling']);
        $this->assertSame('BCA', $row['bank']);
        $this->assertArrayNotHasKey('id_berkas', $row);
    }

    public function test_import_full_tab_akad_with_legacy_ids_ignored(): void
    {
        [$branch, $user] = $this->setupBranchAndUser();

        $raw = "id_kons\tid_kavling\tno_ktp\tnama_konsumen\tid_ppjb_dev\tno_ppjb_akad\ttanggal_akad\tkualitas_akad\tstatus_bangunan\tstatus_dp_konsumen\tstatus_utilitas\tstatus_konsumen\tketerangan_terlambat"
            ."\nK001\tA01\t3374\tBudi\tPPJB001\tAKAD001\t25/01/2026\tBaik\tSiap\tLunas\tSiap\tLanjut\tTepat waktu";

        $response = $this->actingAs($user)
            ->postJson(route('database-v2.import.preview', ['module' => 'akad']), ['raw' => $raw])
            ->assertOk();

        $this->assertArrayNotHasKey('error', $response->json());
        $this->assertSame(1, $response->json('valid_count'));
        $ignored = $response->json('ignored_headers');
        $this->assertContains('id_ppjb_dev', $ignored);
        $this->assertContains('no_ppjb_akad', $ignored);
        $row = $response->json('rows.0.values');
        $this->assertSame('A01', $row['id_kavling']);
        $this->assertSame('2026-01-25', $row['tanggal_akad']);
        $this->assertArrayNotHasKey('no_ppjb_akad', $row);
    }

    public function test_import_truly_unknown_header_still_rejected(): void
    {
        [$branch, $user] = $this->setupBranchAndUser();

        $this->actingAs($user)
            ->postJson(route('database-v2.import.preview', ['module' => 'data_konsumen']), [
                'raw' => "id_kavling\tkolom_ngawur_xyz\nA01\tx",
            ])
            ->assertOk()
            ->assertJsonPath('error', 'Kolom tidak dikenal: kolom_ngawur_xyz');
    }

    public function test_import_save_psjb_with_decimal_and_date_values(): void
    {
        [$branch, $user] = $this->setupBranchAndUser();

        $raw = "id_kons\tid_kavling\tno_ktp\tnama_konsumen\tid_psjb\ttanggal_psjb\tnama_koordinator\tnama_sales\tharga_unit\ttanggal_utj\tutj\ttanggal_dp_klt\tdp_all_in\tnominal_cicilan\tjumlah_cicilan\tluas_klt\tharga_klt_m\tharga_klt_total\tcara_pembayaran\tnama_promo"
            ."\nK001\tA01\t3374\tBudi\tPSJB001\t15/01/2026\tAndi\tSiti\t166000000\t10/01/2026\t5000000\t12/01/2026\t20000000\t500000\t12\t60\t2750000\t165000000\tKPR\tPromo A"
            ."\nK002\tA02\t3375\tSiti\tPSJB002\t20/01/2026\tJoko\tBudi\t200000000\t15/01/2026\t10000000\t17/01/2026\t30000000\t750000\t15\t70\t2857142\t200000000\tCash\tPromo B";

        $response = $this->actingAs($user)
            ->postJson(route('database-v2.import.save', ['module' => 'psjb']), [
                'raw' => $raw,
                'valid_only' => true,
            ])
            ->assertOk();

        $this->assertSame(2, $response->json('saved'));
        $this->assertDatabaseCount('db_v2_psjb', 2);
    }

    public function test_import_save_120_valid_rows_reproduces_browser(): void
    {
        [$branch, $user] = $this->setupBranchAndUser();

        $headers = "id_kavling\tno_ktp\tnama_konsumen\ttanggal_lahir\tpekerjaan\tno_hp\tstatus_konsumen";
        $lines = [$headers];
        for ($i = 1; $i <= 120; $i++) {
            $lines[] = "A{$i}\t3374{$i}\tKonsumen {$i}\t15/01/1990\tKaryawan\t081234{$i}\tLanjut";
        }

        $response = $this->actingAs($user)
            ->postJson(route('database-v2.import.save', ['module' => 'data_konsumen']), [
                'raw' => implode("\n", $lines),
            ])
            ->assertOk();

        $this->assertSame(120, $response->json('saved'));
        $this->assertDatabaseCount('db_v2_data_konsumen', 120);
    }

    public function test_import_save_spot_checks_values_and_legacy_exclusion(): void
    {
        [$branch, $user] = $this->setupBranchAndUser();

        $raw = "id_kons\tid_kavling\tno_ktp\tnama_konsumen\ttanggal_lahir\tpekerjaan\tno_hp\tstatus_konsumen\tumur"
            ."\nK001\tA01\t3374\tBudi\t15/07/1990\tKaryawan\t081234\tLanjut\t35"
            ."\nK002\tA02\t3375\tSiti\t1990-07-15\tWiraswasta\t085678\tLanjut\t28";

        $this->actingAs($user)
            ->postJson(route('database-v2.import.save', ['module' => 'data_konsumen']), [
                'raw' => $raw,
                'valid_only' => true,
            ])
            ->assertOk()
            ->assertJsonPath('saved', 2);

        $record = DataKonsumen::where('id_kavling', 'A01')->first();
        $this->assertSame('3374', $record->no_ktp);
        $this->assertSame('Budi', $record->nama_konsumen);
        $this->assertSame('1990-07-15', $record->tanggal_lahir->format('Y-m-d'));
        $this->assertSame('Karyawan', $record->pekerjaan);
        $this->assertSame('Lanjut', $record->status_konsumen);
        $this->assertNull($record->umur ?? null);
    }

    public function test_import_end_to_end_120_rows_preview_then_save_with_spot_checks(): void
    {
        [$branch, $user] = $this->setupBranchAndUser();

        $headers = "id_kons\tid_kavling\tno_ktp\tnama_konsumen\ttanggal_lahir\tpekerjaan\tdetail_pekerjaan\talamat\tkelurahan\tkecamatan\tkabupaten/kota\tno_hp\tnama_kondar\tno_hp_kondar\tstatus_cash\tstatus_konsumen\tketerangan\tumur\tproses_terakhir\tstatus_terakhir\tstatus_kelengkapan";
        $lines = [$headers];
        for ($i = 1; $i <= 120; $i++) {
            $lines[] = "K{$i}\tA{$i}\t3374{$i}\tKonsumen {$i}\t15/01/1990\tKaryawan\tDetail {$i}\tAlamat {$i}\tKel {$i}\tKec {$i}\tKota {$i}\t081234{$i}\tKondar {$i}\t08567{$i}\tCash\tLanjut\tKet {$i}\t{$i}\tBelum Proses\tLanjut\tLengkap";
        }
        $raw = implode("\n", $lines);

        $preview = $this->actingAs($user)
            ->postJson(route('database-v2.import.preview', ['module' => 'data_konsumen']), [
                'raw' => $raw,
                'branch_id' => $branch->id,
            ])
            ->assertOk()
            ->json();

        $this->assertSame(120, $preview['valid_count']);
        $this->assertSame(0, $preview['invalid_count']);
        $this->assertContains('id_kons', $preview['ignored_headers']);
        $this->assertContains('umur', $preview['ignored_headers']);
        $this->assertContains('proses_terakhir', $preview['ignored_headers']);

        $save = $this->actingAs($user)
            ->postJson(route('database-v2.import.save', ['module' => 'data_konsumen']), [
                'raw' => $raw,
                'branch_id' => $branch->id,
            ])
            ->assertOk()
            ->json();

        $this->assertSame(120, $save['saved']);
        $this->assertDatabaseCount('db_v2_data_konsumen', 120);

        $spot = DataKonsumen::where('id_kavling', 'A1')->first();
        $this->assertNotNull($spot);
        $this->assertSame('33741', $spot->no_ktp);
        $this->assertSame('Konsumen 1', $spot->nama_konsumen);
        $this->assertSame('1990-01-15', $spot->tanggal_lahir->format('Y-m-d'));
        $this->assertSame('Lanjut', $spot->status_konsumen);
        $this->assertSame('Kota 1', $spot->kabupaten_kota);
    }

    public function test_import_save_atomic_all_or_nothing_on_failure(): void
    {
        [$branch, $user] = $this->setupBranchAndUser();

        $raw = "id_kavling\tno_ktp\tnama_konsumen\ttanggal_lahir\nA01\t3374\tBudi\t15/01/1990\nA02\t3375\tSiti\tbad-date\nA03\t3376\tJoko\t20/01/1990";

        $this->actingAs($user)
            ->postJson(route('database-v2.import.save', ['module' => 'data_konsumen']), [
                'raw' => $raw,
                'branch_id' => $branch->id,
            ])
            ->assertOk();

        $this->assertDatabaseCount('db_v2_data_konsumen', 0);
    }

    public function test_export_returns_xlsx(): void
    {
        [$branch, $user] = $this->setupBranchAndUser();
        Bast::create(['branch_id' => $branch->id, 'id_kavling' => 'A01', 'tanggal_bast' => '2026-01-15', 'created_by' => $user->id, 'updated_by' => $user->id]);

        $response = $this->actingAs($user)
            ->get(route('database-v2.export', ['module' => 'bast', 'branch_id' => $branch->id]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('application/vnd.openxmlformats', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('.xlsx', $response->headers->get('Content-Disposition'));
    }

    public function test_changelog_is_idempotent_and_visible(): void
    {
        $title = 'Database V2: Entry Data Manual';
        $migration = require database_path('migrations/2026_08_24_000003_add_database_v2_changelog.php');
        $migration->up();
        $migration->up();
        $superadmin = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'superadmin')->firstOrFail()->id,
            'password_changed_at' => now(),
        ]);

        $this->assertSame(1, Changelog::query()->whereNull('version')->where('title', $title)->count());
        $this->actingAs($superadmin)->get(route('changelogs.index'))->assertOk()->assertSee($title);
    }

    private function setupBranchAndUser(string $branchName = 'Magelang', string $branchCode = 'MGL', bool $readOnly = false): array
    {
        $branch = Branch::create(['name' => $branchName, 'code' => $branchCode, 'is_active' => true, 'sheet_id' => 'sheet-'.$branchCode]);
        $role = Role::create(['name' => 'DB V2 Editor', 'slug' => 'db_v2_editor_'.uniqid(), 'is_superadmin' => false, 'is_active' => true]);
        $slugs = $readOnly
            ? ['database_v2.view', 'database_v2.view_branch']
            : ['database_v2.view', 'database_v2.view_branch', 'database_v2.edit', 'database_v2.manage_branch', 'database_v2.export', 'database_v2.export_branch'];
        $role->permissions()->sync(Permission::query()->whereIn('slug', $slugs)->pluck('id'));
        $user = User::factory()->create(['role_id' => $role->id, 'branch_id' => $branch->id, 'password_changed_at' => now()]);
        $user->branches()->syncWithoutDetaching([$branch->id => ['can_view' => true, 'can_edit' => ! $readOnly]]);

        return [$branch, $user];
    }
}
