<?php

namespace Tests\Feature;

use App\Models\Branch;
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

    public function test_workspace_data_consumer_submission_redirects_to_the_workspace_list(): void
    {
        config(['app.workspace_v2_enabled' => true]);
        $branch = Branch::query()->create(['name' => 'Workspace Submit', 'code' => 'WSP', 'is_active' => true]);
        $project = LeadMaster::query()->create(['branch_id' => $branch->id, 'project_name' => 'Proyek Submit UAT', 'is_active' => true]);
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

    private function superadmin(): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', 'superadmin')->value('id'),
            'password_changed_at' => now(),
        ]);
    }
}
