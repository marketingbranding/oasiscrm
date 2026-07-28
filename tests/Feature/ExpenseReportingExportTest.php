<?php

namespace Tests\Feature;

use App\Models\Changelog;
use App\Models\Expense;
use App\Models\LeadMaster;
use App\Services\ExpenseFilterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Feature\Concerns\BuildsExpenseFixtures;
use Tests\TestCase;

class ExpenseReportingExportTest extends TestCase
{
    use BuildsExpenseFixtures, RefreshDatabase;

    public function test_month_year_period_month_and_custom_range_precedence_filter_dates(): void
    {
        $user = $this->expenseUser('pusat');
        $july = $this->expense(['expense_date' => '2026-07-10', 'description' => 'JULY MATCH']);
        $this->expense(['expense_date' => '2026-06-10', 'description' => 'JUNE ONLY']);
        $custom = $this->expense(['expense_date' => '2026-05-12', 'description' => 'CUSTOM MATCH']);

        $this->assertSame([$july->id], $this->filteredIds(['month' => 7, 'year' => 2026]));
        $this->assertSame([$july->id], $this->filteredIds(['period_month' => '2026-07']));
        $this->assertSame([$custom->id], $this->filteredIds([
            'period_month' => '2026-07', 'date_from' => '2026-05-10', 'date_to' => '2026-05-15',
        ]));

        $response = $this->actingAs($user)->get(route('expenses.index', [
            'period_month' => '2026-07', 'date_from' => '2026-05-10', 'date_to' => '2026-05-15',
        ]))->assertOk();
        $response->assertSee('CUSTOM MATCH')->assertDontSee('JULY MATCH');
        $this->assertSame('custom', $response->viewData('filters')['period_type']);
    }

    public function test_each_domain_filter_and_search_excludes_its_nonmatching_record(): void
    {
        $branch = $this->expenseBranch('Filter Branch');
        $otherBranch = $this->expenseBranch('Other Branch');
        $project = $this->expenseProject($branch, 'Filter Project');
        $otherProject = $this->expenseProject($branch, 'Other Project');
        $category = $this->expenseCategory('Filter Category');
        $otherCategory = $this->expenseCategory('Other Category');
        $creator = $this->expenseUser('pusat', $branch, 'Filter Creator');
        $otherCreator = $this->expenseUser('pusat', $branch, 'Other Creator');
        $target = $this->expense(compact('branch', 'project', 'category', 'creator') + [
            'description' => 'Needle description',
            'vendor_name' => 'Needle Vendor',
            'reference_number' => 'NEEDLE-REF',
            'payment_method' => 'transfer',
        ]);

        $cases = [
            [['branch_id' => $branch->id], ['branch' => $otherBranch, 'description' => 'Wrong branch']],
            [['project_id' => $project->id], ['branch' => $branch, 'project' => $otherProject, 'description' => 'Wrong project']],
            [['expense_category_id' => $category->id], ['category' => $otherCategory, 'description' => 'Wrong category']],
            [['payment_method' => 'transfer'], ['payment_method' => 'tunai', 'description' => 'Wrong payment']],
            [['created_by' => $creator->id], ['creator' => $otherCreator, 'description' => 'Wrong creator']],
            [['status' => Expense::STATUS_ACTIVE], ['status' => Expense::STATUS_CANCELLED, 'description' => 'Wrong status']],
            [['search' => 'NEEDLE-REF'], ['reference_number' => 'OTHER-REF', 'description' => 'Wrong search']],
        ];

        foreach ($cases as [$filter, $decoyOverrides]) {
            $decoy = $this->expense(array_merge(compact('branch', 'project', 'category', 'creator'), $decoyOverrides));
            $ids = $this->filteredIds($filter + ['period_month' => '2026-07']);
            $this->assertContains($target->id, $ids);
            $this->assertNotContains($decoy->id, $ids);
        }
    }

    public function test_status_all_includes_cancelled_while_default_and_soft_delete_exclude_rows(): void
    {
        $active = $this->expense(['description' => 'Active row']);
        $cancelled = $this->expense(['description' => 'Cancelled row', 'status' => Expense::STATUS_CANCELLED]);
        $deleted = $this->expense(['description' => 'Deleted row']);
        $deleted->delete();

        $defaultIds = $this->filteredIds(['period_month' => '2026-07']);
        $this->assertSame([$active->id], $defaultIds);
        $allIds = $this->filteredIds(['period_month' => '2026-07', 'status' => 'all']);
        $this->assertEqualsCanonicalizing([$active->id, $cancelled->id], $allIds);
        $this->assertNotContains($deleted->id, $allIds);
    }

    public function test_invalid_filter_ids_fail_closed_in_index_and_export(): void
    {
        $user = $this->expenseUser('pusat');
        $this->expense(['description' => 'Must not leak']);

        $response = $this->actingAs($user)->get(route('expenses.index', [
            'period_month' => '2026-07', 'branch_id' => 999999,
        ]))->assertOk();
        $response->assertDontSee('Must not leak');

        $this->actingAs($user)->get(route('expenses.export', [
            'period_month' => '2026-07', 'branch_id' => 999999,
        ]))->assertRedirect()->assertSessionHas('warning');
    }

    public function test_single_custom_date_bound_is_applied_within_its_month(): void
    {
        $included = $this->expense(['expense_date' => '2026-07-20', 'description' => 'Included bound']);
        $this->expense(['expense_date' => '2026-07-10', 'description' => 'Before bound']);

        $this->assertSame([$included->id], $this->filteredIds(['date_from' => '2026-07-15']));
    }

    public function test_summary_uses_active_selected_period_top_groups_and_previous_month_comparison(): void
    {
        $branchTop = $this->expenseBranch('Cabang Teratas');
        $branchOther = $this->expenseBranch('Cabang Lain');
        $projectTop = $this->expenseProject($branchTop, 'Proyek Teratas');
        $projectOther = $this->expenseProject($branchOther, 'Proyek Lain');
        $categoryTop = $this->expenseCategory('Kategori Teratas');
        $categoryOther = $this->expenseCategory('Kategori Lain');
        $creator = $this->expenseUser('pusat', $branchTop);
        $this->expense(['branch' => $branchTop, 'project' => $projectTop, 'category' => $categoryTop, 'creator' => $creator, 'amount' => 600, 'description' => 'Top one']);
        $this->expense(['branch' => $branchTop, 'project' => $projectTop, 'category' => $categoryTop, 'creator' => $creator, 'amount' => 400, 'description' => 'Top two']);
        $this->expense(['branch' => $branchOther, 'project' => $projectOther, 'category' => $categoryOther, 'creator' => $creator, 'amount' => 200, 'description' => 'Other']);
        $this->expense(['branch' => $branchTop, 'project' => $projectTop, 'category' => $categoryTop, 'creator' => $creator, 'amount' => 9000, 'description' => 'Cancelled', 'status' => Expense::STATUS_CANCELLED]);
        $this->expense(['branch' => $branchTop, 'project' => $projectTop, 'category' => $categoryTop, 'creator' => $creator, 'expense_date' => '2026-06-10', 'amount' => 600, 'description' => 'Previous']);

        $filters = app(ExpenseFilterService::class)->normalize(['period_month' => '2026-07', 'status' => 'all']);
        $summary = app(ExpenseFilterService::class)->summary($filters);
        $this->assertSame(1200.0, $summary['total']);
        $this->assertSame(3, $summary['count']);
        $this->assertSame('Kategori Teratas', $summary['top_category']['label']);
        $this->assertSame('Cabang Teratas', $summary['top_branch']['label']);
        $this->assertSame('Proyek Teratas', $summary['top_project']['label']);
        $this->assertSame(600.0, $summary['previous_total']);
        $this->assertSame(100.0, $summary['comparison_percent']);
    }

    public function test_previous_zero_summary_renders_dash_without_division_error(): void
    {
        $user = $this->expenseUser('pusat');
        $this->expense(['amount' => 500]);

        $response = $this->actingAs($user)->get(route('expenses.index', ['period_month' => '2026-07']))->assertOk();
        $this->assertNull($response->viewData('summary')['comparison_percent']);
        $response->assertSee('—')->assertSee('Sebelumnya Rp0');
    }

    public function test_index_persists_filters_and_direct_click_sort_state(): void
    {
        $user = $this->expenseUser('pusat');
        $category = $this->expenseCategory('Persist Category');
        $expense = $this->expense(['category' => $category, 'description' => 'Persist Search']);
        foreach (range(1, 10) as $index) {
            $this->expense([
                'branch' => $expense->branch,
                'project' => $expense->project,
                'category' => $category,
                'creator' => $expense->creator,
                'description' => "Persist Search {$index}",
            ]);
        }

        $response = $this->actingAs($user)->get(route('expenses.index', [
            'period_month' => '2026-07',
            'expense_category_id' => $category->id,
            'search' => 'Persist',
            'sort' => 'amount',
            'dir' => 'asc',
            'per_page' => 10,
        ]))->assertOk();

        $response->assertSee($expense->description)->assertSee('Nominal ▼')->assertSee('value="Persist"', false);
        $this->assertSame('amount', $response->viewData('filters')['sort']);
        $this->assertSame('asc', $response->viewData('filters')['dir']);
        $this->assertSame($category->id, $response->viewData('filters')['expense_category_id']);
        $nextPageQuery = [];
        parse_str((string) parse_url($response->viewData('expenses')->nextPageUrl(), PHP_URL_QUERY), $nextPageQuery);
        $expectedQuery = [
            'dir' => 'asc',
            'expense_category_id' => (string) $category->id,
            'page' => '2',
            'per_page' => '10',
            'period_month' => '2026-07',
            'search' => 'Persist',
            'sort' => 'amount',
        ];
        ksort($expectedQuery);
        ksort($nextPageQuery);
        $this->assertSame($expectedQuery, $nextPageQuery);
    }

    public function test_search_toolbar_and_filter_modal_preserve_each_others_query_parameters(): void
    {
        $branch = $this->expenseBranch('Cabang Toolbar');
        $project = $this->expenseProject($branch, 'Proyek Toolbar');
        $category = $this->expenseCategory('Kategori Toolbar');
        $creator = $this->expenseUser('pusat', $branch, 'Pembuat Toolbar');
        $this->expense(compact('branch', 'project', 'category', 'creator') + ['description' => 'Needle toolbar']);

        $response = $this->actingAs($creator)->get(route('expenses.index', [
            'period_month' => '2026-07',
            'branch_id' => $branch->id,
            'project_id' => $project->id,
            'expense_category_id' => $category->id,
            'payment_method' => 'transfer',
            'status' => 'active',
            'search' => 'Needle',
            'sort' => 'amount',
            'dir' => 'asc',
        ]))->assertOk();

        $html = $response->getContent();
        $this->assertLessThan(strpos($html, 'id="expense-filter-title"'), strpos($html, 'aria-label="Cari pengeluaran"'));
        $this->assertGreaterThanOrEqual(2, substr_count($html, 'name="search"'));
        $this->assertStringNotContainsString('[tabindex="-1"]', $html);
        $response->assertSee('Filter aktif:')
            ->assertSee('Cabang: Cabang Toolbar')
            ->assertSee('Proyek: Proyek Toolbar - Cabang Toolbar')
            ->assertSee('Kategori: Kategori Toolbar')
            ->assertSee('Hapus semua filter')
            ->assertSee(route('expenses.index', ['search' => 'Needle']), false);
        $response->assertSee('name="branch_id" value="'.$branch->id.'"', false)
            ->assertSee('name="project_id" value="'.$project->id.'"', false)
            ->assertSee('name="sort" value="amount"', false)
            ->assertSee('name="dir" value="asc"', false);
    }

    public function test_all_branch_project_options_have_branch_context_and_exclude_branchless_legacy_rows(): void
    {
        $branchA = $this->expenseBranch('Cabang A');
        $branchB = $this->expenseBranch('Cabang B');
        $projectA = $this->expenseProject($branchA, 'Proyek Kembar');
        $projectB = $this->expenseProject($branchB, 'Proyek Kembar');
        $branchless = LeadMaster::create([
            'branch_id' => null,
            'project_name' => 'Proyek Kembar',
            'is_active' => true,
        ]);
        $user = $this->expenseUser('pusat', $branchA);

        $response = $this->actingAs($user)->get(route('expenses.index', ['period_month' => '2026-07']))->assertOk();
        $projects = $response->viewData('projects');

        $this->assertEqualsCanonicalizing([$projectA->id, $projectB->id], $projects->pluck('id')->all());
        $response->assertSee('Proyek Kembar - Cabang A')->assertSee('Proyek Kembar - Cabang B');
        $this->assertSame(-1, app(ExpenseFilterService::class)->normalize(['project_id' => $branchless->id])['project_id']);
    }

    public function test_xlsx_has_exact_sheets_headers_filtered_data_native_types_and_safe_text(): void
    {
        $branch = $this->expenseBranch('Ekspor Branch');
        $project = $this->expenseProject($branch, 'Ekspor Project');
        $category = $this->expenseCategory('Ekspor Category');
        $creator = $this->expenseUser('pusat', $branch, 'Ekspor Creator');
        $target = $this->expense(compact('branch', 'project', 'category', 'creator') + [
            'expense_date' => '2026-07-21',
            'amount' => '765432.10',
            'description' => '=2+3',
            'vendor_name' => '+Vendor Formula',
            'payment_method' => 'transfer',
            'reference_number' => '@REF-SAFE',
        ]);
        $this->expense(compact('branch', 'project', 'category', 'creator') + ['description' => 'FILTERED OUT', 'payment_method' => 'tunai']);

        $response = $this->actingAs($creator)->get(route('expenses.export', [
            'date_from' => '2026-07-20',
            'date_to' => '2026-07-22',
            'branch_id' => $branch->id,
            'project_id' => $project->id,
            'expense_category_id' => $category->id,
            'payment_method' => 'transfer',
            'created_by' => $creator->id,
            'status' => 'active',
            'search' => '=2+3',
        ]))->assertOk()->assertDownload('pengeluaran_2026-07-20_2026-07-22_ekspor-branch.xlsx');

        $workbook = IOFactory::load($response->baseResponse->getFile()->getPathname());
        $this->assertCount(3, $workbook->getAllSheets());
        $this->assertSame(['RINGKASAN', 'DETAIL PENGELUARAN', 'REKAP'], $workbook->getSheetNames());
        $detail = $workbook->getSheetByName('DETAIL PENGELUARAN');
        $recap = $workbook->getSheetByName('REKAP');
        $summary = $workbook->getSheetByName('RINGKASAN');
        $this->assertSame(['Keterangan', 'Nilai'], $summary->rangeToArray('A1:B1')[0]);
        $this->assertSame([
            'Tanggal', 'Cabang', 'Proyek', 'Kategori', 'Deskripsi', 'Vendor / Penerima',
            'Metode Pembayaran', 'Nomor Referensi', 'Nominal', 'Status', 'Alasan Pembatalan',
            'Dibuat Oleh', 'Diperbarui Oleh', 'Dibuat Pada', 'Diperbarui Pada',
        ], $detail->rangeToArray('A1:O1')[0]);
        $this->assertSame([
            'Cabang', 'Proyek', 'Kategori', 'Jumlah Transaksi', 'Total',
        ], $recap->rangeToArray('A1:E1')[0]);
        $this->assertSame(2, $detail->getHighestDataRow());
        $this->assertSame($target->description, $detail->getCell('E2')->getValue());
        $this->assertSame($target->description, $detail->getCell('E2')->getCalculatedValue());
        $this->assertSame(DataType::TYPE_STRING, $detail->getCell('E2')->getDataType());
        $this->assertSame(DataType::TYPE_STRING, $detail->getCell('F2')->getDataType());
        $this->assertSame(DataType::TYPE_STRING, $detail->getCell('H2')->getDataType());
        $this->assertSame(DataType::TYPE_NUMERIC, $detail->getCell('A2')->getDataType());
        $this->assertSame(DataType::TYPE_NUMERIC, $detail->getCell('I2')->getDataType());
        $this->assertSame('DD/MM/YYYY', $detail->getStyle('A2')->getNumberFormat()->getFormatCode());
        $this->assertSame('[$Rp-id-ID] #,##0.00', $detail->getStyle('I2')->getNumberFormat()->getFormatCode());
        $this->assertSame('A2', $detail->getFreezePane());
        $this->assertSame('A1:O2', $detail->getAutoFilter()->getRange());
        $this->assertSame('A2', $summary->getFreezePane());
        $this->assertSame('A1:B8', $summary->getAutoFilter()->getRange());
        $this->assertSame('A2', $recap->getFreezePane());
        $this->assertSame('A1:E2', $recap->getAutoFilter()->getRange());
        $this->assertSame(1, $recap->getCell('D2')->getValue());
        $this->assertSame(765432.1, $recap->getCell('E2')->getValue());
        $this->assertSame(DataType::TYPE_NUMERIC, $recap->getCell('D2')->getDataType());
        $this->assertSame(DataType::TYPE_NUMERIC, $recap->getCell('E2')->getDataType());
        $this->assertSame(765432.1, $summary->getCell('B3')->getValue());
        $this->assertSame(1, $summary->getCell('B4')->getValue());
        $workbook->disconnectWorksheets();
    }

    public function test_export_status_all_contains_cancelled_detail_but_active_only_summary(): void
    {
        $user = $this->expenseUser('pusat');
        $this->expense(['creator' => $user, 'description' => 'Export Active', 'amount' => 100]);
        $this->expense([
            'creator' => $user,
            'description' => 'Export Cancelled',
            'amount' => 900,
            'status' => Expense::STATUS_CANCELLED,
            'cancellation_reason' => 'Duplikat',
        ]);

        $response = $this->actingAs($user)->get(route('expenses.export', [
            'period_month' => '2026-07', 'status' => 'all',
        ]))->assertOk();
        $workbook = IOFactory::load($response->baseResponse->getFile()->getPathname());
        $detail = $workbook->getSheetByName('DETAIL PENGELUARAN');
        $values = collect($detail->toArray())->flatten()->implode('|');
        $this->assertStringContainsString('Export Active', $values);
        $this->assertStringContainsString('Export Cancelled', $values);
        $this->assertSame(100.0, $workbook->getSheetByName('RINGKASAN')->getCell('B3')->getValue());
        $this->assertSame(1, $workbook->getSheetByName('RINGKASAN')->getCell('B4')->getValue());
        $workbook->disconnectWorksheets();
    }

    public function test_export_is_forbidden_to_unauthorized_users_and_empty_export_warns(): void
    {
        $branch = $this->expenseBranch();
        $staff = $this->expenseUser('staff', $branch);
        $pusat = $this->expenseUser('pusat', $branch);

        $this->actingAs($staff)->get(route('expenses.export', ['period_month' => '2026-07']))->assertForbidden();
        $this->actingAs($pusat)->from(route('expenses.index'))->get(route('expenses.export', ['period_month' => '2026-07']))
            ->assertRedirect(route('expenses.index'))
            ->assertSessionHas('warning', 'Tidak ada data pengeluaran pada filter dan periode yang dipilih.');
    }

    public function test_expense_hardening_changelog_is_deployed_once_and_rendered(): void
    {
        $title = 'Keandalan Pengelolaan Pengeluaran Ditingkatkan';
        $entry = Changelog::whereNull('version')->where('title', $title)->sole();
        $this->assertSame('fixed', $entry->category);
        $this->assertNull($entry->created_by);
        $this->assertSame(1, Changelog::whereNull('version')->where('title', $title)->count());

        $this->actingAs($this->expenseUser('pusat'))->get(route('changelogs.index'))
            ->assertOk()
            ->assertSee($title)
            ->assertSee('lebih aman dari perubahan bersamaan');
    }

    public function test_expense_filter_toolbar_changelog_is_deployed_once_and_rendered(): void
    {
        $title = 'Filter Pengeluaran Lebih Ringkas';
        $entry = Changelog::whereNull('version')->where('title', $title)->sole();
        $this->assertSame('changed', $entry->category);
        $this->assertSame(1, Changelog::whereNull('version')->where('title', $title)->count());

        $this->actingAs($this->expenseUser('pusat'))->get(route('changelogs.index'))
            ->assertOk()
            ->assertSee($title)
            ->assertSee('satu tombol');
    }

    public function test_existing_operational_pages_render_without_invoking_external_operations(): void
    {
        $branch = $this->expenseBranch();
        $admin = $this->expenseUser('admin', $branch);
        $pusat = $this->expenseUser('pusat', $branch);

        $this->actingAs($admin)->get(route('dashboard'))->assertOk();
        $this->actingAs($admin)->get(route('dana-talangan.index'))->assertOk();
        $this->actingAs($admin)->get(route('content-calendar.index'))->assertOk();
        $this->actingAs($pusat)->get(route('sales-pocketbook.index'))->assertOk();
    }

    private function filteredIds(array $input): array
    {
        $service = app(ExpenseFilterService::class);

        return $service->query($service->normalize($input))->pluck('id')->all();
    }
}
