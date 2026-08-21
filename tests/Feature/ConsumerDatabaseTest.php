<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ConsumerAkadRecord;
use App\Models\ConsumerApplication;
use App\Models\ConsumerBankProcess;
use App\Models\ConsumerBastRecord;
use App\Models\ConsumerPpjbDeveloper;
use App\Models\ConsumerPsjb;
use App\Models\ConsumerStageEvent;
use App\Models\Customer;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_page_two_sheet_preserves_query_and_table_sheet_order(): void
    {
        [$user, $branch, $project] = $this->context();
        foreach (range(1, 26) as $number) {
            $this->application($branch, $project, sprintf('Cari %02d', $number), 'Lanjut');
        }
        $query = ['search' => 'Cari', 'branch_id' => $branch->id, 'project_id' => $project->id, 'filter_column' => 'consumer_status', 'filter' => 'Lanjut', 'sort' => 'customer_name', 'direction' => 'asc', 'page' => 2];
        $table = $this->actingAs($user)->get(route('consumer-database.module', ['module' => 'data-konsumen', 'view' => 'table'] + $query))->assertOk();
        $sheet = $this->actingAs($user)->get(route('consumer-database.module', ['module' => 'data-konsumen', 'view' => 'sheet'] + $query))->assertOk()->assertSee('>26</th>', false);
        preg_match_all('/data-source-id="([^"]+)"/', $table->getContent(), $tableIds);
        preg_match_all('/data-source-id="([^"]+)"/', $sheet->getContent(), $sheetIds);
        $this->assertSame($tableIds[1], $sheetIds[1]);
        foreach (['search=Cari', 'branch_id='.$branch->id, 'project_id='.$project->id, 'filter_column=consumer_status', 'filter=Lanjut', 'sort=customer_name', 'direction=asc', 'view=sheet'] as $parameter) {
            $sheet->assertSee($parameter, false);
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

        $sorted = $this->actingAs($user)->get(route('consumer-database.module', ['module' => 'data-konsumen', 'sort' => 'customer_name', 'direction' => 'asc']));
        $this->assertLessThan(strpos($sorted->getContent(), 'Zeta Consumer'), strpos($sorted->getContent(), 'Alpha Consumer'));
    }

    public function test_process_filter_applies_to_latest_snapshot_and_nullable_events_render(): void
    {
        [$user, $branch, $project] = $this->context();
        $application = $this->application($branch, $project, '<b>Konsumen</b>');
        ConsumerStageEvent::create(['consumer_application_id' => $application->id, 'stage' => 'bi_checking', 'status' => 'Lama', 'occurred_at' => now()->subDay()]);
        $latest = ConsumerStageEvent::create(['consumer_application_id' => $application->id, 'stage' => 'bi_checking', 'status' => 'Terbaru', 'notes' => str_repeat('Narasi panjang ', 20), 'occurred_at' => now()]);

        $this->actingAs($user)->get(route('consumer-database.module', ['module' => 'bi-checking', 'filter_column' => 'status', 'filter' => 'Lama']))->assertOk()->assertDontSee('bi-checking:'.$latest->id, false);
        $this->actingAs($user)->get(route('consumer-database.module', ['module' => 'bi-checking', 'filter_column' => 'status', 'filter' => 'Terbaru']))->assertOk()->assertSee('bi-checking:'.$latest->id, false)->assertSee('&lt;b&gt;Konsumen&lt;/b&gt;', false)->assertDontSee('<b>Konsumen</b>', false);

        $records = [
            'psjb' => ConsumerPsjb::create(['consumer_application_id' => $application->id, 'consumer_stage_event_id' => null, 'id_psjb' => 'PSJB-NULL', 'tanggal_psjb' => today(), 'status' => 'PSJB tanpa event']),
            'ppjb' => ConsumerPpjbDeveloper::create(['consumer_application_id' => $application->id, 'consumer_stage_event_id' => null, 'notes' => 'PPJB tanpa event']),
            'akad' => ConsumerAkadRecord::create(['consumer_application_id' => $application->id, 'consumer_stage_event_id' => null, 'kualitas_akad' => 'Akad tanpa event']),
            'bast' => ConsumerBastRecord::create(['consumer_application_id' => $application->id, 'consumer_stage_event_id' => null, 'tanggal_bast' => today()]),
        ];
        foreach ($records as $module => $record) {
            $this->actingAs($user)->get(route('consumer-database.module', $module))->assertOk()->assertSee($module.':'.$record->id, false);
        }
    }

    public function test_null_identity_unicode_long_text_and_invalid_routes_are_safe(): void
    {
        [$user, $branch, $project] = $this->context();
        $application = $this->application($branch, $project, 'Siti 日本 <script>alert(1)</script>');
        $application->update(['sales_user_id' => null, 'kavling_id' => null, 'current_stage' => null, 'notes' => str_repeat('panjang ', 100)]);

        $this->actingAs($user)->get(route('consumer-database.module', 'data-konsumen'))->assertOk()->assertSee('Siti 日本')->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false)->assertSee('—');
        $unknownUrl = str_replace('data-konsumen', 'tidak-ada', route('consumer-database.module', 'data-konsumen'));
        $this->actingAs($user)->get($unknownUrl)->assertNotFound();
        $this->actingAs($user)->get(route('consumer-database.module', ['module' => 'data-konsumen', 'view' => 'invalid']))->assertOk()->assertViewHas('viewMode', 'table');
    }

    public function test_query_count_is_bounded_for_data_and_process_pages(): void
    {
        [$user, $branch, $project] = $this->context();
        $create = function (int $number) use ($branch, $project): void {
            $application = $this->application($branch, $project, 'Query '.$number);
            ConsumerStageEvent::create(['consumer_application_id' => $application->id, 'stage' => 'bi_checking', 'status' => 'Selesai', 'occurred_at' => now()]);
        };
        $create(1);

        $count = function (string $module) use ($user): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($user)->get(route('consumer-database.module', $module))->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };
        $oneData = $count('data-konsumen');
        $oneProcess = $count('bi-checking');
        foreach (range(2, 25) as $number) {
            $create($number);
        }
        $this->assertLessThanOrEqual($oneData + 2, $count('data-konsumen'));
        $this->assertLessThanOrEqual($oneProcess + 2, $count('bi-checking'));
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
        $this->actingAs($user)->get(route('consumer-database.module', ['module' => 'data-konsumen', 'branch_id' => $branch->id, 'project_id' => $project->id]))->assertOk()->assertSee('Visible Consumer');

        $superadminRole = Role::firstOrCreate(['slug' => 'superadmin'], ['name' => 'Super Admin', 'is_superadmin' => true]);
        $superadminRole->update(['is_superadmin' => true]);
        $superadmin = User::factory()->create(['role_id' => $superadminRole->id, 'branch_id' => $branch->id, 'password_changed_at' => now()]);
        $this->actingAs($superadmin)->get(route('consumer-database.module', ['module' => 'data-konsumen', 'branch_id' => $otherBranch->id, 'project_id' => $otherProject->id]))->assertOk()->assertSee('Hidden Consumer')->assertDontSee('Visible Consumer');

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
