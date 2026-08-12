<?php

namespace Tests\Feature;

use App\Exceptions\SalesLeadSpreadsheetContractException;
use App\Models\Branch;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\SalesLead;
use App\Models\User;
use App\Services\GoogleSheetsApiService;
use App\Services\PhoneNormalizationService;
use App\Services\SalesLeadLifecycleService;
use App\Services\SalesLeadService;
use App\Services\SalesLeadSheetOptionService;
use App\Services\SalesLeadSpreadsheetContract;
use App\Services\SalesLeadSpreadsheetWriter;
use App\Services\SalesSheetIdentityService;
use App\ValueObjects\GoogleSheetsAppendResult;
use App\ValueObjects\ResolvedSalesLeadSpreadsheetContract;
use App\ValueObjects\SalesLeadSheetDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class SalesLeadSpreadsheetFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registry_contains_exact_target_sheets_and_read_alias(): void
    {
        $google = Mockery::mock(GoogleSheetsApiService::class);
        $contracts = new SalesLeadSpreadsheetContract($google);

        $this->assertSame(
            ['lead', 'data_ceklok', 'data_sales', 'data_konsumen_nup', 'data_konsumen', 'bi_checking', 'akad'],
            array_keys($contracts->definitions()),
        );
        $this->assertSame('Cek Slik', $contracts->normalizeReadStatus(' Cek Silk '));
        $this->assertSame('Cek Slik', $contracts->normalizeReadStatus(' Cek Slik '));
        $this->assertSame('Cek Slik', $contracts->normalizeReadStatus(' Cek SLIK '));
    }

    public function test_lead_contract_accepts_actual_cek_slik_validation_and_writes_the_branch_option(): void
    {
        [$lead] = $this->leadContext('sheet-status-alias');
        $headers = [
            'id_lead', 'nama_promo', 'tanggal_lead', 'sumber_lead', 'kanal_masuk', 'aktivitas_lead',
            'nama_konsumen', 'no_hp', 'proyek', 'sales_pic', 'status_lead', 'keterangan', '', 'Sumber', 'Kanal', 'Aktivitas',
        ];
        $formulas = [$headers, ['=FORMULA_ID_LEAD()']];
        $metadata = array_fill(0, count($headers), null);
        $metadata[1] = ['type' => 'select', 'strict' => true, 'options' => ['PROMO-1']];
        $metadata[2] = ['type' => 'date', 'strict' => false, 'options' => []];
        $metadata[3] = ['type' => 'select', 'strict' => true, 'options' => ['Online']];
        $metadata[4] = ['type' => 'select', 'strict' => true, 'options' => ['Instagram']];
        $metadata[5] = ['type' => 'select', 'strict' => true, 'options' => ['Follow Up']];
        $metadata[8] = ['type' => 'select', 'strict' => true, 'options' => ['Oasis Solo']];
        $metadata[9] = ['type' => 'select', 'strict' => true, 'options' => ['Sales Cabang']];
        $metadata[10] = ['type' => 'select', 'strict' => true, 'options' => [
            'No Respon', 'Diskusi', 'Tatap Muka', 'Cek Lokasi', 'UTJ', 'Tidak Lolos BI Checking', 'Cek Slik', 'Jadi Freelance', 'Akad',
        ]];
        $google = Mockery::mock(GoogleSheetsApiService::class);
        $google->shouldReceive('sheetTitles')->once()->with('sheet-status-alias')->andReturn(['lead']);
        $google->shouldReceive('sheetIds')->once()->with('sheet-status-alias')->andReturn(['lead' => 17]);
        $google->shouldReceive('quoteSheetName')->once()->with('lead')->andReturn("'lead'");
        $google->shouldReceive('batchGetRaw')->once()->with('sheet-status-alias', ["'lead'!1:2"], 'FORMATTED_VALUE')->andReturn(['lead' => [$headers, []]]);
        $google->shouldReceive('batchGetRaw')->once()->with('sheet-status-alias', ["'lead'!1:2"], 'FORMULA')->andReturn(['lead' => $formulas]);
        $google->shouldReceive('columnMetadata')->once()->with('sheet-status-alias', ['lead'])->andReturn(['lead' => $metadata]);
        $google->shouldReceive('makeColumnValidationWarningOnly')->once()->with('sheet-status-alias', 'lead', 17, 9);
        $contracts = new SalesLeadSpreadsheetContract($google);

        $resolved = $contracts->resolve($lead, 'lead');

        $this->assertSame('sumber_lead', $resolved->actualHeader('sumber_lead'));
        $this->assertSame('kanal_masuk', $resolved->actualHeader('kanal_masuk'));
        $this->assertSame('aktivitas_lead', $resolved->actualHeader('aktivitas_lead'));
        $this->assertSame([3, 4, 5], [
            $resolved->headerMap['sumber_lead'], $resolved->headerMap['kanal_masuk'], $resolved->headerMap['aktivitas_lead'],
        ]);
        $this->assertArrayNotHasKey('source', $resolved->headerMap);
        $this->assertSame('Cek Slik', $contracts->valueForWrite($resolved, 'status_lead', 'Cek SLIK'));
        $this->assertSame('Cek Slik', $contracts->valueForWrite($resolved, 'status_lead', 'Cek Silk'));
        $this->assertSame('Diskusi', $contracts->valueForWrite($resolved, 'status_lead', 'Diskusi'));
    }

    public function test_status_alias_fix_changelog_is_idempotent_and_visible(): void
    {
        $title = 'Status Lead Spreadsheet Lebih Kompatibel';
        $migration = require database_path('migrations/2026_08_03_000014_add_sales_lead_status_alias_fix_changelog.php');
        $migration->up();
        $migration->up();
        $superadmin = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'superadmin')->firstOrFail()->id,
            'password_changed_at' => now(),
        ]);

        $this->assertSame(1, DB::table('changelogs')->whereNull('version')->where('title', $title)->count());
        $this->actingAs($superadmin)->get(route('changelogs.index'))->assertOk()->assertSee($title);
    }

    public function test_two_branches_resolve_only_their_own_spreadsheet_ids(): void
    {
        [$firstLead] = $this->leadContext('sheet-alpha');
        [$secondLead] = $this->leadContext('sheet-beta');
        $google = Mockery::mock(GoogleSheetsApiService::class);
        $this->expectValidDataSalesResolution($google, 'sheet-alpha');
        $this->expectValidDataSalesResolution($google, 'sheet-beta');
        $contracts = new SalesLeadSpreadsheetContract($google);

        $this->assertSame('sheet-alpha', $contracts->resolve($firstLead, 'data_sales')->spreadsheetId);
        $this->assertSame('sheet-beta', $contracts->resolve($secondLead, 'data_sales')->spreadsheetId);
    }

    public function test_lead_contract_requires_physical_headers_without_alias_indirection(): void
    {
        [$lead] = $this->leadContext('sheet-live');
        $headers = ['id_lead', 'nama_promo', 'tanggal_lead', 'sumber_lead', 'kanal_masuk', 'aktivitas_lead', 'nama_konsumen', 'no_hp', 'proyek', 'sales_pic', 'status_lead', 'keterangan'];
        $google = Mockery::mock(GoogleSheetsApiService::class);
        $google->shouldReceive('sheetTitles')->once()->andReturn(['lead']);
        $google->shouldReceive('sheetIds')->once()->andReturn(['lead' => 7]);
        $google->shouldReceive('quoteSheetName')->once()->andReturn("'lead'");
        $google->shouldReceive('batchGetRaw')->once()->with('sheet-live', ["'lead'!1:2"], 'FORMATTED_VALUE')->andReturn(['lead' => [$headers, []]]);
        $google->shouldReceive('batchGetRaw')->once()->with('sheet-live', ["'lead'!1:2"], 'FORMULA')->andReturn(['lead' => [$headers, ['=ID()']]]);
        $google->shouldReceive('columnMetadata')->once()->andReturn(['lead' => $this->validLeadMetadata($headers)]);

        $resolved = (new SalesLeadSpreadsheetContract($google))->resolve($lead, 'lead');

        $this->assertSame('sumber_lead', $resolved->actualHeader('sumber_lead'));
        $this->assertSame('kanal_masuk', $resolved->actualHeader('kanal_masuk'));
        $this->assertSame('aktivitas_lead', $resolved->actualHeader('aktivitas_lead'));
        foreach (['source', 'platform', 'campaign_name'] as $canonical) {
            $this->assertArrayNotHasKey($canonical, $resolved->headerMap);
        }
    }

    public function test_lead_contract_rejects_canonical_names_as_physical_headers(): void
    {
        [$lead] = $this->leadContext('sheet-canonical');
        $headers = ['id_lead', 'nama_promo', 'tanggal_lead', 'source', 'platform', 'campaign_name', 'nama_konsumen', 'no_hp', 'proyek', 'sales_pic', 'status_lead', 'keterangan'];
        $google = Mockery::mock(GoogleSheetsApiService::class);
        $google->shouldReceive('sheetTitles')->once()->andReturn(['lead']);
        $google->shouldReceive('sheetIds')->once()->andReturn(['lead' => 7]);
        $google->shouldReceive('quoteSheetName')->once()->andReturn("'lead'");
        $google->shouldReceive('batchGetRaw')->once()->with('sheet-canonical', ["'lead'!1:2"], 'FORMATTED_VALUE')->andReturn(['lead' => [$headers, []]]);
        $google->shouldReceive('batchGetRaw')->once()->with('sheet-canonical', ["'lead'!1:2"], 'FORMULA')->andReturn(['lead' => [$headers, ['=ID()']]]);

        $contracts = new SalesLeadSpreadsheetContract($google);
        try {
            $contracts->resolve($lead, 'lead');
            $this->fail('Header kanonik diterima sebagai header fisik.');
        } catch (SalesLeadSpreadsheetContractException $exception) {
            $this->assertSame('Tab lead tidak memiliki header wajib: sumber_lead, kanal_masuk, aktivitas_lead.', $exception->getMessage());
        }
    }

    public function test_option_service_correlates_canonical_fields_without_extra_read(): void
    {
        [, $branch] = $this->leadContext('sheet-options');
        $definition = (new SalesLeadSpreadsheetContract(Mockery::mock(GoogleSheetsApiService::class)))->definitions()['lead'];
        $resolved = new ResolvedSalesLeadSpreadsheetContract('sheet-options', $definition, 1, [], [], [], 2, [
            'nama_promo' => ['No Promo'], 'id_promo' => ['No Promo'], 'sumber_lead' => ['Online'], 'kanal_masuk' => ['WA'], 'aktivitas_lead' => ['Follow Up'],
            'proyek' => ['Oasis'], 'sales_pic' => ['Sales A'], 'status_lead' => ['No Respon'],
        ]);
        $contracts = Mockery::mock(SalesLeadSpreadsheetContract::class);
        $contracts->shouldReceive('resolveForBranch')->once()->with($branch, 'lead')->andReturn($resolved);

        $options = (new SalesLeadSheetOptionService($contracts))->forBranch($branch);

        $this->assertSame(['No Promo', 'Online', 'WA', 'Follow Up', 'Oasis', 'Sales A', 'No Respon'], [
            $options['promo'][0], $options['source'][0], $options['channel'][0], $options['activity'][0],
            $options['project'][0], $options['sales'][0], $options['status'][0],
        ]);
    }

    public function test_option_service_caches_array_payload_and_reuses_it(): void
    {
        [, $branch] = $this->leadContext('sheet-options-cache');
        $definition = (new SalesLeadSpreadsheetContract(Mockery::mock(GoogleSheetsApiService::class)))->definitions()['lead'];
        $resolved = new ResolvedSalesLeadSpreadsheetContract('sheet-options-cache', $definition, 1, ['h1'], ['nama_promo' => 0], [], 2, ['sumber_lead' => ['Online']], []);
        $contracts = Mockery::mock(SalesLeadSpreadsheetContract::class);
        $contracts->shouldReceive('resolveForBranch')->once()->with($branch, 'lead')->andReturn($resolved);

        $service = new SalesLeadSheetOptionService($contracts);

        $first = $service->contract($branch);
        $this->assertInstanceOf(ResolvedSalesLeadSpreadsheetContract::class, $first);

        $second = $service->contract($branch);
        $this->assertSame('sheet-options-cache', $second->spreadsheetId);
        $this->assertSame('Online', $second->validationOptions['sumber_lead'][0]);
    }

    public function test_option_service_recovers_when_cache_holds_unexpected_payload(): void
    {
        [, $branch] = $this->leadContext('sheet-options-recover');
        $definition = (new SalesLeadSpreadsheetContract(Mockery::mock(GoogleSheetsApiService::class)))->definitions()['lead'];
        $resolved = new ResolvedSalesLeadSpreadsheetContract('sheet-options-recover', $definition, 1, [], [], [], 2, ['sumber_lead' => ['Referral']], []);
        $contracts = Mockery::mock(SalesLeadSpreadsheetContract::class);
        $contracts->shouldReceive('resolveForBranch')->once()->with($branch, 'lead')->andReturn($resolved);

        $key = 'sales-lead-sheet-options:v2:'.$branch->id.':'.hash('sha256', (string) $branch->sheet_id);
        Cache::put($key, new \stdClass, now()->addSeconds(60));

        $service = new SalesLeadSheetOptionService($contracts);
        $contract = $service->contract($branch);

        $this->assertInstanceOf(ResolvedSalesLeadSpreadsheetContract::class, $contract);
        $this->assertSame('Referral', $contract->validationOptions['sumber_lead'][0]);
        $this->assertIsArray(Cache::get($key));
    }

    public function test_lead_contract_resolves_production_magelang_physical_header_layout(): void
    {
        $headers = [
            'id_lead', 'nama_promo', 'tanggal_lead', 'sumber_lead', 'kanal_masuk', 'aktivitas_lead',
            'nama_konsumen', 'no_hp', 'proyek', 'sales_pic', 'status_lead', 'keterangan',
        ];
        [$lead] = $this->leadContext('sheet-magelang-fixture');
        $google = Mockery::mock(GoogleSheetsApiService::class);
        $google->shouldReceive('sheetTitles')->once()->andReturn(['lead']);
        $google->shouldReceive('sheetIds')->once()->andReturn(['lead' => 11]);
        $google->shouldReceive('quoteSheetName')->once()->andReturn("'lead'");
        $google->shouldReceive('batchGetRaw')->once()->with('sheet-magelang-fixture', ["'lead'!1:2"], 'FORMATTED_VALUE')->andReturn(['lead' => [$headers, []]]);
        $google->shouldReceive('batchGetRaw')->once()->with('sheet-magelang-fixture', ["'lead'!1:2"], 'FORMULA')->andReturn(['lead' => [$headers, ['=ID()']]]);
        $google->shouldReceive('columnMetadata')->once()->andReturn(['lead' => $this->validLeadMetadata($headers)]);

        $resolved = (new SalesLeadSpreadsheetContract($google))->resolve($lead, 'lead');

        $this->assertSame(['sumber_lead', 'kanal_masuk', 'aktivitas_lead'], array_map(
            fn (string $header) => $resolved->actualHeader($header),
            ['sumber_lead', 'kanal_masuk', 'aktivitas_lead'],
        ));
        $this->assertSame(0, $resolved->headerMap['id_lead']);
        $this->assertSame(3, $resolved->headerMap['sumber_lead']);
        $this->assertSame(4, $resolved->headerMap['kanal_masuk']);
        $this->assertSame(5, $resolved->headerMap['aktivitas_lead']);
    }

    public function test_lead_write_boundary_maps_internal_fields_to_physical_headers(): void
    {
        [$lead, $branch] = $this->leadContext('sheet-write-boundary');
        $project = $lead->project()->first();
        $sales = $lead->sales()->first();
        $lead->source = 'Online';
        $lead->platform = 'Instagram';
        $lead->campaign_name = 'Follow Up';
        $lead->save();

        $project->update([
            'project_name' => 'Canonical OASIS Project',
            'sheet_project_name' => 'Sheet Project',
        ]);
        $options = Mockery::mock(SalesLeadSheetOptionService::class);
        $options->shouldReceive('forBranch')->times(3)->andReturn([
            'project' => ['Sheet Project', 'Canonical OASIS Project'],
        ]);
        $options->shouldReceive('exactOption')->twice()->with(['Sheet Project', 'Canonical OASIS Project'], 'Sheet Project')->andReturn('Sheet Project');
        $options->shouldReceive('exactOption')->once()->with(['Sheet Project', 'Canonical OASIS Project'], 'Canonical OASIS Project')->andReturn('Canonical OASIS Project');
        $sheetIdentities = new SalesSheetIdentityService($options);
        $sales->update(['name' => 'Canonical OASIS Sales']);
        $service = new SalesLeadService(
            Mockery::mock(PhoneNormalizationService::class),
            Mockery::mock(SalesLeadLifecycleService::class),
            $sheetIdentities,
        );

        $fields = $service->spreadsheetFields($lead->fresh());

        $this->assertSame('Canonical OASIS Project', $project->fresh()->project_name);
        $this->assertSame('Sheet Project', $project->fresh()->sheet_project_name);
        $this->assertSame('Sheet Project', $sheetIdentities->projectValue($project->fresh()));
        $this->assertSame('Sheet Project', $fields['proyek']);
        $this->assertSame('Online', $fields['sumber_lead']);
        $this->assertSame('Instagram', $fields['kanal_masuk']);
        $this->assertSame('Follow Up', $fields['aktivitas_lead']);
        $this->assertSame('Canonical OASIS Sales', $fields['sales_pic']);
        $this->assertArrayNotHasKey('source', $fields);
        $this->assertArrayNotHasKey('platform', $fields);
        $this->assertArrayNotHasKey('campaign_name', $fields);

        $project->update(['sheet_project_name' => null]);
        $fallbackFields = $service->spreadsheetFields($lead->fresh());

        $this->assertSame('Canonical OASIS Project', $fallbackFields['proyek']);
        $this->assertSame('Canonical OASIS Sales', $fallbackFields['sales_pic']);
    }

    public function test_cross_branch_lead_resolution_uses_lead_branch_not_project_or_user_branch(): void
    {
        [$lead, $leadBranch] = $this->leadContext('sheet-lead');
        $otherBranch = Branch::create(['name' => 'Other Branch', 'code' => 'OB'.Str::random(4), 'sheet_id' => 'sheet-other', 'is_active' => true]);
        $lead->sales()->associate(User::factory()->create(['branch_id' => $otherBranch->id]));
        $lead->save();
        $this->assertSame($leadBranch->id, $lead->branch_id);

        $google = Mockery::mock(GoogleSheetsApiService::class);
        $this->expectValidDataSalesResolution($google, 'sheet-lead');

        $resolved = (new SalesLeadSpreadsheetContract($google))->resolve($lead, 'data_sales');

        $this->assertSame('sheet-lead', $resolved->spreadsheetId);
    }

    public function test_missing_or_inactive_branch_spreadsheet_fails_in_indonesian(): void
    {
        [$lead, $branch] = $this->leadContext(null);
        $google = Mockery::mock(GoogleSheetsApiService::class);
        $contracts = new SalesLeadSpreadsheetContract($google);

        try {
            $contracts->resolve($lead, 'data_sales');
            $this->fail('Spreadsheet kosong diterima.');
        } catch (SalesLeadSpreadsheetContractException $exception) {
            $this->assertSame('Spreadsheet cabang belum dikonfigurasi.', $exception->getMessage());
        }

        $branch->update(['sheet_id' => 'sheet-disabled', 'is_active' => false]);
        $this->expectExceptionMessage('Cabang lead tidak tersedia atau sudah tidak aktif.');
        $contracts->resolve($lead, 'data_sales');
    }

    public function test_missing_sheet_is_reported_without_fallback(): void
    {
        [$lead] = $this->leadContext('sheet-no-tab');
        $google = Mockery::mock(GoogleSheetsApiService::class);
        $google->shouldReceive('sheetTitles')->once()->with('sheet-no-tab')->andReturn(['lead']);

        $this->expectException(SalesLeadSpreadsheetContractException::class);
        $this->expectExceptionMessage('Tab data_sales tidak ditemukan pada spreadsheet cabang.');
        (new SalesLeadSpreadsheetContract($google))->resolve($lead, 'data_sales');
    }

    public function test_missing_header_is_reported_and_extra_branch_headers_are_accepted(): void
    {
        [$lead] = $this->leadContext('sheet-schema');
        $missingGoogle = Mockery::mock(GoogleSheetsApiService::class);
        $this->expectResolutionReads($missingGoogle, 'sheet-schema', ['nik_sales', 'nama_sales', 'nama_koordinator']);

        try {
            (new SalesLeadSpreadsheetContract($missingGoogle))->resolve($lead, 'data_sales');
            $this->fail('Header wajib yang hilang diterima.');
        } catch (SalesLeadSpreadsheetContractException $exception) {
            $this->assertStringContainsString('nik_koordinator', $exception->getMessage());
        }

        $extraGoogle = Mockery::mock(GoogleSheetsApiService::class);
        $this->expectResolutionReads($extraGoogle, 'sheet-schema', [
            'nik_sales', 'nama_sales', 'nik_koordinator', 'nama_koordinator', 'kolom_cabang',
        ], expectMetadata: true);

        $resolved = (new SalesLeadSpreadsheetContract($extraGoogle))->resolve($lead, 'data_sales');
        $this->assertSame(4, $resolved->headerMap['kolom_cabang']);
    }

    public function test_writer_appends_metadata_with_business_fields_and_excludes_unknown_fields(): void
    {
        [$lead] = $this->leadContext('sheet-write');
        $syncId = (string) Str::uuid();
        $headers = ['nik_sales', 'nama_sales', 'nik_koordinator', 'nama_koordinator'];
        $allHeaders = [...$headers, ...SalesLeadSpreadsheetContract::META_HEADERS];
        $google = Mockery::mock(GoogleSheetsApiService::class);
        $this->expectResolutionReads($google, 'sheet-write', $headers, expectMetadata: true);
        $google->shouldReceive('ensureTrailingMetadataColumns')->once()->andReturn($allHeaders);
        $google->shouldReceive('findRowByHeaderValue')->once()->andReturnNull();
        $google->shouldReceive('appendRows')->once()->withArgs(function ($spreadsheetId, $range, $rows) use ($syncId): bool {
            return $spreadsheetId === 'sheet-write'
                && str_contains($range, 'data_sales')
                && $rows[0][1] === 'Sales Cabang'
                && $rows[0][4] === $syncId
                && ! in_array('ignored', $rows[0], true);
        })->andReturn(new GoogleSheetsAppendResult("'data_sales'!A8:G8", 8));

        $result = (new SalesLeadSpreadsheetWriter($google, new SalesLeadSpreadsheetContract($google)))
            ->append($lead, 'data_sales', ['nama_sales' => 'Sales Cabang', 'unknown' => 'ignored'], $syncId);

        $this->assertSame('sheet-write', $result->spreadsheetId);
        $this->assertSame(8, $result->rowNumber);
        $this->assertSame($syncId, $result->syncId);
    }

    public function test_idempotent_retry_returns_existing_row_without_append(): void
    {
        [$lead] = $this->leadContext('sheet-retry');
        $syncId = (string) Str::uuid();
        $google = $this->writerGoogle('sheet-retry');
        $google->shouldReceive('findRowByHeaderValue')->once()->withArgs(fn (...$args) => end($args) === $syncId)->andReturn(12);
        $google->shouldNotReceive('appendRows');

        $result = (new SalesLeadSpreadsheetWriter($google, new SalesLeadSpreadsheetContract($google)))
            ->append($lead, 'data_sales', [], $syncId);

        $this->assertSame(12, $result->rowNumber);
    }

    public function test_writer_never_writes_formula_owned_fields_and_copies_verified_template(): void
    {
        [$lead] = $this->leadContext('sheet-formula');
        $syncId = (string) Str::uuid();
        $definition = new SalesLeadSheetDefinition('lead', ['id_lead', 'nama_konsumen'], ['id_lead']);
        $resolved = new ResolvedSalesLeadSpreadsheetContract(
            'sheet-formula',
            $definition,
            91,
            ['id_lead', 'nama_konsumen'],
            ['id_lead' => 0, 'nama_konsumen' => 1],
            ['id_lead'],
            2,
        );
        $contracts = Mockery::mock(SalesLeadSpreadsheetContract::class);
        $contracts->shouldReceive('resolve')->once()->with($lead, 'lead')->andReturn($resolved);
        $contracts->shouldReceive('assertStrictValues')->once()->with($resolved, Mockery::type('array'));
        $contracts->shouldReceive('valueForWrite')->andReturnUsing(fn ($contract, $header, $value) => $value);
        $google = Mockery::mock(GoogleSheetsApiService::class);
        $headers = ['id_lead', 'nama_konsumen', ...SalesLeadSpreadsheetContract::META_HEADERS];
        $google->shouldReceive('ensureTrailingMetadataColumns')->once()->andReturn($headers);
        $google->shouldReceive('findRowByHeaderValue')->once()->andReturnNull();
        $google->shouldReceive('quoteSheetName')->twice()->with('lead')->andReturn("'lead'");
        $google->shouldReceive('appendRows')->once()->withArgs(function ($spreadsheetId, $range, $rows) use ($syncId): bool {
            return $rows[0][0] === null
                && $rows[0][1] === 'Nama Aman'
                && $rows[0][2] === $syncId;
        })->andReturn(new GoogleSheetsAppendResult("'lead'!A6:E6", 6));
        $google->shouldReceive('copyRowFormat')->once()->with('sheet-formula', 91, 2, 6);
        $google->shouldReceive('copyRowFormulas')->once()->with('sheet-formula', 91, 2, 6);
        $google->shouldReceive('batchGetRaw')->once()->with('sheet-formula', ["'lead'!6:6"], 'FORMATTED_VALUE')->andReturn([
            'lead' => [['260803-ON-TI-01', 'Nama Aman', $syncId]],
        ]);

        $result = (new SalesLeadSpreadsheetWriter($google, $contracts))->append(
            $lead,
            'lead',
            ['id_lead' => 'tidak-boleh-ditulis', 'nama_konsumen' => 'Nama Aman'],
            $syncId,
        );

        $this->assertSame(6, $result->rowNumber);
        $this->assertSame('260803-ON-TI-01', $result->rowValues['id_lead']);
    }

    public function test_writer_uses_the_exact_status_option_resolved_for_the_branch(): void
    {
        [$lead] = $this->leadContext('sheet-status-write');
        $syncId = (string) Str::uuid();
        $definition = new SalesLeadSheetDefinition('lead', ['status_lead']);
        $resolved = new ResolvedSalesLeadSpreadsheetContract(
            'sheet-status-write',
            $definition,
            18,
            ['status_lead'],
            ['status_lead' => 0],
            [],
            2,
            ['status_lead' => ['Cek Slik']],
        );
        $contracts = Mockery::mock(SalesLeadSpreadsheetContract::class);
        $contracts->shouldReceive('resolve')->once()->with($lead, 'lead')->andReturn($resolved);
        $contracts->shouldReceive('assertStrictValues')->once()->with($resolved, ['status_lead' => 'Cek SLIK']);
        $contracts->shouldReceive('valueForWrite')->once()->with($resolved, 'status_lead', 'Cek SLIK')->andReturn('Cek Slik');
        $google = Mockery::mock(GoogleSheetsApiService::class);
        $headers = ['status_lead', ...SalesLeadSpreadsheetContract::META_HEADERS];
        $google->shouldReceive('ensureTrailingMetadataColumns')->once()->andReturn($headers);
        $google->shouldReceive('findRowByHeaderValue')->once()->andReturnNull();
        $google->shouldReceive('quoteSheetName')->twice()->with('lead')->andReturn("'lead'");
        $google->shouldReceive('appendRows')->once()->withArgs(fn ($spreadsheetId, $range, $rows) => $rows[0][0] === 'Cek Slik' && $rows[0][1] === $syncId)
            ->andReturn(new GoogleSheetsAppendResult("'lead'!A5:D5", 5));
        $google->shouldReceive('batchGetRaw')->once()->andReturn(['lead' => [['Cek Slik', $syncId]]]);

        (new SalesLeadSpreadsheetWriter($google, $contracts))->append(
            $lead,
            'lead',
            ['status_lead' => 'Cek SLIK'],
            $syncId,
        );
    }

    public function test_writer_places_physical_fields_in_the_resolved_live_header_columns(): void
    {
        [$lead] = $this->leadContext('sheet-live-write');
        $syncId = (string) Str::uuid();
        $definition = new SalesLeadSheetDefinition(
            'lead',
            ['sumber_lead', 'kanal_masuk', 'aktivitas_lead'],
            [],
            [],
            [],
        );
        $physicalHeaders = ['sumber_lead', 'kanal_masuk', 'aktivitas_lead', '', 'helper'];
        $resolved = new ResolvedSalesLeadSpreadsheetContract(
            'sheet-live-write',
            $definition,
            19,
            $physicalHeaders,
            ['sumber_lead' => 0, 'kanal_masuk' => 1, 'aktivitas_lead' => 2],
            [],
            2,
            [],
            [],
        );
        $contracts = Mockery::mock(SalesLeadSpreadsheetContract::class);
        $contracts->shouldReceive('resolve')->once()->andReturn($resolved);
        $contracts->shouldReceive('assertStrictValues')->once();
        $contracts->shouldReceive('valueForWrite')->times(3)->andReturnUsing(fn ($contract, $header, $value) => $value);
        $google = Mockery::mock(GoogleSheetsApiService::class);
        $allHeaders = [...$physicalHeaders, ...SalesLeadSpreadsheetContract::META_HEADERS];
        $google->shouldReceive('ensureTrailingMetadataColumns')->once()->andReturn($allHeaders);
        $google->shouldReceive('findRowByHeaderValue')->once()->andReturnNull();
        $google->shouldReceive('quoteSheetName')->twice()->with('lead')->andReturn("'lead'");
        $google->shouldReceive('appendRows')->once()->withArgs(fn ($spreadsheetId, $range, $rows) => $rows[0][0] === 'Online'
            && $rows[0][1] === 'Instagram'
            && $rows[0][2] === 'Story'
            && $rows[0][3] === null
            && $rows[0][5] === $syncId)->andReturn(new GoogleSheetsAppendResult("'lead'!A9:H9", 9));
        $google->shouldReceive('batchGetRaw')->once()->andReturn(['lead' => [['Online', 'Instagram', 'Story', '', '', $syncId]]]);

        (new SalesLeadSpreadsheetWriter($google, $contracts))->append($lead, 'lead', [
            'sumber_lead' => 'Online',
            'kanal_masuk' => 'Instagram',
            'aktivitas_lead' => 'Story',
        ], $syncId);
    }

    public function test_writer_rejects_invalid_strict_value_before_metadata_or_append(): void
    {
        [$lead] = $this->leadContext('sheet-strict');
        $google = Mockery::mock(GoogleSheetsApiService::class);
        $google->shouldNotReceive('ensureTrailingMetadataColumns');
        $google->shouldNotReceive('appendRows');
        $definition = new SalesLeadSheetDefinition('lead', ['sumber_lead'], [], ['sumber_lead' => ['type' => 'select', 'strict' => true]]);
        $resolved = new ResolvedSalesLeadSpreadsheetContract('sheet-strict', $definition, 1, ['sumber_lead'], ['sumber_lead' => 0], [], 2, ['sumber_lead' => ['Online']], []);
        $contracts = Mockery::mock(SalesLeadSpreadsheetContract::class, [$google])->makePartial();
        $contracts->shouldReceive('resolve')->once()->andReturn($resolved);

        $this->expectException(SalesLeadSpreadsheetContractException::class);
        $this->expectExceptionMessage('Nilai sumber_lead tidak tersedia');
        (new SalesLeadSpreadsheetWriter($google, $contracts))->append($lead, 'lead', ['sumber_lead' => 'Tidak Valid'], (string) Str::uuid());
    }

    public function test_metadata_provision_hides_only_newly_verified_exact_trailing_columns(): void
    {
        $google = new class extends GoogleSheetsApiService
        {
            public array $updated = [];

            public array $hidden = [];

            public function __construct() {}

            public function updateRange(string $spreadsheetId, string $range, array $values): void
            {
                $this->updated = [$spreadsheetId, $range, $values];
            }

            public function batchGetRaw(string $spreadsheetId, array $ranges, string $valueRenderOption = 'FORMATTED_VALUE'): array
            {
                return ['data_sales' => [[
                    'nik_sales',
                    'kolom_cabang_tersembunyi',
                    ...SalesLeadSpreadsheetContract::META_HEADERS,
                ]]];
            }

            public function hideColumns(string $spreadsheetId, int $sheetId, int $startIndex, int $endIndex): void
            {
                $this->hidden = [$spreadsheetId, $sheetId, $startIndex, $endIndex];
            }
        };

        $headers = $google->ensureTrailingMetadataColumns(
            'sheet-metadata',
            'data_sales',
            73,
            ['nik_sales', 'kolom_cabang_tersembunyi'],
            SalesLeadSpreadsheetContract::META_HEADERS,
        );

        $this->assertSame(['sheet-metadata', "'data_sales'!C1:E1", [SalesLeadSpreadsheetContract::META_HEADERS]], $google->updated);
        $this->assertSame(['sheet-metadata', 73, 2, 5], $google->hidden);
        $this->assertSame('kolom_cabang_tersembunyi', $headers[1]);
    }

    public function test_lead_metadata_is_appended_after_blank_separator_and_helper_columns(): void
    {
        $google = new class extends GoogleSheetsApiService
        {
            public array $updated = [];

            public function __construct() {}

            public function updateRange(string $spreadsheetId, string $range, array $values): void
            {
                $this->updated = [$range, $values];
            }

            public function batchGetRaw(string $spreadsheetId, array $ranges, string $valueRenderOption = 'FORMATTED_VALUE'): array
            {
                return ['lead' => [[...array_fill(0, 12, 'header'), '', 'Sumber', 'Kanal', 'Aktivitas', ...SalesLeadSpreadsheetContract::META_HEADERS]]];
            }

            public function hideColumns(string $spreadsheetId, int $sheetId, int $startIndex, int $endIndex): void {}
        };
        $headers = [...array_fill(0, 12, 'header'), '', 'Sumber', 'Kanal', 'Aktivitas'];

        $google->ensureTrailingMetadataColumns('sheet', 'lead', 1, $headers, SalesLeadSpreadsheetContract::META_HEADERS);

        $this->assertSame("'lead'!Q1:S1", $google->updated[0]);
    }

    public function test_uncertain_append_success_is_reconciled_by_sync_id(): void
    {
        [$lead] = $this->leadContext('sheet-uncertain');
        $syncId = (string) Str::uuid();
        $google = $this->writerGoogle('sheet-uncertain');
        $google->shouldReceive('findRowByHeaderValue')->twice()->andReturn(null, 19);
        $google->shouldReceive('appendRows')->once()->andThrow(new RuntimeException('timeout'));

        $result = (new SalesLeadSpreadsheetWriter($google, new SalesLeadSpreadsheetContract($google)))
            ->append($lead, 'data_sales', [], $syncId);

        $this->assertSame(19, $result->rowNumber);
        $this->assertSame($syncId, $result->syncId);
    }

    public function test_writer_keeps_branch_spreadsheets_isolated(): void
    {
        [$firstLead] = $this->leadContext('sheet-one');
        [$secondLead] = $this->leadContext('sheet-two');
        $google = Mockery::mock(GoogleSheetsApiService::class);
        foreach (['sheet-one' => 5, 'sheet-two' => 7] as $spreadsheetId => $row) {
            $headers = ['nik_sales', 'nama_sales', 'nik_koordinator', 'nama_koordinator'];
            $this->expectResolutionReads($google, $spreadsheetId, $headers, expectMetadata: true);
            $google->shouldReceive('ensureTrailingMetadataColumns')->once()->with($spreadsheetId, Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any())->andReturn([...$headers, ...SalesLeadSpreadsheetContract::META_HEADERS]);
            $google->shouldReceive('findRowByHeaderValue')->once()->with($spreadsheetId, Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any())->andReturnNull();
            $google->shouldReceive('appendRows')->once()->with($spreadsheetId, Mockery::any(), Mockery::any())->andReturn(new GoogleSheetsAppendResult("'data_sales'!A{$row}:G{$row}", $row));
        }
        $writer = new SalesLeadSpreadsheetWriter($google, new SalesLeadSpreadsheetContract($google));

        $first = $writer->append($firstLead, 'data_sales', [], (string) Str::uuid());
        $second = $writer->append($secondLead, 'data_sales', [], (string) Str::uuid());

        $this->assertSame(['sheet-one', 'sheet-two'], [$first->spreadsheetId, $second->spreadsheetId]);
    }

    public function test_writer_updates_remote_fields_by_stable_sync_id_instead_of_stale_row_number(): void
    {
        [$lead] = $this->leadContext('sheet-stable-update');
        $syncId = (string) Str::uuid();
        $google = $this->writerGoogle('sheet-stable-update');
        $google->shouldReceive('findRowByHeaderValue')->once()->withArgs(fn (...$args) => end($args) === $syncId)->andReturn(27);
        $google->shouldReceive('batchUpdateRanges')->once()->withArgs(function (string $spreadsheetId, array $ranges): bool {
            return $spreadsheetId === 'sheet-stable-update'
                && $ranges === [[
                    'range' => "'data_sales'!D27",
                    'values' => [['Nama Koordinator Baru']],
                ]];
        });

        $result = (new SalesLeadSpreadsheetWriter($google, new SalesLeadSpreadsheetContract($google)))
            ->updateBySyncId($lead, 'data_sales', $syncId, [
                'nama_koordinator' => 'Nama Koordinator Baru',
                'oasis_sync_id' => 'tidak-boleh-diubah',
                'unknown' => 'diabaikan',
            ]);

        $this->assertSame(27, $result->rowNumber);
        $this->assertSame($syncId, $result->syncId);
    }

    public function test_application_source_contains_no_solo_or_reference_spreadsheet_fallback(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));
        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            $this->assertDoesNotMatchRegularExpression('/solo.{0,40}(sheet|spreadsheet)|spreadsheet.{0,40}solo/i', $contents, $file->getPathname());
        }
    }

    private function writerGoogle(string $spreadsheetId): MockInterface
    {
        $headers = ['nik_sales', 'nama_sales', 'nik_koordinator', 'nama_koordinator'];
        $google = Mockery::mock(GoogleSheetsApiService::class);
        $this->expectResolutionReads($google, $spreadsheetId, $headers, expectMetadata: true);
        $google->shouldReceive('ensureTrailingMetadataColumns')->once()->andReturn([...$headers, ...SalesLeadSpreadsheetContract::META_HEADERS]);

        return $google;
    }

    private function expectValidDataSalesResolution(MockInterface $google, string $spreadsheetId): void
    {
        $this->expectResolutionReads(
            $google,
            $spreadsheetId,
            ['nik_sales', 'nama_sales', 'nik_koordinator', 'nama_koordinator'],
            expectMetadata: true,
        );
    }

    private function expectResolutionReads(
        MockInterface $google,
        string $spreadsheetId,
        array $headers,
        bool $expectMetadata = false,
    ): void {
        $google->shouldReceive('sheetTitles')->once()->with($spreadsheetId)->andReturn(['data_sales']);
        $google->shouldReceive('sheetIds')->once()->with($spreadsheetId)->andReturn(['data_sales' => 42]);
        $google->shouldReceive('quoteSheetName')->with('data_sales')->andReturn("'data_sales'");
        $google->shouldReceive('batchGetRaw')->once()->with($spreadsheetId, ["'data_sales'!1:2"], 'FORMATTED_VALUE')->andReturn(['data_sales' => [$headers, []]]);
        $google->shouldReceive('batchGetRaw')->once()->with($spreadsheetId, ["'data_sales'!1:2"], 'FORMULA')->andReturn(['data_sales' => [$headers, []]]);
        if ($expectMetadata) {
            $google->shouldReceive('columnMetadata')->once()->with($spreadsheetId, ['data_sales'])->andReturn([]);
        }
    }

    private function leadContext(?string $spreadsheetId): array
    {
        $branch = Branch::create([
            'name' => 'Branch '.Str::random(8),
            'code' => strtoupper(Str::random(6)),
            'sheet_id' => $spreadsheetId,
            'is_active' => true,
        ]);
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Project '.Str::random(8)]);
        $user = User::factory()->create(['branch_id' => $branch->id]);
        $lead = SalesLead::create([
            'branch_id' => $branch->id,
            'project_id' => $project->id,
            'sales_user_id' => $user->id,
            'lead_date' => '2026-08-03',
            'customer_name' => 'Lead '.Str::random(8),
        ]);

        return [$lead, $branch];
    }

    private function validLeadMetadata(array $headers): array
    {
        $metadata = array_fill(0, count($headers), null);
        foreach (['nama_promo', $headers[3], $headers[4], $headers[5], 'proyek'] as $header) {
            $metadata[array_search($header, $headers, true)] = ['type' => 'select', 'strict' => true, 'options' => ['Option']];
        }
        $metadata[array_search('sales_pic', $headers, true)] = ['type' => 'select', 'strict' => false, 'options' => ['Option']];
        $metadata[array_search('tanggal_lead', $headers, true)] = ['type' => 'date', 'strict' => false, 'options' => []];
        $metadata[array_search('status_lead', $headers, true)] = ['type' => 'select', 'strict' => true, 'options' => ['No Respon', 'Diskusi', 'Tatap Muka', 'Cek Lokasi', 'UTJ', 'Tidak Lolos BI Checking', 'Cek Slik', 'Jadi Freelance', 'Akad']];

        return $metadata;
    }
}
