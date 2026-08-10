<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SalesCoordinatorSales;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesCoordinatorTeamAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_edit_lists_only_accessible_active_primary_sales(): void
    {
        $branch = $this->branch('SLO');
        $otherBranch = $this->branch('MGL');
        $actor = $this->actor($branch);
        $coordinator = $this->user('sales_coordinator', $branch);
        $visible = $this->user('sales', $branch, 'Visible Sales');
        $outside = $this->user('sales', $otherBranch, 'Outside Sales');
        $inactive = $this->user('sales', $branch, 'Inactive Sales', AccountStatus::Inactive);
        $supplemental = $this->user('staff', $branch, 'Supplemental Sales');
        $supplemental->roles()->attach(Role::where('slug', 'sales')->firstOrFail());

        $this->actingAs($actor)->get(route('admin-users.edit', $coordinator))
            ->assertOk()
            ->assertSee('name="coordinator_sales_ids[]"', false)
            ->assertSee('name="coordinator_sales_ids[]" value="'.$visible->id.'"', false)
            ->assertDontSee('name="coordinator_sales_ids[]" value="'.$outside->id.'"', false)
            ->assertDontSee('name="coordinator_sales_ids[]" value="'.$inactive->id.'"', false)
            ->assertDontSee('name="coordinator_sales_ids[]" value="'.$supplemental->id.'"', false);
    }

    public function test_update_creates_new_assignment_episode_without_mutating_history(): void
    {
        $branch = $this->branch('SLO');
        $actor = $this->actor($branch);
        $coordinator = $this->user('sales_coordinator', $branch);
        $selected = $this->user('sales', $branch, 'Selected Sales');
        $history = SalesCoordinatorSales::create([
            'coordinator_user_id' => $coordinator->id,
            'sales_user_id' => $selected->id,
            'is_active' => false,
            'started_at' => today()->subMonth(),
            'ended_at' => today()->subWeek(),
        ]);
        $historicalStartedAt = $history->started_at->toDateString();
        $historicalEndedAt = $history->ended_at->toDateString();

        $this->actingAs($actor)->put(route('admin-users.update', $coordinator), $this->payload($coordinator, $branch, [$selected->id]))->assertRedirect(route('admin-users.show', $coordinator));

        $this->assertSame($history->id, $history->fresh()->id);
        $this->assertFalse($history->fresh()->is_active);
        $this->assertSame($historicalStartedAt, $history->fresh()->started_at->toDateString());
        $this->assertSame($historicalEndedAt, $history->fresh()->ended_at->toDateString());
        $current = SalesCoordinatorSales::query()->whereKeyNot($history->id)->sole();
        $this->assertTrue($current->is_active);
        $this->assertSame(today()->toDateString(), $current->started_at->toDateString());
        $this->assertNull($current->ended_at);
        $this->assertSame(2, SalesCoordinatorSales::count());
    }

    public function test_update_ends_current_assignment_without_changing_start_or_deleting_row(): void
    {
        $branch = $this->branch('SLO');
        $actor = $this->actor($branch);
        $coordinator = $this->user('sales_coordinator', $branch);
        $sales = $this->user('sales', $branch);
        $current = SalesCoordinatorSales::create([
            'coordinator_user_id' => $coordinator->id,
            'sales_user_id' => $sales->id,
            'is_active' => true,
            'started_at' => today()->subMonth(),
        ]);
        $startedAt = $current->started_at->toDateString();

        $this->actingAs($actor)->put(route('admin-users.update', $coordinator), $this->payload($coordinator, $branch, []))->assertRedirect(route('admin-users.show', $coordinator));

        $this->assertDatabaseCount('sales_coordinator_sales', 1);
        $this->assertFalse($current->fresh()->is_active);
        $this->assertSame($startedAt, $current->fresh()->started_at->toDateString());
        $this->assertSame(today()->toDateString(), $current->fresh()->ended_at->toDateString());
    }

    public function test_update_rejects_out_of_scope_sales_and_supplemental_coordinator_target(): void
    {
        $branch = $this->branch('SLO');
        $otherBranch = $this->branch('MGL');
        $actor = $this->actor($branch);
        $coordinator = $this->user('sales_coordinator', $branch);
        $outside = $this->user('sales', $otherBranch);

        $this->actingAs($actor)->from(route('admin-users.edit', $coordinator))
            ->put(route('admin-users.update', $coordinator), $this->payload($coordinator, $branch, [$outside->id]))
            ->assertRedirect(route('admin-users.edit', $coordinator))
            ->assertSessionHasErrors('coordinator_sales_ids');

        $staff = $this->user('staff', $branch);
        $staff->roles()->attach(Role::where('slug', 'sales_coordinator')->firstOrFail());
        $sales = $this->user('sales', $branch);
        $this->actingAs($actor)->put(route('admin-users.update', $staff), $this->payload($staff, $branch, [$sales->id]))
            ->assertSessionHasErrors('coordinator_sales_ids');
        $this->assertDatabaseCount('sales_coordinator_sales', 0);
    }

    private function payload(User $user, Branch $branch, array $salesIds): array
    {
        return [
            'expected_updated_at' => $user->updated_at->utc()->format('Y-m-d H:i:s'),
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role_id' => $user->role_id,
            'branch_id' => $branch->id,
            'branch_ids' => [$branch->id],
            'coordinator_sales_ids' => $salesIds,
        ];
    }

    private function actor(Branch $branch): User
    {
        $role = Role::where('slug', 'admin')->firstOrFail();
        $role->permissions()->syncWithoutDetaching(Permission::where('slug', 'users.update')->pluck('id'));

        return User::factory()->create(['role_id' => $role->id, 'branch_id' => $branch->id, 'password_changed_at' => now()]);
    }

    private function user(string $role, Branch $branch, ?string $name = null, AccountStatus $status = AccountStatus::Active): User
    {
        return User::factory()->create([
            'name' => $name ?? fake()->name(),
            'role_id' => Role::where('slug', $role)->value('id'),
            'branch_id' => $branch->id,
            'account_status' => $status,
            'is_active' => $status === AccountStatus::Active,
            'password_changed_at' => now(),
        ]);
    }

    private function branch(string $code): Branch
    {
        return Branch::create(['name' => "Cabang {$code}", 'code' => $code, 'is_active' => true]);
    }
}
