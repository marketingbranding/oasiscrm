<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\OrganizationAssignment;
use App\Models\Role;
use App\Models\User;
use App\Services\OrganizationBackfillService;
use App\Services\OrganizationGraphService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrganizationBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_proposes_legacy_supervisor_relationship_without_writing_graph_data(): void
    {
        $branch = $this->branch('BFL');
        $parent = $this->user('supervisor', $branch);
        $child = $this->user('staff', $branch);
        $child->forceFill(['supervisor_user_id' => $parent->id])->saveQuietly();
        $before = OrganizationAssignment::query()->count();

        $report = app(OrganizationBackfillService::class)->run(true);

        $this->assertSame(1, $report['assignments_proposed']);
        $this->assertSame(0, $report['created']);
        $this->assertSame($before, OrganizationAssignment::query()->count());
        $proposal = $report['proposals'][0];
        $this->assertSame($child->id, $proposal['user_id']);
        $this->assertSame($parent->id, $proposal['parent_user_id']);
        $this->assertSame('MISSING_GRAPH_ASSIGNMENT', $proposal['classification']);
        $this->assertSame('HIGH', $proposal['confidence']);
        $this->assertSame($branch->id, $proposal['branch_id']);
        $this->assertFalse($proposal['canonical_assignment_active']);
    }

    public function test_coordinator_mapping_is_used_when_no_legacy_supervisor_exists(): void
    {
        $branch = $this->branch('BFC');
        $coordinator = $this->user('sales_coordinator', $branch);
        $sales = $this->user('sales', $branch);
        DB::table('sales_coordinator_sales')->insert([
            'coordinator_user_id' => $coordinator->id,
            'sales_user_id' => $sales->id,
            'is_active' => true,
            'started_at' => today()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $report = app(OrganizationBackfillService::class)->run(true);

        $this->assertSame(1, $report['assignments_proposed']);
        $this->assertSame(0, $report['conflicts']);
    }

    public function test_multiple_active_coordinators_are_not_inferred(): void
    {
        $branch = $this->branch('BFC');
        $supervisor = $this->user('supervisor', $branch);
        $firstCoordinator = $this->user('sales_coordinator', $branch);
        $secondCoordinator = $this->user('sales_coordinator', $branch);
        $sales = $this->user('sales', $branch);
        $sales->forceFill(['supervisor_user_id' => $supervisor->id])->saveQuietly();
        foreach ([$firstCoordinator, $secondCoordinator] as $coordinator) {
            DB::table('sales_coordinator_sales')->insert([
                'coordinator_user_id' => $coordinator->id,
                'sales_user_id' => $sales->id,
                'is_active' => true,
                'started_at' => today()->toDateString(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $report = app(OrganizationBackfillService::class)->run(true);

        $this->assertSame(1, $report['multiple_active_coordinator_assignments']);
        $this->assertSame(1, $report['assignments_proposed']);
        $this->assertTrue(collect($report['details'])->contains(fn (array $detail): bool => $detail['user_id'] === $sales->id));
    }

    public function test_active_supervisor_and_coordinator_relationships_produce_independent_proposals(): void
    {
        $branch = $this->branch('BFV');
        $supervisor = $this->user('supervisor', $branch);
        $coordinator = $this->user('sales_coordinator', $branch);
        $salesUsers = collect(range(1, 4))->map(fn (): User => $this->user('sales', $branch));

        foreach ($salesUsers as $sales) {
            $sales->forceFill(['supervisor_user_id' => $supervisor->id])->saveQuietly();
            DB::table('sales_coordinator_sales')->insert([
                'coordinator_user_id' => $coordinator->id,
                'sales_user_id' => $sales->id,
                'is_active' => true,
                'started_at' => today()->toDateString(),
                'ended_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $report = app(OrganizationBackfillService::class)->run(true);
        $proposals = collect($report['proposals']);

        $this->assertSame(8, $report['assignments_proposed']);
        $this->assertSame(0, $report['conflicts']);
        $this->assertCount(4, $proposals->where('relationship_type', 'reports_to'));
        $this->assertCount(4, $proposals->where('relationship_type', 'sales_team_member_of'));
        $this->assertTrue($proposals->every(fn (array $proposal): bool => $proposal['branch_id'] === $branch->id));
        $this->assertDatabaseCount('organization_assignments', 0);
    }

    public function test_dual_relationship_backfill_is_idempotent_without_collapsing_dimensions(): void
    {
        $branch = $this->branch('BFD');
        $supervisor = $this->user('supervisor', $branch);
        $coordinator = $this->user('sales_coordinator', $branch);
        $sales = $this->user('sales', $branch);
        $sales->forceFill(['supervisor_user_id' => $supervisor->id])->saveQuietly();
        DB::table('sales_coordinator_sales')->insert([
            'coordinator_user_id' => $coordinator->id,
            'sales_user_id' => $sales->id,
            'is_active' => true,
            'started_at' => today()->toDateString(),
            'ended_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $service = app(OrganizationBackfillService::class);

        $dryRun = $service->run(true);

        $this->assertSame(2, $dryRun['assignments_proposed']);
        $this->assertSame(0, $dryRun['created']);
        $this->assertDatabaseCount('organization_assignments', 0);

        $actual = $service->run(false);

        $this->assertSame(2, $actual['created']);
        $this->assertDatabaseHas('organization_assignments', [
            'user_id' => $sales->id,
            'parent_user_id' => $supervisor->id,
            'relationship_type' => 'reports_to',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('organization_assignments', [
            'user_id' => $sales->id,
            'parent_user_id' => $coordinator->id,
            'relationship_type' => 'sales_team_member_of',
            'is_active' => true,
        ]);

        $again = $service->run(false);

        $this->assertSame(0, $again['created']);
        $this->assertSame(2, $again['already_migrated']);
        $this->assertSame(2, OrganizationAssignment::query()->current()->where('user_id', $sales->id)->count()
            + OrganizationAssignment::query()->currentSalesTeam()->where('user_id', $sales->id)->count());
    }

    public function test_inactive_parent_cycle_and_cross_branch_relationships_are_reported_without_writes(): void
    {
        $branch = $this->branch('BFX');
        $foreignBranch = $this->branch('BFY');
        $inactiveParent = $this->user('supervisor', $branch);
        $inactiveParent->forceFill(['is_active' => false])->saveQuietly();
        $inactiveChild = $this->user('staff', $branch);
        $inactiveChild->forceFill(['supervisor_user_id' => $inactiveParent->id])->saveQuietly();

        $cycleFirst = $this->user('supervisor', $branch);
        $cycleSecond = $this->user('staff', $branch);
        $cycleFirst->forceFill(['supervisor_user_id' => $cycleSecond->id])->saveQuietly();
        $cycleSecond->forceFill(['supervisor_user_id' => $cycleFirst->id])->saveQuietly();

        $foreignParent = $this->user('supervisor', $foreignBranch);
        $foreignChild = $this->user('staff', $branch);
        $foreignChild->forceFill(['supervisor_user_id' => $foreignParent->id])->saveQuietly();
        $before = OrganizationAssignment::query()->count();

        $report = app(OrganizationBackfillService::class)->run(true);

        $this->assertGreaterThanOrEqual(1, $report['inactive_parents']);
        $this->assertGreaterThanOrEqual(2, $report['cycles']);
        $this->assertGreaterThanOrEqual(1, $report['cross_scope_anomalies']);
        $this->assertSame($before, OrganizationAssignment::query()->count());
    }

    public function test_matching_assignment_is_idempotent_and_repeated_dry_runs_are_stable(): void
    {
        $branch = $this->branch('BFI');
        $parent = $this->user('supervisor', $branch);
        $child = $this->user('staff', $branch);
        app(OrganizationGraphService::class)->assign($child, $parent);

        $first = app(OrganizationBackfillService::class)->run(true);
        $second = app(OrganizationBackfillService::class)->run(true);

        $this->assertSame(1, $first['already_migrated']);
        $this->assertSame($first['assignments_proposed'], $second['assignments_proposed']);
        $this->assertSame($first['conflicts'], $second['conflicts']);
        $this->assertSame($first['proposals'], $second['proposals']);
        $this->assertSame($first['details'], $second['details']);
    }

    public function test_controlled_backfill_requires_confirmation_and_explicit_isolated_marker(): void
    {
        $branch = $this->branch('GUA');
        $parent = $this->user('supervisor', $branch);
        $child = $this->user('staff', $branch);
        $child->forceFill(['supervisor_user_id' => $parent->id])->saveQuietly();
        config(['app.env' => 'testing', 'organization.backfill_isolated' => false]);

        $report = app(OrganizationBackfillService::class)->runControlled([$child->id], true, false);

        $this->assertFalse($report['safety']['allowed']);
        $this->assertContains('confirm_isolated_required', $report['safety']['reasons']);
        $this->assertContains('isolated_marker_required', $report['safety']['reasons']);
        $this->assertSame(0, $report['created']);
        $this->assertDatabaseCount('organization_assignments', 0);
    }

    public function test_controlled_backfill_is_idempotent_for_selected_dual_relationships(): void
    {
        $branch = $this->branch('GID');
        $supervisor = $this->user('supervisor', $branch);
        $coordinator = $this->user('sales_coordinator', $branch);
        $sales = $this->user('sales', $branch);
        $sales->forceFill(['supervisor_user_id' => $supervisor->id])->saveQuietly();
        $this->legacySalesMapping($coordinator, $sales);
        config(['app.env' => 'testing', 'organization.backfill_isolated' => true]);
        $service = app(OrganizationBackfillService::class);

        $first = $service->runControlled([$sales->id], true, true);
        $second = $service->runControlled([$sales->id], true, true);
        $third = $service->runControlled([$sales->id], false, true);

        $this->assertTrue($first['safety']['allowed']);
        $this->assertSame(2, $first['created']);
        $this->assertSame(0, $second['created']);
        $this->assertSame(2, $second['already_migrated']);
        $this->assertSame(0, $third['assignments_proposed']);
        $this->assertSame(2, $third['already_migrated']);
        $this->assertSame($first['before']['assignment_count'] + $first['created'], $second['before']['assignment_count']);
        $this->assertSame($first['after']['assignment_count'], $second['after']['assignment_count']);
        $this->assertSame(2, OrganizationAssignment::query()->count());
        $this->assertSame($first['proposals'], $first['loss_analysis']['proposals']);
    }

    public function test_controlled_backfill_rejects_production_environment_before_writing(): void
    {
        $branch = $this->branch('GPR');
        $parent = $this->user('supervisor', $branch);
        $child = $this->user('staff', $branch);
        $child->forceFill(['supervisor_user_id' => $parent->id])->saveQuietly();
        config(['app.env' => 'production', 'organization.backfill_isolated' => true]);

        $report = app(OrganizationBackfillService::class)->runControlled([$child->id], true, true);

        $this->assertFalse($report['safety']['allowed']);
        $this->assertContains('unsafe_environment', $report['safety']['reasons']);
        $this->assertSame(0, $report['created']);
        $this->assertDatabaseCount('organization_assignments', 0);
    }

    public function test_controlled_backfill_requires_a_non_production_database_identity(): void
    {
        $branch = $this->branch('DBI');
        $parent = $this->user('supervisor', $branch);
        $child = $this->user('staff', $branch);
        $child->forceFill(['supervisor_user_id' => $parent->id])->saveQuietly();
        config([
            'app.env' => 'local',
            'organization.backfill_isolated' => true,
            'organization.backfill_database_identity' => '',
        ]);

        $report = app(OrganizationBackfillService::class)->runControlled([$child->id], true, true);

        $this->assertFalse($report['safety']['allowed']);
        $this->assertContains('production_database_identity', $report['safety']['reasons']);
        $this->assertDatabaseCount('organization_assignments', 0);
    }

    private function branch(string $code): Branch
    {
        return Branch::query()->create(['name' => $code, 'code' => $code, 'is_active' => true]);
    }

    private function user(string $roleSlug, Branch $branch): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $roleSlug)->firstOrFail()->id,
            'branch_id' => $branch->id,
        ]);
    }

    private function legacySalesMapping(User $coordinator, User $sales): void
    {
        DB::table('sales_coordinator_sales')->insert([
            'coordinator_user_id' => $coordinator->id,
            'sales_user_id' => $sales->id,
            'is_active' => true,
            'started_at' => today()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
