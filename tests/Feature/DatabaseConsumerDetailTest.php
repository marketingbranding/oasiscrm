<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Changelog;
use App\Models\DatabaseSheetRecord;
use App\Models\Role;
use App\Models\User;
use App\Services\DatabaseConsumerDetailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseConsumerDetailTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_preserves_field_order_masks_nik_and_excludes_metadata(): void
    {
        $branch = $this->branch('Magelang', 'MGL');
        $this->record($branch, 'data_konsumen', 2, [
            'id_kavling' => 'KV-01',
            'nama_konsumen' => 'Siti Aminah',
            'no_ktp' => '3374012345678901',
            'tanggal_lahir' => '1990-01-02',
            'extra_field' => 'Nilai tambahan',
            'oasis_sync_id' => 'secret-sync-id',
            'oasis_deleted_by' => '99',
        ], ['nama_konsumen', 'id_kavling', 'no_ktp', 'tanggal_lahir', 'oasis_sync_id']);

        $response = $this->actingAs($this->user('superadmin', $branch))->getJson(route('database.consumer', [
            'branch' => $branch,
            'id_kavling' => '  KV-01  ',
            'section' => 'summary',
        ]))->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.identity.branch_id', $branch->id)
            ->assertJsonPath('data.identity.id_kavling', 'KV-01')
            ->assertJsonPath('data.source.sheet_name', 'data_konsumen')
            ->assertJsonPath('data.fields.0.key', 'nama_konsumen')
            ->assertJsonPath('data.fields.1.key', 'id_kavling')
            ->assertJsonPath('data.fields.2.key', 'no_ktp')
            ->assertJsonPath('data.fields.2.label', 'NIK')
            ->assertJsonPath('data.fields.2.type', 'text')
            ->assertJsonPath('data.fields.2.value', '3374••••••••8901')
            ->assertJsonPath('data.fields.3.type', 'date')
            ->assertJsonPath('data.fields.4.key', 'extra_field')
            ->assertJsonPath('data.fields.4.label', 'Extra Field');

        $content = $response->getContent();
        $this->assertStringNotContainsString('3374012345678901', $content);
        $this->assertStringNotContainsString('secret-sync-id', $content);
        $this->assertStringNotContainsString('oasis_deleted_by', $content);
    }

    public function test_summary_uses_exact_canonical_identity_and_branch_only(): void
    {
        $branch = $this->branch('Magelang', 'MGL');
        $otherBranch = $this->branch('Solo', 'SLO');
        $this->record($branch, 'data_konsumen', 2, ['id_kavling' => 'KV-01', 'nama_konsumen' => 'Tepat']);
        $this->record($branch, 'data_konsumen', 3, ['ID Kavling' => 'KV-02', 'nama_konsumen' => 'Alias']);
        $this->record($otherBranch, 'data_konsumen', 2, ['id_kavling' => 'KV-01', 'nama_konsumen' => 'Cabang Lain']);
        $user = $this->user('superadmin', $branch);

        $this->actingAs($user)->getJson(route('database.consumer', ['branch' => $branch, 'id_kavling' => 'kv-01']))
            ->assertNotFound()->assertJsonPath('code', 'consumer_not_found');
        $this->actingAs($user)->getJson(route('database.consumer', ['branch' => $branch, 'id_kavling' => 'KV-02']))
            ->assertNotFound()->assertJsonPath('code', 'consumer_not_found');
        $this->actingAs($user)->getJson(route('database.consumer', ['branch' => $branch, 'id_kavling' => 'KV-01']))
            ->assertOk()->assertJsonPath('data.fields.1.value', 'Tepat');
    }

    public function test_missing_duplicate_and_invalid_requests_have_stable_codes(): void
    {
        $branch = $this->branch('Magelang', 'MGL');
        $user = $this->user('superadmin', $branch);

        $this->actingAs($user)->getJson(route('database.consumer', ['branch' => $branch, 'id_kavling' => 'MISSING']))
            ->assertNotFound()->assertExactJson([
                'ok' => false,
                'code' => 'consumer_not_found',
                'message' => 'Detail konsumen tidak ditemukan.',
            ]);

        $this->record($branch, 'data_konsumen', 2, ['id_kavling' => 'DUP-01', 'nama_konsumen' => 'Satu']);
        $this->record($branch, 'data_konsumen', 3, ['id_kavling' => 'DUP-01', 'nama_konsumen' => 'Dua']);
        $this->actingAs($user)->getJson(route('database.consumer', ['branch' => $branch, 'id_kavling' => 'DUP-01']))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'ambiguous_id_kavling')
            ->assertJsonMissing(['nama_konsumen' => 'Satu'])
            ->assertJsonMissing(['nama_konsumen' => 'Dua']);

        $this->actingAs($user)->getJson(route('database.consumer', ['branch' => $branch, 'section' => 'forged']))
            ->assertUnprocessable()->assertJsonPath('code', 'invalid_request');
        $this->actingAs($user)->getJson(route('database.consumer', ['branch' => $branch, 'id_kavling' => 'DUP-01', 'section' => 'forged']))
            ->assertUnprocessable()->assertJsonPath('code', 'invalid_request');
    }

    public function test_authorization_runs_before_consumer_lookup(): void
    {
        $allowed = $this->branch('Magelang', 'MGL');
        $foreign = $this->branch('Solo', 'SLO');
        $admin = $this->user('admin', $allowed);
        $staff = $this->user('staff', $allowed);
        $sales = $this->user('sales', $allowed);

        $this->actingAs($admin)->getJson(route('database.consumer', ['branch' => $foreign, 'id_kavling' => 'UNKNOWN']))->assertForbidden();
        $this->actingAs($staff)->getJson(route('database.consumer', ['branch' => $allowed, 'id_kavling' => 'UNKNOWN']))->assertForbidden();
        $this->actingAs($sales)->getJson(route('database.consumer', ['branch' => $allowed, 'id_kavling' => 'UNKNOWN']))->assertForbidden();
    }

    public function test_history_returns_all_stages_and_repeated_rows_in_canonical_order(): void
    {
        $branch = $this->branch('Magelang', 'MGL');
        $otherBranch = $this->branch('Solo', 'SLO');
        $this->record($branch, 'data_konsumen', 2, ['id_kavling' => 'KV-01', 'nama_konsumen' => 'Siti', 'no_ktp' => '3374012345678901']);
        $this->record($branch, 'bi_checking', 8, ['id_kavling' => 'KV-01', 'nama_konsumen' => 'Siti', 'no_ktp' => '3374012345678901', 'hasil_slik' => 'Lolos']);
        $this->record($branch, 'bi_checking', 3, ['id_kavling' => 'KV-01', 'nama_konsumen' => 'Siti', 'hasil_slik' => 'Ulang']);
        $this->record($branch, 'PSJB', 4, ['id_kavling' => 'KV-01', 'nama_konsumen' => 'Siti', 'harga_unit' => '250000000']);
        $this->record($branch, 'bast', 9, ['id_kavling' => 'KV-01', 'nama_konsumen' => 'Siti', 'tanggal_bast' => '2026-08-24']);
        $this->record($branch, 'akad', 4, ['id_kavling' => 'OTHER', 'nama_konsumen' => 'Lain']);
        $this->record($otherBranch, 'akad', 4, ['id_kavling' => 'KV-01', 'nama_konsumen' => 'Cabang Lain']);
        $this->record($branch, 'akad', 5, ['id_kavling' => 'KV-01', 'nama_konsumen' => 'Terhapus'], deleted: true);

        $response = $this->actingAs($this->user('superadmin', $branch))->getJson(route('database.consumer', [
            'branch' => $branch,
            'id_kavling' => 'KV-01',
            'section' => 'history',
        ]))->assertOk()
            ->assertJsonPath('data.section', 'history')
            ->assertJsonPath('data.stages.0.key', 'bi_checking')
            ->assertJsonPath('data.stages.1.key', 'PSJB')
            ->assertJsonPath('data.stages.2.key', 'pemberkasan')
            ->assertJsonPath('data.stages.3.key', 'proses_bank')
            ->assertJsonPath('data.stages.4.key', 'ppjb_dev')
            ->assertJsonPath('data.stages.5.key', 'akad')
            ->assertJsonPath('data.stages.6.key', 'bast')
            ->assertJsonPath('data.stages.0.items.0.row_number', 3)
            ->assertJsonPath('data.stages.0.items.1.row_number', 8)
            ->assertJsonCount(2, 'data.stages.0.items')
            ->assertJsonCount(1, 'data.stages.1.items')
            ->assertJsonCount(0, 'data.stages.5.items')
            ->assertJsonCount(1, 'data.stages.6.items');

        $content = $response->getContent();
        $this->assertStringContainsString('snapshot cache spreadsheet', $content);
        $this->assertStringContainsString('Penggunaan ulang ID Kavling', $content);
        $this->assertSame('3374••••••••8901', $response->json('data.stages.0.items.1.fields.2.value'));
        $this->assertStringNotContainsString('3374012345678901', $content);
        $this->assertStringNotContainsString('Terhapus', $content);
        $this->assertStringNotContainsString('Cabang Lain', $content);
        $this->assertStringNotContainsString('data_konsumen', json_encode($response->json('data.stages'), JSON_THROW_ON_ERROR));
    }

    public function test_service_query_count_is_fixed_and_queries_filter_json_identity(): void
    {
        $branch = $this->branch('Magelang', 'MGL');
        $this->record($branch, 'data_konsumen', 2, ['id_kavling' => 'KV-01', 'nama_konsumen' => 'Siti']);
        $this->record($branch, 'akad', 3, ['id_kavling' => 'KV-01', 'nama_konsumen' => 'Siti']);
        $service = app(DatabaseConsumerDetailService::class);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $service->summary($branch, 'KV-01');
        $summaryQueries = DB::getQueryLog();
        $this->assertCount(1, $summaryQueries);
        $this->assertStringContainsString('id_kavling', $summaryQueries[0]['query']);
        $this->assertStringContainsString('limit 2', strtolower($summaryQueries[0]['query']));

        DB::flushQueryLog();
        $service->history($branch, 'KV-01');
        $historyQueries = DB::getQueryLog();
        $this->assertCount(2, $historyQueries);
        $this->assertStringContainsString('id_kavling', $historyQueries[1]['query']);
        $this->assertStringContainsString('limit 501', strtolower($historyQueries[1]['query']));
        DB::disableQueryLog();
    }

    public function test_nik_aliases_are_masked_and_history_limit_fails_closed(): void
    {
        $branch = $this->branch('Magelang', 'MGL');
        $this->record($branch, 'data_konsumen', 2, [
            'id_kavling' => 'KV-01',
            'nama_konsumen' => 'Siti',
            'nomor_ktp' => '3374012345678901',
            'nik_konsumen' => '3374012345678902',
            'no_ktp_pasangan' => '3374012345678903',
        ]);
        $user = $this->user('superadmin', $branch);

        $summary = $this->actingAs($user)->getJson(route('database.consumer', [
            'branch' => $branch,
            'id_kavling' => 'KV-01',
        ]))->assertOk();
        $content = $summary->getContent();
        foreach (['3374012345678901', '3374012345678902', '3374012345678903'] as $nik) {
            $this->assertStringNotContainsString($nik, $content);
        }

        for ($row = 2; $row <= 502; $row++) {
            $this->record($branch, 'bi_checking', $row, ['id_kavling' => 'KV-01', 'hasil_slik' => 'OK']);
        }

        $this->actingAs($user)->getJson(route('database.consumer', [
            'branch' => $branch,
            'id_kavling' => 'KV-01',
            'section' => 'history',
        ]))->assertUnprocessable()
            ->assertJsonPath('code', 'history_limit_exceeded')
            ->assertJsonMissing(['hasil_slik' => 'OK']);
    }

    public function test_drawer_source_contract_and_changelog_are_present(): void
    {
        $view = file_get_contents(resource_path('views/crm/database/index.blade.php'));
        $modal = file_get_contents(resource_path('views/components/crm/modal.blade.php'));

        $this->assertStringContainsString("normalizeKey(h) === 'nama konsumen' && String(rec.row_data.id_kavling ?? '').trim() !== ''", $view);
        $this->assertStringContainsString('openConsumerDetail(rec, $el)', $view);
        $this->assertStringContainsString('aria-controls="crm-modal-database-consumer-detail"', $view);
        $this->assertStringContainsString('name="database-consumer-detail" title="Detail Konsumen" placement="right"', $view);
        $this->assertStringContainsString('<details class="database-consumer-history" @toggle="loadConsumerHistory($event)">', $view);
        $this->assertStringContainsString('consumerHistoryLoaded', $view);
        $this->assertStringContainsString('retryConsumerHistory()', $view);
        $this->assertStringNotContainsString('x-html', substr($view, strpos($view, '<x-crm.modal name="database-consumer-detail"'), strpos($view, '<x-crm.modal name="database-edit"') - strpos($view, '<x-crm.modal name="database-consumer-detail"')));
        $this->assertStringContainsString("'placement' => 'center'", $modal);
        $this->assertStringContainsString('crm-modal-panel--right', $modal);

        $migration = require database_path('migrations/2026_08_24_000010_add_database_consumer_detail_changelog.php');
        $migration->up();
        $migration->up();
        $title = 'Detail Konsumen Database Kini Tersedia';
        $this->assertSame(1, Changelog::query()->whereNull('version')->where('title', $title)->count());
        $this->actingAs($this->user('superadmin'))->get(route('changelogs.index'))->assertOk()->assertSee($title);
    }

    private function branch(string $name, string $code): Branch
    {
        return Branch::query()->create([
            'name' => $name,
            'code' => $code,
            'is_active' => true,
            'sheet_id' => 'sheet-'.strtolower($code),
        ]);
    }

    private function user(string $roleSlug, ?Branch $branch = null): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $roleSlug)->firstOrFail()->id,
            'branch_id' => $branch?->id,
            'password_changed_at' => now(),
        ]);
    }

    private function record(Branch $branch, string $sheet, int $row, array $data, ?array $headers = null, bool $deleted = false): DatabaseSheetRecord
    {
        return DatabaseSheetRecord::query()->create([
            'branch_id' => $branch->id,
            'sheet_id' => $branch->sheet_id,
            'sheet_name' => $sheet,
            'row_number' => $row,
            'headers' => $headers ?? array_keys($data),
            'row_data' => $data,
            'formula_columns' => [],
            'column_metadata' => [],
            'oasis_deleted_at' => $deleted ? now() : null,
        ]);
    }
}
