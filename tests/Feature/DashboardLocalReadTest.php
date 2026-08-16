<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ConsumerApplication;
use App\Models\ConsumerStageEvent;
use App\Models\Customer;
use App\Models\Kavling;
use App\Models\KonsumenProgressSheetRow;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\User;
use App\Services\KonsumenProgressReadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardLocalReadTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_dashboard_source_keeps_legacy_progress_counts(): void
    {
        config(['oasis.dashboard_consumer_read_source' => 'unexpected']);
        [$branch, $user, $project] = $this->context();
        $this->legacy($branch, 'Legacy Consumer', 'akad');
        $this->local($branch, $project, 'Local Consumer', 'bast');

        $progress = $this->actingAs($user)->get(route('dashboard', ['branch_id' => $branch->id]))->viewData('konsumenProgress');

        $this->assertSame(1, $progress['akad']['count']);
        $this->assertSame(0, $progress['bast']['count']);
    }

    public function test_default_dashboard_keeps_legacy_progress_counts(): void
    {
        [$branch, $user, $project] = $this->context();
        $this->legacy($branch, 'Legacy Consumer', 'akad');
        $this->local($branch, $project, 'Local Consumer', 'bast');

        $response = $this->actingAs($user)->get(route('dashboard', ['branch_id' => $branch->id]));

        $this->assertSame(1, $response->viewData('konsumenProgress')['akad']['count']);
        $this->assertSame(0, $response->viewData('konsumenProgress')['bast']['count']);
    }

    public function test_local_dashboard_counts_current_local_stages_only(): void
    {
        config(['oasis.dashboard_consumer_read_source' => 'local']);
        [$branch, $user, $project] = $this->context();
        $this->local($branch, $project, 'Local Consumer', 'akad');
        $application = $this->local($branch, $project, 'Local BAST', 'bast');
        ConsumerStageEvent::create(['consumer_application_id' => $application->id, 'stage' => 'akad', 'status' => 'historical', 'occurred_at' => now()->subDay()]);

        $progress = $this->actingAs($user)->get(route('dashboard', ['branch_id' => $branch->id]))->viewData('konsumenProgress');

        $this->assertSame(1, $progress['akad']['count']);
        $this->assertSame(1, $progress['bast']['count']);
    }

    public function test_local_dashboard_zero_does_not_fallback_to_legacy(): void
    {
        config(['oasis.dashboard_consumer_read_source' => 'local']);
        [$branch, $user] = $this->context();
        $this->legacy($branch, 'Legacy Consumer', 'akad');

        $progress = $this->actingAs($user)->get(route('dashboard', ['branch_id' => $branch->id]))->viewData('konsumenProgress');

        $this->assertSame(0, $progress['akad']['count']);
    }

    public function test_local_dashboard_failure_falls_back_to_legacy(): void
    {
        config(['oasis.dashboard_consumer_read_source' => 'local']);
        [$branch, $user] = $this->context();
        $this->legacy($branch, 'Legacy Consumer', 'akad');
        $reader = \Mockery::mock(KonsumenProgressReadService::class);
        $reader->shouldReceive('read')->andThrow(new \RuntimeException('database unavailable'));
        $this->app->instance(KonsumenProgressReadService::class, $reader);

        $progress = $this->actingAs($user)->get(route('dashboard', ['branch_id' => $branch->id]))->viewData('konsumenProgress');

        $this->assertSame(1, $progress['akad']['count']);
    }

    public function test_local_dashboard_isolates_branch(): void
    {
        config(['oasis.dashboard_consumer_read_source' => 'local']);
        [$branch, $user, $project] = $this->context();
        [$otherBranch, , $otherProject] = $this->context();
        $this->local($branch, $project, 'Branch A', 'akad');
        $this->local($otherBranch, $otherProject, 'Branch B', 'akad');

        $progress = $this->actingAs($user)->get(route('dashboard', ['branch_id' => $branch->id]))->viewData('konsumenProgress');

        $this->assertSame(1, $progress['akad']['count']);
    }

    private function context(): array
    {
        $role = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin', 'is_active' => true]);
        $branch = Branch::create(['name' => 'Branch '.str()->random(4), 'code' => 'B'.str()->upper(str()->random(2)), 'is_active' => true, 'sheet_id' => 'sheet-'.str()->random(4)]);
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Project '.str()->random(4), 'is_active' => true]);
        $user = User::factory()->create(['role_id' => $role->id, 'branch_id' => $branch->id, 'password_changed_at' => now()]);

        return [$branch, $user, $project];
    }

    private function local(Branch $branch, LeadMaster $project, string $name, string $stage): ConsumerApplication
    {
        $kavling = Kavling::create(['project_id' => $project->id, 'kavling_code' => 'K-'.str()->random(5), 'name' => $name]);
        $application = ConsumerApplication::create(['customer_id' => Customer::create(['name' => $name])->id, 'branch_id' => $branch->id, 'project_id' => $project->id, 'kavling_id' => $kavling->id, 'application_status' => 'active', 'current_stage' => $stage]);
        ConsumerStageEvent::create(['consumer_application_id' => $application->id, 'stage' => $stage, 'status' => 'current', 'occurred_at' => now()]);

        return $application;
    }

    private function legacy(Branch $branch, string $name, string $stage): void
    {
        KonsumenProgressSheetRow::create(['branch_id' => $branch->id, 'sheet_id' => $branch->sheet_id, 'sheet_name' => 'data_konsumen', 'row_hash' => str()->uuid(), 'row_data' => ['id_kavling' => 'LEGACY-01', 'nama_konsumen' => $name, 'project_name' => 'Project']]);
        KonsumenProgressSheetRow::create(['branch_id' => $branch->id, 'sheet_id' => $branch->sheet_id, 'sheet_name' => $stage, 'row_hash' => str()->uuid(), 'row_data' => ['id_kavling' => 'LEGACY-01']]);
    }
}
