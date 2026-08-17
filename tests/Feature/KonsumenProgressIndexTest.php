<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ConsumerApplication;
use App\Models\ConsumerBankProcess;
use App\Models\ConsumerStageEvent;
use App\Models\Customer;
use App\Models\Kavling;
use App\Models\KonsumenProgressSheetRow;
use App\Models\KonsumenProgressSyncStatus;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\User;
use App\Services\KonsumenPipelineService;
use App\Services\KonsumenProgressReadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KonsumenProgressIndexTest extends TestCase
{
    use RefreshDatabase;

    private function branchAndUser(string $roleSlug = 'admin', bool $withSheet = true): array
    {
        $role = Role::firstOrCreate(['slug' => $roleSlug], ['name' => $roleSlug, 'is_superadmin' => $roleSlug === 'superadmin']);
        $branch = Branch::create([
            'name' => 'Jepara '.str()->random(4),
            'code' => 'J'.str()->upper(str()->random(2)),
            'is_active' => true,
            'sheet_id' => $withSheet ? 'sheet-jepara' : null,
        ]);
        $user = User::factory()->create([
            'role_id' => $role->id,
            'branch_id' => $branch->id,
            'password_changed_at' => now(),
        ]);

        return [$branch, $user];
    }

    private function pipelineCustomer(Branch $branch, string $idKavling, string $name, string $stage): void
    {
        KonsumenProgressSheetRow::create([
            'branch_id' => $branch->id,
            'sheet_id' => $branch->sheet_id,
            'sheet_name' => 'data_konsumen',
            'row_hash' => 'konsumen-'.$branch->id.'-'.$idKavling,
            'row_data' => ['id_kavling' => $idKavling, 'nama_konsumen' => $name, 'project_name' => 'Oasis Jepara'],
        ]);
        KonsumenProgressSheetRow::create([
            'branch_id' => $branch->id,
            'sheet_id' => $branch->sheet_id,
            'sheet_name' => $stage,
            'row_hash' => $stage.'-'.$branch->id.'-'.$idKavling,
            'row_data' => ['id_kavling' => $idKavling],
        ]);
    }

    public function test_index_renders_canonical_header_toolbar_and_sync_panel(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $this->pipelineCustomer($branch, 'A-01', 'Budi Santoso', 'akad');

        $html = $this->actingAs($user)->get(route('konsumen-progress.index'))->getContent();

        $this->assertStringContainsString('Konsumen Progress', $html);
        $this->assertStringContainsString('crm-page-header', $html);
        $this->assertStringContainsString('crm-toolbar', $html);
        $this->assertStringContainsString('name="branch_id" value="'.$branch->id.'"', $html);
        $this->assertStringContainsString('Budi Santoso', $html);
    }

    public function test_stage_counts_match_pipeline_and_tabs_expose_pressed_state(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $this->pipelineCustomer($branch, 'A-01', 'Budi Santoso', 'akad');
        $this->pipelineCustomer($branch, 'A-02', 'Siti Aminah', 'akad');
        $this->pipelineCustomer($branch, 'B-01', 'Joko Widodo', 'bast');

        $response = $this->actingAs($user)->get(route('konsumen-progress.index'));
        $response->assertOk();

        $pipeline = $response->viewData('pipeline');
        $this->assertCount(2, $pipeline['akad']);
        $this->assertCount(1, $pipeline['bast']);
        $this->assertCount(0, $pipeline['bi_checking']);

        $html = $response->getContent();
        $this->assertStringContainsString(':aria-pressed="stage === \'akad\'"', $html);
        $this->assertStringContainsString('aria-label="Lihat konsumen tahap Akad"', $html);
        $this->assertStringContainsString('role="group"', $html);
        $this->assertStringContainsString('aria-label="Tahap konsumen"', $html);
    }

    public function test_search_input_has_accessible_label(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $this->pipelineCustomer($branch, 'A-01', 'Budi Santoso', 'akad');

        $html = $this->actingAs($user)->get(route('konsumen-progress.index'))->getContent();

        $this->assertStringContainsString('aria-label="Cari konsumen progress berdasarkan nama atau kavling"', $html);
        $this->assertStringContainsString('window.__kpItems', $html);
    }

    public function test_sync_control_hidden_for_view_only_role(): void
    {
        [$branch, $user] = $this->branchAndUser('manager');
        $this->pipelineCustomer($branch, 'A-01', 'Budi Santoso', 'akad');

        $response = $this->actingAs($user)->get(route('konsumen-progress.index'));

        $response->assertOk()->assertDontSee('name="branch_id" value="'.$branch->id.'"', false);
    }

    public function test_empty_pipeline_shows_local_data_alert(): void
    {
        [$branch, $user] = $this->branchAndUser();

        $response = $this->actingAs($user)->get(route('konsumen-progress.index'));

        $response->assertOk()
            ->assertSee('Gagal memuat beberapa stage')
            ->assertSee('Data lokal belum tersedia. Klik Sync Sekarang terlebih dahulu.');
    }

    public function test_branch_without_sheet_shows_empty_state(): void
    {
        [$branch, $user] = $this->branchAndUser('admin', false);

        $response = $this->actingAs($user)->get(route('konsumen-progress.index'));

        $response->assertOk()->assertSee('Database branch belum tersedia.');
    }

    public function test_stage_json_endpoint_returns_canonical_stage_items(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $this->pipelineCustomer($branch, 'A-01', 'Budi Santoso', 'akad');

        $response = $this->actingAs($user)->getJson(route('konsumen-progress.stage', [
            'branch_id' => $branch->id,
            'stage' => 'akad',
        ]));

        $response->assertOk()->assertJsonPath('ok', true)->assertJsonPath('count', 1);
        $this->assertSame('Budi Santoso', $response->json('items.0.nama'));
    }

    public function test_unauthorized_explicit_branch_denied_on_index(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $other = Branch::create(['name' => 'Pati', 'code' => 'PTI', 'is_active' => true, 'sheet_id' => 'sheet-pati']);

        $this->actingAs($user)->get(route('konsumen-progress.index', ['branch_id' => $other->id]))->assertForbidden();
    }

    public function test_unauthorized_role_denied_index(): void
    {
        [$branch] = $this->branchAndUser();
        $sales = Role::firstOrCreate(['slug' => 'sales'], ['name' => 'Sales', 'is_superadmin' => false]);
        $user = User::factory()->create([
            'role_id' => $sales->id,
            'branch_id' => $branch->id,
            'password_changed_at' => now(),
        ]);

        $this->actingAs($user)->get(route('konsumen-progress.index'))->assertForbidden();
    }

    public function test_stale_sync_status_is_passed_to_view(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $this->pipelineCustomer($branch, 'A-01', 'Budi Santoso', 'akad');
        KonsumenProgressSyncStatus::create(['branch_id' => $branch->id, 'status' => 'success', 'finished_at' => now()->subMinutes(45)]);

        $response = $this->actingAs($user)->get(route('konsumen-progress.index'));

        $response->assertOk()->assertViewHas('isStale', true);
    }

    public function test_default_source_remains_legacy_even_when_local_application_exists(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $this->pipelineCustomer($branch, 'A-01', 'Legacy Budi', 'akad');
        $this->localApplication($branch, 'Local Budi', 'bast');

        $response = $this->actingAs($user)->get(route('konsumen-progress.index'));

        $response->assertOk()->assertViewHas('readSource', 'legacy')->assertSee('Legacy Budi')->assertDontSee('Local Budi');
    }

    public function test_local_mode_renders_local_application_and_stage_counts_once(): void
    {
        config(['oasis.consumer_progress_read_source' => 'local']);
        [$branch, $user] = $this->branchAndUser();
        $this->localApplication($branch, 'Local Budi', 'akad');
        $this->localApplication($branch, 'Local Siti', 'bast');

        $response = $this->actingAs($user)->get(route('konsumen-progress.index'));

        $response->assertOk()->assertViewHas('readSource', 'local')->assertSee('Local Budi')->assertSee('Local Siti');
        $this->assertCount(1, $response->viewData('pipeline')['akad']);
        $this->assertCount(1, $response->viewData('pipeline')['bast']);
    }

    public function test_local_read_failure_falls_back_to_legacy_and_logs_warning(): void
    {
        config(['oasis.consumer_progress_read_source' => 'local']);
        [$branch, $user] = $this->branchAndUser();
        $this->pipelineCustomer($branch, 'A-01', 'Legacy Budi', 'akad');
        $reader = \Mockery::mock(KonsumenProgressReadService::class);
        $reader->shouldReceive('read')->once()->andReturn(['pipeline' => app(KonsumenPipelineService::class)->buildPipeline($branch), 'source' => 'legacy', 'fallback_used' => true]);
        $this->app->instance(KonsumenProgressReadService::class, $reader);

        $this->actingAs($user)->get(route('konsumen-progress.index'))->assertOk()->assertSee('Legacy Budi')->assertViewHas('fallbackUsed', true);
    }

    public function test_local_mode_keeps_empty_result_empty_without_legacy_fallback(): void
    {
        config(['oasis.consumer_progress_read_source' => 'local']);
        [$branch, $user] = $this->branchAndUser();
        $this->pipelineCustomer($branch, 'A-01', 'Legacy Budi', 'akad');

        $response = $this->actingAs($user)->get(route('konsumen-progress.index'));

        $response->assertOk()->assertViewHas('readSource', 'local')->assertViewHas('pipeline', fn (array $pipeline) => array_sum(array_map('count', $pipeline)) === 0)->assertDontSee('Legacy Budi');
    }

    public function test_local_mode_isolates_consumer_applications_by_branch(): void
    {
        config(['oasis.consumer_progress_read_source' => 'local']);
        [$branch, $user] = $this->branchAndUser();
        [$otherBranch] = $this->branchAndUser();
        $this->localApplication($branch, 'Branch A Consumer', 'akad');
        $this->localApplication($otherBranch, 'Branch B Consumer', 'akad');

        $response = $this->actingAs($user)->get(route('konsumen-progress.index'));

        $response->assertOk()->assertSee('Branch A Consumer')->assertDontSee('Branch B Consumer');
    }

    public function test_local_adapter_isolates_consumer_applications_by_project(): void
    {
        config(['oasis.consumer_progress_read_source' => 'local']);
        [$branch] = $this->branchAndUser();
        $projectA = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Project A', 'is_active' => true]);
        $projectB = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Project B', 'is_active' => true]);
        $this->localApplicationForProject($branch, $projectA, 'Project A Consumer', 'akad');
        $this->localApplicationForProject($branch, $projectB, 'Project B Consumer', 'akad');

        $result = app(KonsumenProgressReadService::class)->read($branch, $projectA);
        $items = collect($result['pipeline'])->flatten(1);

        $this->assertTrue($items->contains('nama', 'Project A Consumer'));
        $this->assertFalse($items->contains('nama', 'Project B Consumer'));
    }

    public function test_local_consumer_page_filters_search_and_masks_nik(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $project = LeadMaster::firstOrCreate(['branch_id' => $branch->id, 'project_name' => 'Oasis Jepara'], ['is_active' => true]);
        $kavling = Kavling::create(['project_id' => $project->id, 'kavling_code' => 'LOCAL-A1', 'name' => 'LOCAL-A1']);
        $sales = User::factory()->create(['role_id' => $user->role_id, 'branch_id' => $branch->id, 'password_changed_at' => now()]);
        $customer = Customer::create(['name' => 'Data Lengkap Budi', 'phone' => '081234567890', 'nik_encrypted' => '1234567890123456']);
        ConsumerApplication::create(['customer_id' => $customer->id, 'branch_id' => $branch->id, 'project_id' => $project->id, 'sales_user_id' => $sales->id, 'kavling_id' => $kavling->id, 'application_status' => 'active', 'consumer_status' => 'Lanjut', 'source_last_process' => 'Akad', 'source_completeness_status' => 'Data Lengkap']);

        $response = $this->actingAs($user)->get(route('consumer-local.index', ['search' => '081234567890', 'source_completeness_status' => 'Data Lengkap', 'consumer_status' => 'Lanjut', 'source_last_process' => 'Akad']));

        $response->assertOk()->assertSee('Data Lengkap Budi')->assertSee('Data Lengkap')->assertSee('Lanjut')->assertSee('Akad')->assertDontSee('1234567890123456');
        $this->actingAs($user)->getJson(route('consumer-local.show', ['application' => $customer->applications()->first()->id]))->assertOk()->assertJsonPath('data.nik', '••••••••••••••••');
    }

    public function test_local_consumer_page_hides_other_branch_and_paginates(): void
    {
        [$branch, $user] = $this->branchAndUser();
        [$otherBranch] = $this->branchAndUser();
        for ($i = 1; $i <= 26; $i++) {
            $this->localApplication($branch, 'Allowed Consumer '.$i, 'akad');
        }
        $this->localApplication($otherBranch, 'Hidden Consumer', 'akad');

        $response = $this->actingAs($user)->get(route('consumer-local.index', ['page' => 2]));

        $response->assertOk()->assertDontSee('Hidden Consumer')->assertSee('page=1');
    }

    public function test_local_consumer_detail_denies_other_branch(): void
    {
        [$branch, $user] = $this->branchAndUser();
        [$otherBranch] = $this->branchAndUser();
        $application = $this->localApplication($otherBranch, 'Hidden Consumer', 'akad');

        $this->actingAs($user)->getJson(route('consumer-local.show', $application))->assertForbidden();
    }

    private function localApplication(Branch $branch, string $name, string $stage): ConsumerApplication
    {
        $project = LeadMaster::firstOrCreate(['branch_id' => $branch->id, 'project_name' => 'Oasis Jepara'], ['is_active' => true]);

        return $this->localApplicationForProject($branch, $project, $name, $stage);
    }

    private function localApplicationForProject(Branch $branch, LeadMaster $project, string $name, string $stage): ConsumerApplication
    {
        $kavling = Kavling::create(['project_id' => $project->id, 'kavling_code' => 'LOCAL-'.str()->random(5), 'name' => $name]);
        $application = ConsumerApplication::create(['customer_id' => Customer::create(['name' => $name, 'phone' => '0812345678'])->id, 'branch_id' => $branch->id, 'project_id' => $project->id, 'kavling_id' => $kavling->id, 'application_status' => 'active', 'current_stage' => $stage, 'booking_date' => '2026-08-01']);
        ConsumerStageEvent::create(['consumer_application_id' => $application->id, 'stage' => $stage, 'status' => 'current', 'occurred_at' => now()]);
        ConsumerBankProcess::create(['consumer_application_id' => $application->id, 'bank_name' => 'BCA', 'status' => 'submitted', 'submitted_at' => now()]);

        return $application;
    }
}
