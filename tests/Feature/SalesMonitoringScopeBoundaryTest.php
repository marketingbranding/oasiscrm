<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\SalesCoordinatorSales;
use App\Models\SalesLead;
use App\Models\User;
use App\Services\AppSettingResolver;
use App\Services\OrganizationScopeService;
use App\Services\SalesLeadSheetOptionService;
use App\Services\SalesTeamScopeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class SalesMonitoringScopeBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google_sheets.sales_lead_sync_enabled' => true]);
    }

    public function test_br53_coordinator_lead_options_do_not_expose_another_coordinators_sales(): void
    {
        [$coordinator, $workspaceBranch, $outsideBranch] = $this->coordinatorWithWorkspaceBranches();
        $otherCoordinator = User::factory()->create([
            'name' => 'Koordinator Lain',
            'role_id' => Role::query()->where('slug', 'sales_coordinator')->value('id'),
            'branch_id' => $workspaceBranch->id,
            'password_changed_at' => now(),
        ]);
        $otherSales = User::factory()->create([
            'name' => 'Sales Koordinator Lain',
            'role_id' => Role::query()->where('slug', 'sales')->value('id'),
            'branch_id' => $workspaceBranch->id,
            'password_changed_at' => now(),
        ]);
        SalesCoordinatorSales::create([
            'coordinator_user_id' => $otherCoordinator->id,
            'sales_user_id' => $otherSales->id,
        ]);
        $this->setHierarchicalMonitoring(true);
        $scope = Mockery::mock(OrganizationScopeService::class);
        $scope->shouldReceive('branchIds')->with($coordinator, 'sales_pocketbook', 'view')->andReturn([$workspaceBranch->id]);
        $this->app->instance(OrganizationScopeService::class, $scope);
        $sheetOptions = Mockery::mock(SalesLeadSheetOptionService::class);
        $sheetOptions->shouldReceive('forBranch')->andReturn([
            'promo' => [], 'source' => [], 'channel' => [], 'activity' => [], 'project' => [],
            'sales' => [$otherSales->name], 'status' => [],
        ]);
        $teams = Mockery::mock(SalesTeamScopeService::class);
        $teams->shouldReceive('currentSales')->with($coordinator)->andReturn(collect());
        $this->app->instance(SalesTeamScopeService::class, $teams);
        $this->app->instance(SalesLeadSheetOptionService::class, $sheetOptions);

        $this->actingAs($coordinator)
            ->getJson(route('sales-leads.options', ['branch' => $workspaceBranch]))
            ->assertOk()
            ->assertJsonPath('options.sales', []);
    }

    public function test_br53_coordinator_export_rejects_foreign_sales_id_as_json_and_direct_url(): void
    {
        [$coordinator, $workspaceBranch] = $this->coordinatorWithWorkspaceBranches();
        $foreignSales = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'sales')->value('id'),
            'branch_id' => $workspaceBranch->id,
            'password_changed_at' => now(),
        ]);

        $this->actingAs($coordinator)
            ->get(route('coordinator-leads.export', ['period' => 'month', 'sales_id' => $foreignSales->id]))
            ->assertForbidden();
        $this->actingAs($coordinator)
            ->getJson(route('coordinator-leads.export', ['period' => 'month', 'sales_id' => $foreignSales->id]))
            ->assertForbidden();
    }

    public function test_br53_coordinator_comment_endpoints_reject_foreign_lead_direct_and_json_urls(): void
    {
        [$coordinator, $workspaceBranch] = $this->coordinatorWithWorkspaceBranches();
        $project = LeadMaster::where('branch_id', $workspaceBranch->id)->firstOrFail();
        $foreignSales = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'sales')->value('id'),
            'branch_id' => $workspaceBranch->id,
            'password_changed_at' => now(),
        ]);
        $foreignLead = SalesLead::create([
            'branch_id' => $workspaceBranch->id,
            'project_id' => $project->id,
            'sales_user_id' => $foreignSales->id,
            'lead_date' => today(),
            'customer_name' => 'Lead Koordinator Lain',
            'created_by' => $foreignSales->id,
        ]);

        $comment = $foreignLead->comments()->create([
            'user_id' => $foreignSales->id,
            'body' => 'Komentar asing',
            'body_plain' => 'Komentar asing',
        ]);

        $this->actingAs($coordinator)
            ->get(route('comments.thread', ['alias' => 'sales-lead', 'id' => $foreignLead->id]))
            ->assertForbidden();
        $this->actingAs($coordinator)
            ->postJson(route('comments.store'), [
                'alias' => 'sales-lead', 'id' => $foreignLead->id, 'body' => 'Komentar baru',
            ])
            ->assertForbidden();
        $this->assertDatabaseMissing('comments', ['body_plain' => 'Komentar baru']);
        $this->assertDatabaseHas('comments', ['id' => $comment->id]);
        $this->actingAs($coordinator)
            ->getJson(route('comments.index', ['alias' => 'sales-lead', 'id' => $foreignLead->id]))
            ->assertForbidden();
    }

    public function test_br51_flag_on_rejects_lead_options_for_workspace_branch_outside_organization_scope(): void
    {
        [$coordinator, $workspaceBranch, $outsideBranch] = $this->coordinatorWithWorkspaceBranches();
        $this->setHierarchicalMonitoring(true);
        $scope = Mockery::mock(OrganizationScopeService::class);
        $scope->shouldReceive('branchIds')
            ->with($coordinator, 'sales_pocketbook', 'view')
            ->andReturn([$workspaceBranch->id]);
        $this->app->instance(OrganizationScopeService::class, $scope);
        $this->mockSheetOptions();

        $this->actingAs($coordinator)
            ->get(route('sales-leads.options', ['branch' => $outsideBranch]))
            ->assertForbidden();
    }

    public function test_flag_off_preserves_workspace_only_lead_options_access(): void
    {
        [$coordinator, , $workspaceBranch] = $this->coordinatorWithWorkspaceBranches();
        $this->setHierarchicalMonitoring(false);
        $scope = Mockery::mock(OrganizationScopeService::class);
        $scope->shouldReceive('branchIds')
            ->with($coordinator, 'sales_pocketbook', 'view')
            ->never();
        $this->app->instance(OrganizationScopeService::class, $scope);
        $options = [
            'promo' => [],
            'source' => ['Referensi'],
            'channel' => [],
            'activity' => [],
            'project' => [],
            'sales' => [],
            'status' => [],
        ];
        $sheetOptions = Mockery::mock(SalesLeadSheetOptionService::class);
        $sheetOptions->shouldReceive('forBranch')->once()->with(Mockery::on(fn (Branch $branch): bool => $branch->is($workspaceBranch)))->andReturn($options);
        $this->app->instance(SalesLeadSheetOptionService::class, $sheetOptions);

        $this->actingAs($coordinator)
            ->get(route('sales-leads.options', ['branch' => $workspaceBranch]))
            ->assertOk()
            ->assertJsonPath('options.source', ['Referensi']);
    }

    private function coordinatorWithWorkspaceBranches(): array
    {
        $workspaceBranch = Branch::create(['name' => 'Workspace', 'code' => 'WKS', 'is_active' => true]);
        $outsideBranch = Branch::create(['name' => 'Outside', 'code' => 'OUT', 'is_active' => true]);
        $project = LeadMaster::create([
            'branch_id' => $workspaceBranch->id,
            'project_name' => 'Workspace Project',
            'is_active' => true,
        ]);
        $coordinator = User::factory()->create([
            'name' => 'Koordinator Scope',
            'role_id' => Role::query()->where('slug', 'sales_coordinator')->value('id'),
            'branch_id' => $workspaceBranch->id,
            'password_changed_at' => now(),
        ]);
        $coordinator->branches()->sync([
            $workspaceBranch->id => ['can_view' => true, 'can_edit' => false, 'can_sync' => false],
            $outsideBranch->id => ['can_view' => true, 'can_edit' => false, 'can_sync' => false],
        ]);
        $coordinator->assignedProjects()->attach($project, ['is_primary' => true, 'is_active' => true]);

        return [$coordinator, $workspaceBranch, $outsideBranch];
    }

    private function mockSheetOptions(): void
    {
        $sheetOptions = Mockery::mock(SalesLeadSheetOptionService::class);
        $sheetOptions->shouldReceive('forBranch')->andReturn([
            'promo' => [],
            'source' => [],
            'channel' => [],
            'activity' => [],
            'project' => [],
            'sales' => [],
            'status' => [],
        ]);
        $this->app->instance(SalesLeadSheetOptionService::class, $sheetOptions);
    }

    private function setHierarchicalMonitoring(bool $enabled): void
    {
        DB::table('app_settings')->updateOrInsert(
            ['key' => 'org_hierarchical_monitoring'],
            [
                'value' => json_encode($enabled, JSON_THROW_ON_ERROR),
                'group' => 'feature_flags',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
        app(AppSettingResolver::class)->forget('org_hierarchical_monitoring');
    }
}
