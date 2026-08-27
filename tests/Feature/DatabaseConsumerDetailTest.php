<?php

namespace Tests\Feature;

use App\Exceptions\DatabaseConsumerDetailException;
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

    public function test_same_kavling_roots_return_only_their_exact_canonical_chains(): void
    {
        $branch = $this->branch('Magelang', 'MGL');
        $first = $this->chain($branch, 'A', 'KV-01', '3374012345678901');
        $second = $this->chain($branch, 'B', 'KV-01', '3374012345678902');
        $user = $this->user('superadmin', $branch);

        foreach ([[$first, 'KONS-A', 'KONS-B'], [$second, 'KONS-B', 'KONS-A']] as [$chain, $included, $excluded]) {
            $summary = $this->actingAs($user)->getJson(route('database.consumer', [
                'branch' => $branch,
                'record_id' => $chain['data_konsumen']->id,
            ]))->assertOk()
                ->assertJsonPath('data.identity.id_kavling', 'KV-01')
                ->assertJsonPath('data.identity.anchor.record_id', $chain['data_konsumen']->id)
                ->assertJsonPath('data.identity.basis', 'canonical_chain')
                ->assertJsonPath('data.identity.history_available', true);

            $history = $this->actingAs($user)->getJson(route('database.consumer', [
                'branch' => $branch,
                'record_id' => $chain['data_konsumen']->id,
                'section' => 'history',
            ]))->assertOk()
                ->assertJsonPath('data.basis', 'canonical_chain')
                ->assertJsonCount(1, 'data.stages.0.items')
                ->assertJsonCount(1, 'data.stages.6.items');

            $this->assertStringContainsString($included, $history->getContent());
            $this->assertStringNotContainsString($excluded, $history->getContent());
            $this->assertStringNotContainsString($chain['nik'], $summary->getContent());
            $this->assertStringNotContainsString($chain['nik'], $history->getContent());
        }
    }

    public function test_anchors_from_every_supported_stage_resolve_the_exact_root(): void
    {
        $branch = $this->branch('Magelang', 'MGL');
        $chain = $this->chain($branch, 'A', 'KV-01', '3374012345678901');
        $user = $this->user('superadmin', $branch);

        foreach ($chain['records'] as $sheet => $record) {
            $this->actingAs($user)->getJson(route('database.consumer', [
                'branch' => $branch,
                'record_id' => $record->id,
            ]))->assertOk()
                ->assertJsonPath('data.source.sheet_name', 'data_konsumen')
                ->assertJsonPath('data.identity.anchor.record_id', $record->id)
                ->assertJsonPath('data.identity.anchor.sheet_name', $sheet)
                ->assertJsonPath('data.fields.1.value', 'Konsumen A');
        }
    }

    public function test_forward_history_includes_repeated_bi_and_bank_attempts(): void
    {
        $branch = $this->branch('Magelang', 'MGL');
        $chain = $this->chain($branch, 'A', 'KV-01', '3374012345678901');
        $this->record($branch, 'bi_checking', 20, [
            'no_ktp' => $chain['nik'],
            'id_kons' => 'KONS-A-RETRY',
            'hasil_slik' => 'Ulang',
            'id_kavling' => 'KV-01',
        ]);
        $this->record($branch, 'PSJB', 21, [
            'id_kons' => 'KONS-A-RETRY',
            'id_psjb' => 'PSJB-A-RETRY',
            'id_kavling' => 'KV-01',
        ]);
        $this->record($branch, 'pemberkasan', 22, [
            'id_psjb' => 'PSJB-A-RETRY',
            'id_berkas' => 'BERKAS-A-RETRY',
            'id_kavling' => 'KV-01',
        ]);
        $this->record($branch, 'proses_bank', 23, [
            'id_berkas' => 'BERKAS-A-RETRY',
            'no_sp3k' => 'SP3K-A-RETRY-1',
            'bank' => 'Bank Satu',
            'id_kavling' => 'KV-01',
        ]);
        $this->record($branch, 'proses_bank', 24, [
            'id_berkas' => 'BERKAS-A-RETRY',
            'no_sp3k' => 'SP3K-A-RETRY-2',
            'bank' => 'Bank Dua',
            'id_kavling' => 'KV-01',
        ]);

        $response = $this->actingAs($this->user('superadmin', $branch))->getJson(route('database.consumer', [
            'branch' => $branch,
            'record_id' => $chain['data_konsumen']->id,
            'section' => 'history',
        ]))->assertOk()
            ->assertJsonCount(2, 'data.stages.0.items')
            ->assertJsonCount(2, 'data.stages.1.items')
            ->assertJsonCount(2, 'data.stages.2.items')
            ->assertJsonCount(3, 'data.stages.3.items');

        $this->assertStringContainsString('Bank Satu', $response->getContent());
        $this->assertStringContainsString('Bank Dua', $response->getContent());
    }

    public function test_duplicate_root_nik_and_cross_root_produced_id_fail_closed(): void
    {
        $branch = $this->branch('Magelang', 'MGL');
        $first = $this->chain($branch, 'A', 'KV-01', '3374012345678901');
        $this->record($branch, 'data_konsumen', 30, [
            'id_kavling' => 'KV-02',
            'nama_konsumen' => 'Identitas Ganda',
            'no_ktp' => $first['nik'],
        ]);
        $service = app(DatabaseConsumerDetailService::class);

        try {
            $service->history($branch, $first['data_konsumen']->id);
            $this->fail('Duplicate root NIK should fail.');
        } catch (DatabaseConsumerDetailException $exception) {
            $this->assertSame('ambiguous_consumer_identity', $exception->errorCode);
        }
        $this->actingAs($this->user('superadmin', $branch))->getJson(route('database.consumer', [
            'branch' => $branch,
            'record_id' => $first['data_konsumen']->id,
        ]))->assertUnprocessable()->assertJsonPath('code', 'ambiguous_consumer_identity');

        $other = $this->chain($branch, 'B', 'KV-03', '3374012345678902');
        $this->record($branch, 'data_konsumen', 31, [
            'id_kavling' => 'KV-04',
            'nama_konsumen' => 'Pemilik ID Lain',
            'no_ktp' => '3374012345678903',
        ]);
        $this->record($branch, 'bi_checking', 32, [
            'no_ktp' => '3374012345678903',
            'id_kons' => 'KONS-B',
            'id_kavling' => 'KV-04',
        ]);

        $this->actingAs($this->user('superadmin', $branch))->getJson(route('database.consumer', [
            'branch' => $branch,
            'record_id' => $other['data_konsumen']->id,
            'section' => 'history',
        ]))->assertUnprocessable()
            ->assertExactJson([
                'ok' => false,
                'code' => 'ambiguous_chain_id',
                'message' => 'Rantai konsumen tidak dapat ditentukan secara unik.',
            ]);
    }

    public function test_repeated_produced_id_is_allowed_when_all_attempts_resolve_to_same_root(): void
    {
        $branch = $this->branch('Magelang', 'MGL');
        $chain = $this->chain($branch, 'A', 'KV-01', '3374012345678901');
        $retry = $this->record($branch, 'bi_checking', 30, [
            'no_ktp' => $chain['nik'],
            'id_kons' => 'KONS-A',
            'id_kavling' => 'KV-01',
            'hasil_slik' => 'Ulang',
        ]);

        $this->actingAs($this->user('superadmin', $branch))->getJson(route('database.consumer', [
            'branch' => $branch,
            'record_id' => $retry->id,
            'section' => 'history',
        ]))->assertOk()
            ->assertJsonCount(2, 'data.stages.0.items')
            ->assertJsonPath('data.identity.anchor.record_id', $retry->id);
    }

    public function test_broken_chain_has_no_kavling_fallback_and_blank_root_nik_summary_remains_available(): void
    {
        $branch = $this->branch('Magelang', 'MGL');
        $root = $this->record($branch, 'data_konsumen', 2, [
            'id_kavling' => 'KV-01',
            'nama_konsumen' => 'Tanpa NIK',
            'no_ktp' => '   ',
        ]);
        $orphan = $this->record($branch, 'pemberkasan', 3, [
            'id_psjb' => 'MISSING',
            'id_berkas' => 'BERKAS-ORPHAN',
            'id_kavling' => 'KV-01',
        ]);
        $user = $this->user('superadmin', $branch);

        $this->actingAs($user)->getJson(route('database.consumer', [
            'branch' => $branch,
            'record_id' => $root->id,
        ]))->assertOk()
            ->assertJsonPath('data.identity.history_available', false)
            ->assertJsonPath('data.identity.history_unavailable_reason', 'consumer_chain_broken')
            ->assertJsonPath('data.fields.1.value', 'Tanpa NIK');

        $this->actingAs($user)->getJson(route('database.consumer', [
            'branch' => $branch,
            'record_id' => $root->id,
            'section' => 'history',
        ]))->assertUnprocessable()->assertJsonPath('code', 'consumer_chain_broken');

        $this->actingAs($user)->getJson(route('database.consumer', [
            'branch' => $branch,
            'record_id' => $orphan->id,
        ]))->assertUnprocessable()->assertJsonPath('code', 'consumer_chain_broken');
    }

    public function test_blank_produced_id_keeps_connected_row_warns_and_stops_downstream(): void
    {
        $branch = $this->branch('Magelang', 'MGL');
        $root = $this->record($branch, 'data_konsumen', 2, [
            'id_kavling' => 'KV-01',
            'nama_konsumen' => 'Siti',
            'no_ktp' => '3374012345678901',
        ]);
        $bi = $this->record($branch, 'bi_checking', 3, [
            'id_kavling' => 'KV-01',
            'no_ktp' => '3374012345678901',
            'id_kons' => '   ',
        ]);
        $this->record($branch, 'PSJB', 4, [
            'id_kavling' => 'KV-01',
            'id_kons' => 'UNRELATED',
            'id_psjb' => 'PSJB-UNRELATED',
        ]);

        $response = $this->actingAs($this->user('superadmin', $branch))->getJson(route('database.consumer', [
            'branch' => $branch,
            'record_id' => $root->id,
            'section' => 'history',
        ]))->assertOk()
            ->assertJsonPath('data.stages.0.items.0.record_id', $bi->id)
            ->assertJsonCount(0, 'data.stages.1.items')
            ->assertJsonPath('data.diagnostics.0.code', 'blank_chain_id');

        $this->assertStringContainsString('tahap berikutnya tidak ditelusuri', $response->getContent());
        $this->assertStringNotContainsString('PSJB-UNRELATED', $response->getContent());
    }

    public function test_chain_with_different_kavling_is_included_with_warnings_and_diagnostics(): void
    {
        $branch = $this->branch('Magelang', 'MGL');
        $chain = $this->chain($branch, 'A', 'KV-01', '3374012345678901');
        $chain['PSJB']->update(['row_data' => array_merge($chain['PSJB']->row_data, ['id_kavling' => 'KV-99'])]);

        $response = $this->actingAs($this->user('superadmin', $branch))->getJson(route('database.consumer', [
            'branch' => $branch,
            'record_id' => $chain['data_konsumen']->id,
            'section' => 'history',
        ]))->assertOk()
            ->assertJsonPath('data.stages.1.items.0.record_id', $chain['PSJB']->id)
            ->assertJsonPath('data.diagnostics.0.code', 'kavling_mismatch');

        $this->assertStringContainsString('berbeda dari data konsumen utama', $response->getContent());
    }

    public function test_deleted_foreign_and_stale_anchors_are_not_found_after_branch_authorization(): void
    {
        $branch = $this->branch('Magelang', 'MGL');
        $foreign = $this->branch('Solo', 'SLO');
        $deleted = $this->record($branch, 'data_konsumen', 2, ['id_kavling' => 'KV-01'], deleted: true);
        $foreignRecord = $this->record($foreign, 'data_konsumen', 2, ['id_kavling' => 'KV-02']);
        $unsupported = $this->record($branch, 'other_sheet', 3, ['id_kavling' => 'KV-03']);
        $user = $this->user('superadmin', $branch);

        foreach ([$deleted->id, $foreignRecord->id, $unsupported->id, 999999] as $recordId) {
            $this->actingAs($user)->getJson(route('database.consumer', [
                'branch' => $branch,
                'record_id' => $recordId,
            ]))->assertNotFound()->assertExactJson([
                'ok' => false,
                'code' => 'consumer_not_found',
                'message' => 'Detail konsumen tidak ditemukan.',
            ]);
        }
    }

    public function test_authorization_runs_before_record_validation_or_lookup(): void
    {
        $allowed = $this->branch('Magelang', 'MGL');
        $foreign = $this->branch('Solo', 'SLO');

        $this->actingAs($this->user('admin', $allowed))->getJson(route('database.consumer', ['branch' => $foreign]))->assertForbidden();
        $this->actingAs($this->user('staff', $allowed))->getJson(route('database.consumer', ['branch' => $allowed]))->assertForbidden();
        $this->actingAs($this->user('sales', $allowed))->getJson(route('database.consumer', ['branch' => $allowed]))->assertForbidden();
    }

    public function test_501_connected_history_rows_fail_without_partial_data(): void
    {
        $branch = $this->branch('Magelang', 'MGL');
        $root = $this->record($branch, 'data_konsumen', 2, [
            'id_kavling' => 'KV-01',
            'nama_konsumen' => 'Siti',
            'no_ktp' => '3374012345678901',
        ]);
        for ($row = 3; $row <= 503; $row++) {
            $this->record($branch, 'bi_checking', $row, [
                'no_ktp' => '3374012345678901',
                'id_kons' => 'KONS-'.$row,
                'hasil_slik' => 'Rahasia-'.$row,
            ]);
        }

        $response = $this->actingAs($this->user('superadmin', $branch))->getJson(route('database.consumer', [
            'branch' => $branch,
            'record_id' => $root->id,
            'section' => 'history',
        ]))->assertUnprocessable()
            ->assertJsonPath('code', 'history_limit_exceeded');

        $this->assertStringNotContainsString('Rahasia-', $response->getContent());
    }

    public function test_nik_aliases_are_masked_and_never_leak_in_error_responses(): void
    {
        $branch = $this->branch('Magelang', 'MGL');
        $chain = $this->chain($branch, 'A', 'KV-01', '3374012345678901');
        $rootData = array_merge($chain['data_konsumen']->row_data, [
            'nomor_ktp' => '3374012345678902',
            'nik_konsumen' => '3374012345678903',
            'no_ktp_pasangan' => '3374012345678904',
            'oasis_sync_id' => 'hidden-sync-id',
            'oasis_deleted_by' => 'hidden-actor',
        ]);
        $chain['data_konsumen']->update(['headers' => array_keys($rootData), 'row_data' => $rootData]);
        $user = $this->user('superadmin', $branch);

        $summary = $this->actingAs($user)->getJson(route('database.consumer', [
            'branch' => $branch,
            'record_id' => $chain['data_konsumen']->id,
        ]))->assertOk();
        foreach (['3374012345678901', '3374012345678902', '3374012345678903', '3374012345678904'] as $nik) {
            $this->assertStringNotContainsString($nik, $summary->getContent());
        }
        $this->assertStringNotContainsString('hidden-sync-id', $summary->getContent());
        $this->assertStringNotContainsString('hidden-actor', $summary->getContent());

        $this->record($branch, 'data_konsumen', 50, ['no_ktp' => $chain['nik'], 'nama_konsumen' => 'Ganda']);
        $error = $this->actingAs($user)->getJson(route('database.consumer', [
            'branch' => $branch,
            'record_id' => $chain['data_konsumen']->id,
            'section' => 'history',
        ]))->assertUnprocessable()->assertJsonPath('code', 'ambiguous_consumer_identity');
        $this->assertStringNotContainsString($chain['nik'], $error->getContent());
    }

    public function test_queries_are_bounded_and_use_only_canonical_chain_fields(): void
    {
        $branch = $this->branch('Magelang', 'MGL');
        $chain = $this->chain($branch, 'A', 'KV-01', '3374012345678901');
        $service = app(DatabaseConsumerDetailService::class);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $service->history($branch, $chain['bast']->id);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(25, count($queries));
        $sql = strtolower(collect($queries)->pluck('query')->implode(' '));
        foreach (['no_ktp', 'id_kons', 'id_psjb', 'id_berkas', 'no_sp3k', 'id_ppjb_dev', 'no_ppjb_akad'] as $field) {
            $this->assertStringContainsString($field, $sql);
        }
        $this->assertStringNotContainsString('id_kavling', $sql);
    }

    public function test_endpoint_requires_positive_integer_record_id(): void
    {
        $branch = $this->branch('Magelang', 'MGL');
        $user = $this->user('superadmin', $branch);

        foreach ([[], ['record_id' => 0], ['record_id' => '1.2'], ['id_kavling' => 'KV-01'], ['record_id' => 1, 'section' => 'forged']] as $query) {
            $this->actingAs($user)->getJson(route('database.consumer', array_merge(['branch' => $branch], $query)))
                ->assertUnprocessable()->assertJsonPath('code', 'invalid_request');
        }
    }

    public function test_drawer_record_anchor_freeze_contract_and_changelog_are_present(): void
    {
        $view = file_get_contents(resource_path('views/crm/database/index.blade.php'));
        $css = file_get_contents(resource_path('css/app.css'));
        $modalStart = strpos($view, '<x-crm.modal name="database-consumer-detail"');
        $modalEnd = strpos($view, '<x-crm.modal name="database-edit"');

        $this->assertStringContainsString('detailSupported(name) && Number.isInteger(Number(rec.id))', $view);
        $this->assertStringContainsString('consumerRecordId: null', $view);
        $this->assertStringContainsString('new URLSearchParams({ record_id: this.consumerRecordId, section })', $view);
        $this->assertStringNotContainsString('consumerIdKavling', $view);
        $this->assertStringContainsString('ambiguous_consumer_identity', $view);
        $this->assertStringContainsString('ambiguous_chain_id', $view);
        $this->assertStringContainsString('consumer_chain_broken', $view);
        $this->assertStringContainsString('freezeEligible(name)', $view);
        $this->assertStringContainsString('effectiveFrozen(name)', $view);
        $this->assertStringContainsString('this.isIdKavlingColumn(this.tableHeaders(name)[0])', $view);
        $this->assertStringContainsString("x-text=\"effectiveFrozen(name) ? 'Lepaskan ID Kavling' : 'Bekukan ID Kavling'\"", $view);
        $this->assertStringNotContainsString('database-freeze-toggle', $view);
        $this->assertStringNotContainsString('database-freeze-toggle', $css);
        $this->assertStringContainsString('left: 44px', $css);
        $this->assertStringContainsString('box-shadow: 2px 0 0', $css);
        $this->assertStringNotContainsString('left: 52px', $css);
        $this->assertStringNotContainsString('x-html', substr($view, $modalStart, $modalEnd - $modalStart));

        $migration = require database_path('migrations/2026_08_24_000010_add_database_consumer_detail_changelog.php');
        $migration->up();
        $migration->up();
        $title = 'Detail Konsumen Database Kini Tersedia';
        $this->assertSame(1, Changelog::query()->whereNull('version')->where('title', $title)->count());
        $this->assertStringContainsString('rantai ID kanonis', Changelog::query()->where('title', $title)->value('description'));
        $this->actingAs($this->user('superadmin'))->get(route('changelogs.index'))->assertOk()->assertSee($title);
    }

    private function chain(Branch $branch, string $suffix, string $kavling, string $nik): array
    {
        $data = [
            'data_konsumen' => ['id_kavling' => $kavling, 'nama_konsumen' => 'Konsumen '.$suffix, 'no_ktp' => $nik],
            'bi_checking' => ['id_kavling' => $kavling, 'no_ktp' => $nik, 'id_kons' => 'KONS-'.$suffix],
            'PSJB' => ['id_kavling' => $kavling, 'id_kons' => 'KONS-'.$suffix, 'id_psjb' => 'PSJB-'.$suffix],
            'pemberkasan' => ['id_kavling' => $kavling, 'id_psjb' => 'PSJB-'.$suffix, 'id_berkas' => 'BERKAS-'.$suffix],
            'proses_bank' => ['id_kavling' => $kavling, 'id_berkas' => 'BERKAS-'.$suffix, 'no_sp3k' => 'SP3K-'.$suffix],
            'ppjb_dev' => ['id_kavling' => $kavling, 'no_sp3k' => 'SP3K-'.$suffix, 'id_ppjb_dev' => 'PPJBDEV-'.$suffix],
            'akad' => ['id_kavling' => $kavling, 'id_ppjb_dev' => 'PPJBDEV-'.$suffix, 'no_ppjb_akad' => 'AKAD-'.$suffix],
            'bast' => ['id_kavling' => $kavling, 'no_ppjb_akad' => 'AKAD-'.$suffix, 'no_bast' => 'BAST-'.$suffix],
        ];
        $records = [];
        $row = 2 + ((ord($suffix) - ord('A')) * 100);
        foreach ($data as $sheet => $rowData) {
            $records[$sheet] = $this->record($branch, $sheet, $row++, $rowData);
        }

        return array_merge($records, [
            'records' => $records,
            'nik' => $nik,
        ]);
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
