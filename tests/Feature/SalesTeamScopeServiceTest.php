<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Models\Role;
use App\Models\SalesCoordinatorSales;
use App\Models\User;
use App\Services\SalesTeamScopeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SalesTeamScopeServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_primary_role_strategies_return_expected_hierarchy_and_mapping_edges(): void
    {
        $manager = $this->user('manager');
        $branchManager = $this->user('branch_manager');
        $supervisor = $this->user('supervisor', $manager);
        $coordinator = $this->user('sales_coordinator', $supervisor);
        $directCoordinator = $this->user('sales_coordinator', $manager);
        $branchSupervisor = $this->user('supervisor', $branchManager);
        $branchCoordinator = $this->user('sales_coordinator', $branchSupervisor);
        $salesWithoutHierarchy = $this->user('sales');
        $salesElsewhere = $this->user('sales', $branchSupervisor);
        $directSales = $this->user('sales', $manager);
        $inactiveSales = $this->user('sales', null, false);
        $inactiveCoordinator = $this->user('sales_coordinator', $supervisor, false);
        $irrelevant = $this->user('staff', $manager);
        $coordinatorUnderIrrelevant = $this->user('sales_coordinator', $irrelevant);

        $this->map($coordinator, $salesWithoutHierarchy);
        $this->map($coordinator, $salesElsewhere);
        $this->map($directCoordinator, $salesElsewhere);
        $this->map($branchCoordinator, $directSales);
        $this->map($coordinator, $inactiveSales);
        $this->map($inactiveCoordinator, $directSales);
        $this->map($coordinatorUnderIrrelevant, $directSales);
        $this->map($coordinator, $directSales, ['ended_at' => today()->subDay()]);

        $service = app(SalesTeamScopeService::class);
        $coordinatorResult = $service->for($coordinator);
        $supervisorResult = $service->for($supervisor);
        $managerResult = $service->for($manager);
        $branchResult = $service->for($branchManager);

        $this->assertIds([$coordinator->id], $coordinatorResult['coordinators']);
        $this->assertIds([$salesWithoutHierarchy->id, $salesElsewhere->id], $coordinatorResult['sales']);
        $this->assertIds([$supervisor->id], $supervisorResult['supervisors']);
        $this->assertIds([$coordinator->id], $supervisorResult['coordinators']);
        $this->assertIds([$salesWithoutHierarchy->id, $salesElsewhere->id], $supervisorResult['sales']);
        $this->assertIds([$supervisor->id], $managerResult['supervisors']);
        $this->assertIds([$coordinator->id, $directCoordinator->id], $managerResult['coordinators']);
        $this->assertIds([$salesWithoutHierarchy->id, $salesElsewhere->id], $managerResult['sales']);
        $this->assertEqualsCanonicalizing([$salesWithoutHierarchy->id, $salesElsewhere->id], $managerResult['sales_ids_by_coordinator'][$coordinator->id]->all());
        $this->assertSame([$salesElsewhere->id], $managerResult['sales_ids_by_coordinator'][$directCoordinator->id]->all());
        $this->assertSame([$coordinator->id], $managerResult['coordinator_ids_by_supervisor'][$supervisor->id]->all());
        $this->assertIds([$branchSupervisor->id], $branchResult['supervisors']);
        $this->assertIds([$branchCoordinator->id], $branchResult['coordinators']);
        $this->assertIds([$directSales->id], $branchResult['sales']);
    }

    public function test_sales_and_unsupported_primary_roles_do_not_switch_from_supplemental_roles(): void
    {
        $sales = $this->user('sales');
        $staff = $this->user('staff');
        $staff->roles()->attach(Role::where('slug', 'manager')->firstOrFail());

        $service = app(SalesTeamScopeService::class);

        $this->assertIds([$sales->id], $service->for($sales)['sales']);
        $this->assertTrue($service->for($staff)['sales']->isEmpty());
        $this->assertTrue($service->for($staff)['coordinators']->isEmpty());
    }

    public function test_scope_uses_fixed_query_count(): void
    {
        $manager = $this->user('manager');
        $supervisor = $this->user('supervisor', $manager);
        $coordinator = $this->user('sales_coordinator', $supervisor);
        $this->map($coordinator, $this->user('sales'));
        $manager->load('role');

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(SalesTeamScopeService::class)->for($manager);

        $this->assertLessThanOrEqual(3, count(DB::getQueryLog()));
    }

    private function user(string $role, ?User $supervisor = null, bool $active = true): User
    {
        $user = User::factory()->create([
            'role_id' => Role::where('slug', $role)->firstOrFail()->id,
            'supervisor_user_id' => $supervisor?->id,
        ]);

        if (! $active) {
            DB::table('users')->where('id', $user->id)->update([
                'account_status' => AccountStatus::Inactive->value,
                'is_active' => false,
            ]);
            $user->refresh();
        }

        return $user;
    }

    private function map(User $coordinator, User $sales, array $attributes = []): void
    {
        SalesCoordinatorSales::create($attributes + [
            'coordinator_user_id' => $coordinator->id,
            'sales_user_id' => $sales->id,
        ]);
    }

    private function assertIds(array $expected, $users): void
    {
        $this->assertEqualsCanonicalizing($expected, $users->pluck('id')->all());
    }
}
