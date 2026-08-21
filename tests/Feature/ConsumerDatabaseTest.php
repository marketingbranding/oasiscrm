<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ConsumerApplication;
use App\Models\ConsumerBankProcess;
use App\Models\ConsumerStageEvent;
use App\Models\Customer;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConsumerDatabaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_root_and_all_module_routes_render(): void
    {
        [$user] = $this->context();

        $this->actingAs($user)->get(route('consumer-database.index'))->assertRedirect(route('consumer-database.module', 'data-konsumen'));
        foreach (['data-konsumen', 'bi-checking', 'psjb', 'pemberkasan', 'proses-bank', 'ppjb', 'akad', 'bast'] as $module) {
            $this->actingAs($user)->get(route('consumer-database.module', $module))->assertOk()->assertViewHas('moduleSlug', $module);
        }
    }

    public function test_table_and_sheet_use_same_source_ids(): void
    {
        [$user, $branch, $project] = $this->context();
        $application = $this->application($branch, $project, 'Budi Database', 'Lanjut');

        foreach (['table', 'sheet'] as $view) {
            $this->actingAs($user)->get(route('consumer-database.module', ['module' => 'data-konsumen', 'view' => $view]))
                ->assertOk()->assertSee('data-konsumen:'.$application->id, false)->assertSee('Budi Database');
        }
    }

    public function test_process_module_uses_stage_specific_bank_rows(): void
    {
        [$user, $branch, $project] = $this->context();
        $application = $this->application($branch, $project, 'Process Consumer');
        ConsumerStageEvent::create(['consumer_application_id' => $application->id, 'stage' => 'pemberkasan', 'occurred_at' => now()]);
        $pemberkasan = ConsumerBankProcess::create(['consumer_application_id' => $application->id, 'bank_name' => 'Bank Berkas', 'tipe_pemberkasan' => 'KPR', 'tanggal_terima_bank' => today()]);
        $bank = ConsumerBankProcess::create(['consumer_application_id' => $application->id, 'bank_name' => 'Bank Proses', 'response_type' => 'approved', 'approved_plafond' => 100000000]);

        $this->actingAs($user)->get(route('consumer-database.module', 'pemberkasan'))->assertOk()->assertSee('pemberkasan:'.$pemberkasan->id, false)->assertSee('Bank Berkas')->assertDontSee('Bank Proses');
        $this->actingAs($user)->get(route('consumer-database.module', 'proses-bank'))->assertOk()->assertSee('proses-bank:'.$bank->id, false)->assertSee('Bank Proses')->assertDontSee('Bank Berkas');
    }

    public function test_registry_driven_filter_and_safe_sort_work(): void
    {
        [$user, $branch, $project] = $this->context();
        $this->application($branch, $project, 'Zeta Consumer', 'Lanjut');
        $this->application($branch, $project, 'Alpha Consumer', 'Mundur');

        $response = $this->actingAs($user)->get(route('consumer-database.module', ['module' => 'data-konsumen', 'filter_column' => 'consumer_status', 'filter' => 'Lanjut', 'sort' => 'unsafe', 'direction' => 'unsafe']));
        $response->assertOk()->assertSee('Zeta Consumer')->assertDontSee('Alpha Consumer');
    }

    public function test_permission_scope_and_branch_project_mismatch_are_enforced(): void
    {
        [$user, $branch, $project] = $this->context();
        [, $otherBranch, $otherProject] = $this->context('manager');
        $sameBranchProject = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Project Mismatch', 'is_active' => true]);
        $this->application($branch, $project, 'Visible Consumer');
        $this->application($otherBranch, $otherProject, 'Hidden Consumer');

        $this->actingAs($user)->get(route('consumer-database.module', 'data-konsumen'))->assertOk()->assertSee('Visible Consumer')->assertDontSee('Hidden Consumer');
        $this->actingAs($user)->get(route('consumer-database.module', ['module' => 'data-konsumen', 'branch_id' => $otherBranch->id]))->assertForbidden();
        $this->actingAs($user)->get(route('consumer-database.module', ['module' => 'data-konsumen', 'project_id' => $otherProject->id]))->assertForbidden();
        $this->actingAs($user)->get(route('consumer-database.module', ['module' => 'data-konsumen', 'branch_id' => $otherBranch->id, 'project_id' => $sameBranchProject->id]))->assertForbidden();

        $sales = Role::firstOrCreate(['slug' => 'sales'], ['name' => 'Sales']);
        $denied = User::factory()->create(['role_id' => $sales->id, 'branch_id' => $branch->id, 'password_changed_at' => now()]);
        $this->actingAs($denied)->get(route('consumer-database.index'))->assertForbidden();
    }

    private function application(Branch $branch, LeadMaster $project, string $name, ?string $status = null): ConsumerApplication
    {
        return ConsumerApplication::create(['customer_id' => Customer::create(['name' => $name])->id, 'branch_id' => $branch->id, 'project_id' => $project->id, 'application_status' => 'active', 'consumer_status' => $status]);
    }

    private function context(string $roleSlug = 'admin'): array
    {
        $role = Role::firstOrCreate(['slug' => $roleSlug], ['name' => $roleSlug, 'is_superadmin' => false]);
        $branch = Branch::create(['name' => 'Cabang '.str()->random(5), 'code' => str()->upper(str()->random(5)), 'is_active' => true]);
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Proyek '.str()->random(5), 'is_active' => true]);
        $user = User::factory()->create(['role_id' => $role->id, 'branch_id' => $branch->id, 'password_changed_at' => now()]);

        return [$user, $branch, $project];
    }
}
