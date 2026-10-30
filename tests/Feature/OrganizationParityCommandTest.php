<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Services\OrganizationGraphShadowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrganizationParityCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_json_command_returns_machine_readable_report_without_writes(): void
    {
        $viewer = $this->user('supervisor', $this->branch('CMD'));
        $beforeAssignments = DB::table('organization_assignments')->count();
        $beforeSupervisor = User::query()->whereKey($viewer->id)->value('supervisor_user_id');
        $beforeMappings = DB::table('sales_coordinator_sales')->count();

        $exitCode = Artisan::call('organization:parity', [
            '--user' => (string) $viewer->id,
            '--scope' => 'descendants',
            '--json' => true,
        ]);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertArrayHasKey('meta', $report);
        $this->assertArrayHasKey('comparisons', $report);
        $this->assertArrayHasKey('gate', $report);
        $this->assertArrayHasKey('access_expansion', $report['summary']);
        $this->assertArrayHasKey('access_loss', $report['summary']);
        $this->assertArrayHasKey('critical', $report['summary']);
        $this->assertArrayHasKey('conflicts', $report['summary']);
        $this->assertSame($beforeAssignments, DB::table('organization_assignments')->count());
        $this->assertSame($beforeSupervisor, User::query()->whereKey($viewer->id)->value('supervisor_user_id'));
        $this->assertSame($beforeMappings, DB::table('sales_coordinator_sales')->count());
    }

    public function test_invalid_scope_and_role_filters_fail_without_broadening_the_report(): void
    {
        $this->assertSame(1, Artisan::call('organization:parity', ['--scope' => 'invalid']));
        $this->assertSame(1, Artisan::call('organization:parity', ['--role' => 'invalid-role']));
    }

    public function test_critical_and_expansion_filters_are_forwarded_without_mutation(): void
    {
        $branch = $this->branch('CMF');
        $viewer = $this->user('manager', $branch);
        $graphOnly = $this->user('staff', $branch);
        DB::table('organization_assignments')->insert([
            'user_id' => $graphOnly->id,
            'parent_user_id' => $viewer->id,
            'organization_unit_id' => $viewer->organization_unit_id,
            'relationship_type' => 'reports_to',
            'branch_id' => $branch->id,
            'started_at' => today()->toDateString(),
            'is_active' => true,
            'is_primary' => true,
            'source' => 'command_fixture',
            'lock_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $before = DB::table('organization_assignments')->count();

        $exitCode = Artisan::call('organization:parity', [
            '--user' => (string) $viewer->id,
            '--module' => 'work_planner',
            '--only-differences' => true,
            '--only-critical' => true,
            '--only-expansion' => true,
            '--json' => true,
        ]);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertNotEmpty($report['comparisons']);
        $this->assertTrue(collect($report['comparisons'])->every(fn (array $comparison): bool => $comparison['severity'] === 'SECURITY_CRITICAL'));
        $this->assertTrue(collect($report['comparisons'])->every(fn (array $comparison): bool => $comparison['access_direction'] === 'EXPANSION'));
        $this->assertSame($before, DB::table('organization_assignments')->count());
    }

    public function test_invalid_assessment_filter_fails(): void
    {
        $this->assertSame(1, Artisan::call('organization:parity', ['--severity' => 'not-a-severity']));
    }

    public function test_missing_user_filter_fails_instead_of_returning_clean_parity(): void
    {
        $this->assertSame(1, Artisan::call('organization:parity', ['--user' => '999999999']));
    }

    public function test_cutover_health_command_returns_json_without_failing_for_blocked_health(): void
    {
        $viewer = $this->user('staff', $this->branch('HLT'));
        config(['organization.controlled_evidence_marker' => true]);
        $shadow = app(OrganizationGraphShadowService::class);
        $shadow->record('reporting_descendants', true);
        $shadow->record('sales_team_scope', true);
        $shadow->record('hierarchy_ids', true);

        $exitCode = Artisan::call('organization:cutover-health', [
            '--user' => [$viewer->id],
            '--controlled-evidence' => true,
            '--json' => true,
        ]);
        $health = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame('READY', $health['status']);
        $this->assertArrayHasKey('blockers', $health);
    }

    public function test_cutover_health_command_rejects_invalid_user_selection(): void
    {
        $this->assertSame(1, Artisan::call('organization:cutover-health', ['--user' => ['invalid']]));
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
}
