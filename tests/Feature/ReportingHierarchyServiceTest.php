<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Services\ReportingHierarchyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ReportingHierarchyServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_supervisor_assignment_and_descendants_are_iterative(): void
    {
        $branch = $this->branch();
        $manager = $this->user('manager', $branch);
        $supervisor = $this->user('supervisor', $branch);
        $sales = $this->user('sales', $branch);
        $service = app(ReportingHierarchyService::class);

        $service->assignSupervisor($supervisor, $manager);
        $service->assignSupervisor($sales, $supervisor);

        $this->assertTrue($manager->directReports()->firstOrFail()->is($supervisor));
        $this->assertEqualsCanonicalizing([$supervisor->id, $sales->id], $service->descendantIds($manager));
        $this->assertDatabaseHas('activity_log', ['subject_id' => $sales->id, 'event' => 'supervisor_assignment_changed']);
    }

    public function test_supervisor_cannot_be_self_inactive_or_lower_ranked(): void
    {
        $branch = $this->branch();
        $manager = $this->user('manager', $branch);
        $sales = $this->user('sales', $branch);
        $inactive = $this->user('branch_manager', $branch, false);
        $service = app(ReportingHierarchyService::class);

        foreach ([$manager, $inactive, $sales] as $candidate) {
            try {
                $service->assignSupervisor($manager, $candidate);
                $this->fail('Invalid supervisor should fail.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('supervisor_user_id', $exception->errors());
            }
        }
    }

    public function test_supervisor_must_share_authorization_and_indirect_cycles_are_rejected(): void
    {
        $firstBranch = $this->branch('SLO');
        $otherBranch = $this->branch('MGL');
        $first = $this->user('supervisor', $firstBranch);
        $second = $this->user('supervisor', $firstBranch);
        $third = $this->user('supervisor', $firstBranch);
        $unrelated = $this->user('manager', $otherBranch);
        $service = app(ReportingHierarchyService::class);

        try {
            $service->assignSupervisor($first, $unrelated);
            $this->fail('Unrelated supervisor should fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('supervisor_user_id', $exception->errors());
        }

        $service->assignSupervisor($first, $second);
        $service->assignSupervisor($second, $third);
        $this->expectException(ValidationException::class);
        $service->assignSupervisor($third, $first);
    }

    private function branch(string $code = 'SLO'): Branch
    {
        return Branch::create(['name' => $code, 'code' => $code, 'is_active' => true]);
    }

    private function user(string $role, Branch $branch, bool $active = true): User
    {
        return User::factory()->create([
            'role_id' => Role::where('slug', $role)->firstOrFail()->id,
            'branch_id' => $branch->id,
            'is_active' => $active,
            'account_status' => $active ? AccountStatus::Active : AccountStatus::Inactive,
        ]);
    }
}
