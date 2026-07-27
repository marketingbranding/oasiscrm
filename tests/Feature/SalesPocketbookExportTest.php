<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Changelog;
use App\Models\ContentItem;
use App\Models\LeadMaster;
use App\Models\LeadSource;
use App\Models\Role;
use App\Models\SalesLead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class SalesPocketbookExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_export_contains_exact_sheets_headers_filtered_data_and_excel_formats(): void
    {
        [$branch, $project, $sales] = $this->salesContext('Solo', 'Solo Sales');
        $otherSales = $this->sales($branch, $project, 'Sales Lain');
        $manager = $this->user('manager', $branch);
        $source = LeadSource::where('is_active', true)->firstOrFail();
        $lead = $this->lead($sales, $project, '=Nama Aman', [
            'lead_date' => '2026-07-20',
            'source_name_snapshot' => $source->name,
            'contacted_at' => '2026-07-20 10:30:00',
        ]);
        $this->lead($sales, $project, 'Di Luar Periode', ['lead_date' => '2026-07-01']);
        $this->lead($otherSales, $project, 'Milik Sales Lain', ['lead_date' => '2026-07-20']);
        $this->agenda($sales, $project);

        $response = $this->actingAs($manager)->get(route('sales-pocketbook.export', [
            'branch_id' => $branch->id,
            'project_id' => $project->id,
            'sales_user_id' => $sales->id,
            'period_type' => 'week',
            'week' => '2026-07-20',
            'lead_source_id' => $source->id,
            'stage' => 'contacted_at',
        ]))->assertOk()->assertDownload('buku-saku-sales_solo_solo-sales_2026-07-20_2026-07-26.xlsx');

        $workbook = IOFactory::load($response->baseResponse->getFile()->getPathname());
        $this->assertCount(3, $workbook->getAllSheets());
        $this->assertSame(['REKAP MINGGUAN', 'LEAD HARIAN', 'AGENDA HARIAN'], $workbook->getSheetNames());

        $weekly = $workbook->getSheetByName('REKAP MINGGUAN');
        $leads = $workbook->getSheetByName('LEAD HARIAN');
        $agendas = $workbook->getSheetByName('AGENDA HARIAN');
        $this->assertSame([
            'Periode Mulai', 'Periode Selesai', 'Sales', 'Cabang', 'Proyek', 'Lead Baru',
            'Dihubungi', 'Tatap Muka', 'Survey Lokasi', 'UTJ', 'Berkas Awal Lengkap', 'Akad',
            'Agenda Selesai', 'Konversi Lead ke Dihubungi', 'Konversi Dihubungi ke Tatap Muka',
            'Konversi Tatap Muka ke Survey', 'Konversi Survey ke UTJ', 'Konversi UTJ ke Berkas',
            'Konversi Berkas ke Akad', 'Input Terakhir',
        ], $weekly->rangeToArray('A1:T1')[0]);
        $this->assertSame([
            'Tanggal Lead', 'Sales', 'Cabang', 'Proyek', 'Nama Konsumen', 'Nomor HP', 'Sumber Lead',
            'Dihubungi', 'Tatap Muka', 'Survey', 'UTJ', 'Berkas Awal', 'Akad', 'Catatan',
            'Dibuat', 'Diperbarui',
        ], $leads->rangeToArray('A1:P1')[0]);
        $this->assertSame([
            'Tanggal', 'Sales', 'Cabang', 'Proyek', 'Jam Mulai', 'Jam Selesai', 'Durasi',
            'Kategori', 'Agenda', 'Lokasi', 'Hasil', 'Status',
        ], $agendas->rangeToArray('A1:L1')[0]);

        $this->assertSame($lead->customer_name, $leads->getCell('E2')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $leads->getCell('E2')->getDataType());
        $this->assertSame($lead->customer_name, $leads->getCell('E2')->getCalculatedValue());
        $this->assertSame(2, $leads->getHighestDataRow());
        $this->assertSame('Solo Sales', $weekly->getCell('C2')->getValue());
        $this->assertSame(1.0, $weekly->getCell('N2')->getValue());
        $this->assertSame('0.0%', $weekly->getStyle('N2')->getNumberFormat()->getFormatCode());
        $this->assertSame('DD/MM/YYYY', $leads->getStyle('A2')->getNumberFormat()->getFormatCode());
        $this->assertSame('HH:MM', $agendas->getStyle('E2')->getNumberFormat()->getFormatCode());
        $this->assertNotFalse($weekly->getAutoFilter()->getRange());
        $this->assertSame('A2', $weekly->getFreezePane());
        $this->assertSame('Follow-up Konsumen', $agendas->getCell('I2')->getValue());
        $workbook->disconnectWorksheets();
    }

    public function test_export_scope_blocks_inaccessible_filters_and_sales_export_only_contains_own_pii(): void
    {
        [$branch, $project, $sales] = $this->salesContext('Solo', 'Solo Sales');
        $otherSales = $this->sales($branch, $project, 'Sales Rahasia');
        $this->lead($sales, $project, 'Lead Saya', ['lead_date' => '2026-07-20']);
        $this->lead($otherSales, $project, 'PII Rahasia', ['lead_date' => '2026-07-20']);
        [$otherBranch, $otherProject, $outsideSales] = $this->salesContext('Pati', 'Sales Pati');

        $this->actingAs($sales)->get(route('sales-pocketbook.export', [
            'period_type' => 'week', 'week' => '2026-07-20', 'sales_user_id' => $otherSales->id,
        ]))->assertForbidden();
        $this->actingAs($sales)->get(route('sales-pocketbook.export', [
            'period_type' => 'week', 'week' => '2026-07-20', 'branch_id' => $otherBranch->id,
        ]))->assertForbidden();
        $this->actingAs($sales)->get(route('sales-pocketbook.export', [
            'period_type' => 'week', 'week' => '2026-07-20', 'project_id' => $otherProject->id,
        ]))->assertForbidden();

        $response = $this->actingAs($sales)->get(route('sales-pocketbook.export', ['period_type' => 'week', 'week' => '2026-07-20']))->assertOk();
        $workbook = IOFactory::load($response->baseResponse->getFile()->getPathname());
        $leadValues = collect($workbook->getSheetByName('LEAD HARIAN')->toArray())->flatten()->implode('|');
        $this->assertStringContainsString('Lead Saya', $leadValues);
        $this->assertStringNotContainsString('PII Rahasia', $leadValues);
        $this->assertStringNotContainsString($outsideSales->name, $leadValues);
        $workbook->disconnectWorksheets();
    }

    public function test_export_uses_the_same_dates_as_metric_and_completed_agenda_drilldowns(): void
    {
        [$branch, $project, $sales] = $this->salesContext('Solo', 'Solo Sales');
        $manager = $this->user('manager', $branch);
        $this->lead($sales, $project, 'Dihubungi Minggu Ini', [
            'lead_date' => '2026-07-01',
            'contacted_at' => '2026-07-21 09:00:00',
        ]);
        $agenda = $this->agenda($sales, $project);
        $agenda->update([
            'scheduled_date' => '2026-07-01',
            'start_date' => '2026-07-01',
            'deadline_date' => '2026-07-01',
            'completed_at' => '2026-07-22 10:00:00',
        ]);

        $response = $this->actingAs($manager)->get(route('sales-pocketbook.export', [
            'period_type' => 'week',
            'week' => '2026-07-20',
            'report_metric' => 'contacted',
            'report_agenda_completed' => 1,
        ]))->assertOk();

        $workbook = IOFactory::load($response->baseResponse->getFile()->getPathname());
        $this->assertSame('Dihubungi Minggu Ini', $workbook->getSheetByName('LEAD HARIAN')->getCell('E2')->getValue());
        $this->assertSame('Follow-up Konsumen', $workbook->getSheetByName('AGENDA HARIAN')->getCell('I2')->getValue());
        $workbook->disconnectWorksheets();
    }

    public function test_empty_export_redirects_with_a_clear_warning(): void
    {
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Solo Project', 'is_active' => true]);
        $sales = $this->user('sales', $branch);
        $sales->assignedProjects()->attach($project, ['is_primary' => true]);

        $this->actingAs($sales)
            ->from(route('sales-pocketbook.index'))
            ->get(route('sales-pocketbook.export', ['period_type' => 'week', 'week' => '2026-07-20']))
            ->assertRedirect(route('sales-pocketbook.index'))
            ->assertSessionHas('warning', 'Tidak ada data Buku Saku Sales pada filter dan periode yang dipilih.');

        $this->get(route('sales-pocketbook.index'))
            ->assertOk()
            ->assertSee('Tidak ada data Buku Saku Sales pada filter dan periode yang dipilih.')
            ->assertSee('crmToasts', false);
    }

    public function test_grouped_changelog_contains_one_system_authored_sales_pocketbook_entry(): void
    {
        $entry = Changelog::whereNull('version')->where('title', 'Buku Saku Sales Terpadu')->sole();
        $this->assertSame('added', $entry->category);
        $this->assertNull($entry->created_by);
        $this->assertSame(1, Changelog::whereNull('version')->where('title', 'Buku Saku Sales Terpadu')->count());

        $user = $this->user('sales');
        $this->actingAs($user)->get(route('changelogs.index'))
            ->assertOk()
            ->assertSee('Buku Saku Sales Terpadu')
            ->assertSee('penugasan sales per proyek');
    }

    public function test_sales_workflow_correction_changelog_is_idempotent_and_rendered(): void
    {
        $entry = Changelog::whereNull('version')->where('title', 'Penyempurnaan Alur Buku Saku Sales')->sole();
        $this->assertSame('changed', $entry->category);
        $this->assertNull($entry->created_by);
        $this->assertSame(1, Changelog::whereNull('version')->where('title', $entry->title)->count());

        $this->actingAs($this->user('sales'))->get(route('changelogs.index'))
            ->assertOk()->assertSee($entry->title)->assertSee('Durasi agenda dihitung otomatis')->assertSee('notifikasi toast');
    }

    private function salesContext(string $branchName, string $salesName): array
    {
        $branch = Branch::create([
            'name' => $branchName,
            'code' => strtoupper(substr($branchName, 0, 3)).random_int(10, 99),
            'is_active' => true,
        ]);
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => "{$branchName} Project", 'is_active' => true]);
        $sales = $this->sales($branch, $project, $salesName);

        return [$branch, $project, $sales];
    }

    private function sales(Branch $branch, LeadMaster $project, string $name): User
    {
        $sales = $this->user('sales', $branch, $name);
        $sales->assignedProjects()->attach($project, ['is_primary' => true]);

        return $sales;
    }

    private function user(string $roleSlug, ?Branch $branch = null, ?string $name = null): User
    {
        $role = Role::firstOrCreate(['slug' => $roleSlug], [
            'name' => ucfirst($roleSlug),
            'is_superadmin' => $roleSlug === 'superadmin',
        ]);

        return User::factory()->create([
            'name' => $name ?? ucfirst($roleSlug),
            'role_id' => $role->id,
            'branch_id' => $branch?->id,
            'password_changed_at' => now(),
        ]);
    }

    private function lead(User $sales, LeadMaster $project, string $name, array $overrides = []): SalesLead
    {
        $source = LeadSource::where('is_active', true)->firstOrFail();

        return SalesLead::create(array_merge([
            'branch_id' => $project->branch_id,
            'project_id' => $project->id,
            'sales_user_id' => $sales->id,
            'lead_source_id' => $source->id,
            'lead_date' => '2026-07-20',
            'customer_name' => $name,
            'phone' => '08123456789',
            'source_name_snapshot' => $source->name,
            'created_by' => $sales->id,
            'updated_by' => $sales->id,
        ], $overrides));
    }

    private function agenda(User $sales, LeadMaster $project): ContentItem
    {
        return ContentItem::create([
            'branch_id' => $project->branch_id,
            'project_name' => $project->project_name,
            'item_type' => 'agenda',
            'visibility' => 'personal',
            'title' => 'Follow-up Konsumen',
            'agenda_type' => ContentItem::SALES_AGENDA_TYPE,
            'sales_activity_category' => 'Follow-up',
            'scheduled_date' => '2026-07-21',
            'start_date' => '2026-07-21',
            'deadline_date' => '2026-07-21',
            'start_time' => '09:00',
            'end_time' => '10:00',
            'duration_minutes' => 60,
            'status' => 'done',
            'activity_result' => 'Konsumen tertarik.',
            'completed_at' => '2026-07-21 10:00:00',
            'owner_user_id' => $sales->id,
            'sales_project_id' => $project->id,
            'created_by' => $sales->id,
        ]);
    }
}
