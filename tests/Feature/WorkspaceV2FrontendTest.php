<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchMigrationState;
use App\Models\ConsumerApplication;
use App\Models\Customer;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class WorkspaceV2FrontendTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_routes_return_not_found_when_feature_flag_is_disabled(): void
    {
        config(['app.workspace_v2_enabled' => false]);
        $user = $this->superadmin();

        $this->actingAs($user)
            ->get(route('workspace-v2.dashboard'))
            ->assertNotFound();
    }

    public function test_workspace_backend_routes_require_matching_module_scope(): void
    {
        config(['app.workspace_v2_enabled' => true]);
        $denied = User::factory()->create([
            'password_changed_at' => now(),
        ]);

        $this->actingAs($denied)->get(route('workspace-v2.dashboard'))->assertForbidden();
        $this->actingAs($denied)->get(route('workspace-v2.lead'))->assertForbidden();
        $this->actingAs($denied)->get(route('workspace-v2.transactions'))->assertForbidden();
        $this->actingAs($denied)->getJson(route('workspace-v2.transactions'))->assertForbidden();
    }

    public function test_workspace_transaction_json_is_allowed_for_scoped_user(): void
    {
        config(['app.workspace_v2_enabled' => true]);
        $branch = Branch::query()->create(['name' => 'JSON Branch', 'code' => 'JSN', 'is_active' => true]);
        $project = LeadMaster::query()->create(['branch_id' => $branch->id, 'project_name' => 'JSON Project', 'is_active' => true]);
        $customer = Customer::factory()->create(['name' => 'JSON Customer']);
        $application = ConsumerApplication::query()->create([
            'customer_id' => $customer->id,
            'branch_id' => $branch->id,
            'project_id' => $project->id,
            'application_status' => 'draft',
            'consumer_status' => 'Lanjut',
            'current_process' => 'data_konsumen',
        ]);

        $this->actingAs($this->superadmin())
            ->getJson(route('workspace-v2.transaction.detail', $application))
            ->assertOk()
            ->assertJsonPath('ok', true);
    }

    public function test_workspace_renders_required_navigation_without_standalone_sp3k_entry(): void
    {
        config(['app.workspace_v2_enabled' => true]);
        $user = $this->superadmin();

        $response = $this->actingAs($user)->get(route('workspace-v2.transactions.proses-bank'));
        $response->assertOk();
        $response->assertViewHas('activeProcessView', 'proses-bank');

        $response->assertSeeText('Transaksi Konsumen')
            ->assertSeeText('BI Checking')
            ->assertSeeText('Proses Bank')
            ->assertSeeText('PPJB Dev')
            ->assertDontSeeText('SP3K');
        $this->assertFalse(Route::has('workspace-v2.transactions.sp3k'));
    }

    public function test_workspace_exposes_all_process_views_as_views_of_one_transaction_workspace(): void
    {
        config(['app.workspace_v2_enabled' => true]);
        $user = $this->superadmin();
        $routes = [
            'workspace-v2.transactions',
            'workspace-v2.transactions.data-konsumen',
            'workspace-v2.transactions.psjb',
            'workspace-v2.transactions.bi-checking',
            'workspace-v2.transactions.pemberkasan',
            'workspace-v2.transactions.proses-bank',
            'workspace-v2.transactions.ppjb-dev',
            'workspace-v2.transactions.akad',
            'workspace-v2.transactions.bast',
        ];

        foreach ($routes as $routeName) {
            $this->actingAs($user)->get(route($routeName))->assertSeeText('Transaksi Konsumen');
        }
    }

    public function test_workspace_exposes_spreadsheet_aligned_data_consumer_form(): void
    {
        config(['app.workspace_v2_enabled' => true]);
        $user = $this->superadmin();

        $this->actingAs($user)
            ->get(route('workspace-v2.transactions.data-konsumen.create'))
            ->assertSee('name="nik"', false)
            ->assertSee('name="date_of_birth"', false)
            ->assertSee('name="emergency_contact_name"', false)
            ->assertSeeText('Data Konsumen');
    }

    public function test_workspace_supporting_areas_render_with_the_same_shell(): void
    {
        config(['app.workspace_v2_enabled' => true]);
        $user = $this->superadmin();

        foreach ([
            ['workspace-v2.dashboard', 'Ruang kerja hari ini'],
            ['workspace-v2.lead', 'Lead'],
            ['workspace-v2.nup', 'NUP / Waiting List'],
            ['workspace-v2.mundur', 'Mundur'],
            ['workspace-v2.kendala', 'Kendala'],
            ['workspace-v2.garansi', 'Garansi'],
            ['workspace-v2.selesai', 'Selesai'],
            ['workspace-v2.activity', 'Aktivitas'],
            ['workspace-v2.reports', 'Laporan'],
            ['workspace-v2.settings', 'Pengaturan'],
        ] as [$routeName, $heading]) {
            $this->actingAs($user)->get(route($routeName))->assertOk()->assertSeeText($heading);
        }
    }

    public function test_superadmin_sees_all_transactions_and_process_views_use_the_same_scope(): void
    {
        config(['app.workspace_v2_enabled' => true]);
        Branch::query()->create(['name' => 'AAA Cabang Lain', 'code' => 'AAA', 'is_active' => true]);
        $branch = Branch::query()->create(['name' => 'ZZZ Cabang Demo', 'code' => 'ZZZ', 'is_active' => true]);
        $project = LeadMaster::query()->create(['branch_id' => $branch->id, 'project_name' => 'Proyek Workspace UAT', 'is_active' => true]);
        $dataCustomer = Customer::factory()->create(['name' => 'UAT Data Konsumen']);
        $bankCustomer = Customer::factory()->create(['name' => 'UAT Proses Bank']);
        ConsumerApplication::query()->create([
            'customer_id' => $dataCustomer->id,
            'branch_id' => $branch->id,
            'project_id' => $project->id,
            'application_status' => 'draft',
            'consumer_status' => 'Lanjut',
            'current_process' => 'data_konsumen',
        ]);
        ConsumerApplication::query()->create([
            'customer_id' => $bankCustomer->id,
            'branch_id' => $branch->id,
            'project_id' => $project->id,
            'application_status' => 'active',
            'consumer_status' => 'Lanjut',
            'current_process' => 'proses_bank',
        ]);
        $user = $this->superadmin();

        $allTransactions = $this->actingAs($user)
            ->get(route('workspace-v2.transactions'))
            ->assertOk()
            ->assertSeeText('UAT Data Konsumen')
            ->assertSeeText('UAT Proses Bank');
        $this->assertSame(1, substr_count($allTransactions->getContent(), '+ Data Konsumen'));
        $allTransactions->assertSeeText('Urutkan: Terakhir diperbarui');
        $allTransactions->assertSee('workspace-v2-mobile-cards', false)
            ->assertDontSeeText('Showing');

        $this->actingAs($user)
            ->get(route('workspace-v2.transactions.data-konsumen'))
            ->assertOk()
            ->assertSeeText('UAT Data Konsumen')
            ->assertDontSeeText('UAT Proses Bank');

        $this->actingAs($user)
            ->get(route('workspace-v2.transactions.proses-bank'))
            ->assertOk()
            ->assertSeeText('UAT Proses Bank')
            ->assertDontSeeText('UAT Data Konsumen');
    }

    /** BR-30: dashboard and reports count only canonical LANJUT transactions as active. */
    public function test_br30_workspace_reporting_active_counts_only_canonical_lanjut_transactions(): void
    {
        config(['app.workspace_v2_enabled' => true]);
        [$branch, $project] = $this->reportingContext();
        $this->reportingApplication($branch, $project, 'Aktif Kanonis', 'LANJUT');
        $completed = $this->reportingApplication($branch, $project, 'Selesai Kanonis', 'LANJUT');
        $completed->bastRecords()->create(['tanggal_bast' => '2026-11-01']);
        $completed->warranties()->create(['status_komplain' => 'Tidak Ada Komplain', 'status_garansi' => 'Tidak Ada Komplain']);
        $this->reportingApplication($branch, $project, 'Gugur Kanonis', 'MUNDUR');
        $this->reportingApplication($branch, $project, 'Ditutup Ganti Nama', 'DITUTUP', 'GANTI_NAMA');
        $this->reportingApplication($branch, $project, 'Ditutup Kembali NUP', 'DITUTUP', 'KEMBALI_KE_NUP');
        $this->reportingApplication($branch, $project, 'Status Konsumen Lama Mundur', 'LANJUT', null, 'Mundur');

        $user = $this->superadmin();
        $dashboard = $this->actingAs($user)->get(route('workspace-v2.dashboard'));
        $reports = $this->actingAs($user)->get(route('workspace-v2.reports'));

        $dashboard->assertOk()
            ->assertViewHas('activeTransactions', 3)
            ->assertViewHas('completedTransactions', 1);
        $reports->assertOk()
            ->assertViewHas('active', 3)
            ->assertViewHas('completed', 1);
    }

    /** BR-30: Mundur aggregate uses canonical MUNDUR and excludes Ganti Nama/Kembali ke NUP closures. */
    public function test_br30_mundur_aggregate_excludes_ganti_nama_and_kembali_ke_nup_closed_transactions(): void
    {
        config(['app.workspace_v2_enabled' => true]);
        [$branch, $project] = $this->reportingContext();
        $this->reportingApplication($branch, $project, 'Gugur Kanonis', 'MUNDUR');
        $this->reportingApplication($branch, $project, 'Status Konsumen Lama Mundur', 'LANJUT', null, 'Mundur');
        $this->reportingApplication($branch, $project, 'Ditutup Ganti Nama', 'DITUTUP', 'GANTI_NAMA');
        $this->reportingApplication($branch, $project, 'Ditutup Kembali NUP', 'DITUTUP', 'KEMBALI_KE_NUP');

        $response = $this->actingAs($this->superadmin())->get(route('workspace-v2.mundur'));

        $response->assertOk()->assertViewHas('records', function ($records): bool {
            return $records->total() === 1
                && $records->getCollection()->sole()->transaction_status === 'MUNDUR';
        });
    }

    public function test_workspace_data_consumer_submission_redirects_to_the_workspace_list(): void
    {
        config(['app.workspace_v2_enabled' => true]);
        $branch = Branch::query()->create(['name' => 'Workspace Submit', 'code' => 'WSP', 'is_active' => true]);
        $project = LeadMaster::query()->create(['branch_id' => $branch->id, 'project_name' => 'Proyek Submit UAT', 'is_active' => true]);
        BranchMigrationState::query()->create(['branch_id' => $branch->id, 'state' => BranchMigrationState::STATE_PARALEL]);
        $user = $this->superadmin();

        $response = $this->actingAs($user)->post(route('workspace-v2.transactions.data-konsumen.store'), [
            'name' => 'UAT Manual Consumer',
            'phone' => '081234567890',
            'branch_id' => $branch->id,
            'project_id' => $project->id,
            'payment_method' => 'kpr',
        ]);

        $application = ConsumerApplication::query()->latest('id')->firstOrFail();

        $response
            ->assertRedirect(route('workspace-v2.transactions.data-konsumen'))
            ->assertSessionHas('success', 'Data konsumen berhasil disimpan.');
        $this->assertNotSame('', (string) $application->id_transaksi);
        $this->assertSame('data_konsumen', $application->current_process);
        $this->followRedirects($response)
            ->assertSeeText('UAT Manual Consumer')
            ->assertSeeText('Data konsumen berhasil disimpan.');
    }

    public function test_workspace_does_not_expose_a_standalone_sp3k_destination_and_keeps_contextual_drawer_contract(): void
    {
        config(['app.workspace_v2_enabled' => true]);

        $response = $this->actingAs($this->superadmin())->get(route('workspace-v2.transactions.proses-bank'));
        $script = file_get_contents(resource_path('js/workspace-v2/app.js'));
        $styles = file_get_contents(resource_path('css/workspace-v2.css'));

        $response->assertDontSeeText('SP3K');
        $this->assertFalse(Route::has('workspace-v2.transactions.sp3k'));
        $this->assertStringContainsString('Lanjutkan Proses', $script);
        $this->assertStringContainsString('Tindakan', $script);
        $this->assertStringContainsString('@media (min-width: 768px)', $styles);
        $this->assertStringContainsString('.workspace-v2-sidebar-close { display: none !important; }', $styles);
        $this->assertStringContainsString('.workspace-v2-content { min-width: 0; overflow-x: hidden; }', $styles);
        $this->assertStringContainsString('.workspace-v2-page-action { width: 100%; }', $styles);
        $this->assertStringContainsString('.workspace-v2-drawer-header, .workspace-v2-drawer-body { padding-inline: 16px; }', $styles);
        $this->assertDatabaseHas('changelogs', ['version' => null, 'title' => 'Penyempurnaan UAT Workspace V2']);
    }

    /** @return array{0: Branch, 1: LeadMaster} */
    private function reportingContext(): array
    {
        $branch = Branch::query()->create(['name' => 'Reporting Branch', 'code' => 'RPT', 'is_active' => true]);
        $project = LeadMaster::query()->create(['branch_id' => $branch->id, 'project_name' => 'Reporting Project', 'is_active' => true]);

        return [$branch, $project];
    }

    private function reportingApplication(Branch $branch, LeadMaster $project, string $name, string $transactionStatus, ?string $closedByEvent = null, ?string $consumerStatus = null): ConsumerApplication
    {
        return ConsumerApplication::query()->create([
            'customer_id' => Customer::factory()->create(['name' => $name])->id,
            'branch_id' => $branch->id,
            'project_id' => $project->id,
            'application_status' => 'active',
            'transaction_status' => $transactionStatus,
            'closed_by_event' => $closedByEvent,
            'consumer_status' => $consumerStatus,
            'current_process' => 'data_konsumen',
        ]);
    }

    private function superadmin(): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', 'superadmin')->value('id'),
            'password_changed_at' => now(),
        ]);
    }
}
