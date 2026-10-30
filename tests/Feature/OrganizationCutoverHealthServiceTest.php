<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Services\OrganizationCutoverHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrganizationCutoverHealthServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_relationship_specific_access_loss_blocks_health_gate(): void
    {
        $branch = $this->branch('HLT');
        $supervisor = $this->user('supervisor', $branch);
        $coordinator = $this->user('sales_coordinator', $branch);
        $sales = $this->user('sales', $branch);
        $sales->forceFill(['supervisor_user_id' => $supervisor->id])->saveQuietly();
        DB::table('sales_coordinator_sales')->insert([
            'coordinator_user_id' => $coordinator->id,
            'sales_user_id' => $sales->id,
            'is_active' => true,
            'started_at' => today()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $health = app(OrganizationCutoverHealthService::class)->check([$supervisor->id, $coordinator->id]);

        $this->assertSame('BLOCKED', $health['status']);
        $this->assertGreaterThanOrEqual(1, $health['parity']['access_loss']);
        $this->assertContains('parity_access_loss', $health['blockers']);
        $this->assertContains('parity_reporting_conflict', $health['blockers']);
        $this->assertContains('parity_sales_team_conflict', $health['blockers']);
        $this->assertContains('controlled_evidence_incomplete', $health['blockers']);
        $this->assertSame('legacy', $health['mode']['production_read_source']);
        $this->assertFalse($health['mode']['production_cutover_performed']);
    }

    public function test_complete_equivalent_controlled_evidence_is_ready(): void
    {
        $viewer = $this->user('staff', $this->branch('RDY'));

        $health = app(OrganizationCutoverHealthService::class)->check([$viewer->id], true);

        $this->assertSame('READY', $health['status']);
        $this->assertTrue(app(OrganizationCutoverHealthService::class)->isReady($health));
        $this->assertSame(0, $health['parity']['mismatches']);
        $this->assertSame([], $health['blockers']);
    }

    public function test_health_json_contract_contains_stable_sections_without_user_pii(): void
    {
        $viewer = $this->user('staff', $this->branch('JSON'));

        $health = app(OrganizationCutoverHealthService::class)->check([$viewer->id], true);
        $json = json_encode($health, JSON_THROW_ON_ERROR);

        foreach (['status', 'reasons', 'blockers', 'parity', 'backfill', 'integrity', 'writers', 'mode', 'shadow'] as $key) {
            $this->assertArrayHasKey($key, $health);
        }
        $this->assertStringNotContainsString($viewer->name, $json);
        $this->assertStringNotContainsString($viewer->email, $json);
    }

    public function test_nonexistent_selected_user_blocks_health_instead_of_reporting_ready(): void
    {
        $health = app(OrganizationCutoverHealthService::class)->check([999999999], true);

        $this->assertSame('BLOCKED', $health['status']);
        $this->assertContains('invalid_user_selection', $health['blockers']);
        $this->assertSame(1, $health['integrity']['missing_users']);
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
