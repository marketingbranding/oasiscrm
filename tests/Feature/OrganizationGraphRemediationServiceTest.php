<?php

namespace Tests\Feature;

use App\Exceptions\OrganizationAssignmentConflictException;
use App\Models\Branch;
use App\Models\OrganizationAssignment;
use App\Models\Role;
use App\Models\User;
use App\Services\OrganizationGraphRemediationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OrganizationGraphRemediationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_high_confidence_missing_assignment_is_applied_and_audited(): void
    {
        $branch = $this->branch('RMD');
        $parent = $this->user('supervisor', $branch);
        $child = $this->user('staff', $branch);
        $child->forceFill(['supervisor_user_id' => $parent->id])->saveQuietly();

        $assignment = app(OrganizationGraphRemediationService::class)->applyApproved([
            'user_id' => $child->id,
            'parent_user_id' => $parent->id,
            'classification' => 'MISSING_GRAPH_ASSIGNMENT',
            'confidence' => 'HIGH',
            'expected_legacy_parent_user_id' => $parent->id,
            'expected_graph_parent_user_id' => null,
            'reason' => 'Bukti scope legacy dan validasi workspace menyetujui relasi ini.',
        ]);

        $this->assertNotNull($assignment);
        $this->assertSame($parent->id, $assignment->parent_user_id);
        $this->assertSame('phase_2_5_remediation', $assignment->source);
        $this->assertSame($parent->id, $child->fresh()->supervisor_user_id);
        $this->assertDatabaseHas('activity_log', [
            'subject_id' => $child->id,
            'event' => 'organization_assignment_changed',
        ]);
    }

    public function test_repeating_the_same_approved_repair_is_idempotent(): void
    {
        $branch = $this->branch('RMI');
        $parent = $this->user('supervisor', $branch);
        $child = $this->user('staff', $branch);
        $child->forceFill(['supervisor_user_id' => $parent->id])->saveQuietly();
        $approval = [
            'user_id' => $child->id,
            'parent_user_id' => $parent->id,
            'classification' => 'MISSING_GRAPH_ASSIGNMENT',
            'confidence' => 'HIGH',
            'expected_legacy_parent_user_id' => $parent->id,
            'expected_graph_parent_user_id' => null,
            'reason' => 'Perbaikan Phase 2.5 yang dapat diulang.',
        ];

        $first = app(OrganizationGraphRemediationService::class)->applyApproved($approval);
        $second = app(OrganizationGraphRemediationService::class)->applyApproved([
            ...$approval,
            'expected_graph_parent_user_id' => $parent->id,
        ]);

        $this->assertSame($first?->id, $second?->id);
        $this->assertSame(1, OrganizationAssignment::query()->where('user_id', $child->id)->current()->count());
    }

    public function test_stale_expected_state_is_rejected_without_mutation(): void
    {
        $branch = $this->branch('RMS');
        $parent = $this->user('supervisor', $branch);
        $otherParent = $this->user('supervisor', $branch);
        $child = $this->user('staff', $branch);
        $child->forceFill(['supervisor_user_id' => $otherParent->id])->saveQuietly();

        $this->expectException(OrganizationAssignmentConflictException::class);
        app(OrganizationGraphRemediationService::class)->applyApproved([
            'user_id' => $child->id,
            'parent_user_id' => $parent->id,
            'classification' => 'MISSING_GRAPH_ASSIGNMENT',
            'confidence' => 'HIGH',
            'expected_legacy_parent_user_id' => $parent->id,
            'expected_graph_parent_user_id' => null,
            'reason' => 'State lama tidak lagi cocok.',
        ]);
    }

    public function test_low_confidence_or_conflicting_relationship_is_rejected(): void
    {
        $branch = $this->branch('RMC');
        $parent = $this->user('supervisor', $branch);
        $child = $this->user('staff', $branch);
        $child->forceFill(['supervisor_user_id' => $parent->id])->saveQuietly();

        try {
            app(OrganizationGraphRemediationService::class)->applyApproved([
                'user_id' => $child->id,
                'parent_user_id' => $parent->id,
                'classification' => 'MISSING_GRAPH_ASSIGNMENT',
                'confidence' => 'MEDIUM',
                'expected_legacy_parent_user_id' => $parent->id,
                'expected_graph_parent_user_id' => null,
                'reason' => 'Confidence tidak cukup.',
            ]);
            $this->fail('Low-confidence repairs must be rejected.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_role_workspace_and_cycle_validation_remain_enforced(): void
    {
        $branch = $this->branch('RMV');
        $parent = $this->user('staff', $branch);
        $child = $this->user('supervisor', $branch);
        $child->forceFill(['supervisor_user_id' => $parent->id])->saveQuietly();

        $this->expectException(ValidationException::class);
        app(OrganizationGraphRemediationService::class)->applyApproved([
            'user_id' => $child->id,
            'parent_user_id' => $parent->id,
            'classification' => 'MISSING_GRAPH_ASSIGNMENT',
            'confidence' => 'HIGH',
            'expected_legacy_parent_user_id' => $parent->id,
            'expected_graph_parent_user_id' => null,
            'reason' => 'Role parent tidak valid.',
        ]);
    }

    public function test_reporting_remediation_does_not_collapse_an_independent_sales_team_mapping(): void
    {
        $branch = $this->branch('RMW');
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

        $assignment = app(OrganizationGraphRemediationService::class)->applyApproved([
            'user_id' => $sales->id,
            'parent_user_id' => $supervisor->id,
            'classification' => 'MISSING_GRAPH_ASSIGNMENT',
            'confidence' => 'HIGH',
            'expected_legacy_parent_user_id' => $supervisor->id,
            'expected_graph_parent_user_id' => null,
            'reason' => 'Reporting dan Sales-team adalah relasi terpisah.',
        ]);

        $this->assertSame($supervisor->id, $assignment?->parent_user_id);
        $this->assertDatabaseHas('sales_coordinator_sales', [
            'coordinator_user_id' => $coordinator->id,
            'sales_user_id' => $sales->id,
            'is_active' => true,
        ]);
    }

    public function test_sales_team_remediation_uses_sales_mapping_and_keeps_reporting_parent_untouched(): void
    {
        $branch = $this->branch('RMS');
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

        $assignment = app(OrganizationGraphRemediationService::class)->applyApproved([
            'user_id' => $sales->id,
            'parent_user_id' => $coordinator->id,
            'relationship_type' => OrganizationAssignment::SALES_TEAM_MEMBER_OF,
            'classification' => 'MISSING_GRAPH_ASSIGNMENT',
            'confidence' => 'HIGH',
            'expected_legacy_parent_user_id' => $coordinator->id,
            'expected_graph_parent_user_id' => null,
            'reason' => 'Mapping Sales Coordinator legacy tervalidasi.',
        ]);

        $this->assertSame(OrganizationAssignment::SALES_TEAM_MEMBER_OF, $assignment?->relationship_type);
        $this->assertSame($supervisor->id, $sales->fresh()->supervisor_user_id);
        $this->assertSame($coordinator->id, $assignment?->parent_user_id);
    }

    public function test_sales_team_remediation_rejects_ambiguous_current_typed_edges(): void
    {
        $branch = $this->branch('RMA');
        $firstCoordinator = $this->user('sales_coordinator', $branch);
        $secondCoordinator = $this->user('sales_coordinator', $branch);
        $sales = $this->user('sales', $branch);
        foreach ([$firstCoordinator, $secondCoordinator] as $coordinator) {
            OrganizationAssignment::query()->create([
                'user_id' => $sales->id,
                'parent_user_id' => $coordinator->id,
                'relationship_type' => OrganizationAssignment::SALES_TEAM_MEMBER_OF,
                'branch_id' => $branch->id,
                'started_at' => today()->toDateString(),
                'is_active' => true,
                'is_primary' => true,
                'source' => 'ambiguous_fixture',
                'lock_version' => 1,
            ]);
        }

        $this->expectException(ValidationException::class);
        app(OrganizationGraphRemediationService::class)->applyApproved([
            'user_id' => $sales->id,
            'parent_user_id' => $firstCoordinator->id,
            'relationship_type' => OrganizationAssignment::SALES_TEAM_MEMBER_OF,
            'classification' => 'MISSING_GRAPH_ASSIGNMENT',
            'confidence' => 'HIGH',
            'expected_legacy_parent_user_id' => $firstCoordinator->id,
            'expected_graph_parent_user_id' => null,
            'reason' => 'Ambiguitas harus ditangani secara eksplisit.',
        ]);
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
