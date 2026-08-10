<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\SalesCoordinatorSales;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesCoordinatorSalesTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_scope_and_user_relationships_use_inclusive_date_window(): void
    {
        $coordinator = $this->user('sales_coordinator');
        $currentSales = $this->user('sales');
        $futureSales = $this->user('sales');
        $expiredSales = $this->user('sales');

        $current = SalesCoordinatorSales::create([
            'coordinator_user_id' => $coordinator->id,
            'sales_user_id' => $currentSales->id,
            'started_at' => today(),
            'ended_at' => today(),
        ]);
        SalesCoordinatorSales::create([
            'coordinator_user_id' => $coordinator->id,
            'sales_user_id' => $futureSales->id,
            'started_at' => today()->addDay(),
        ]);
        SalesCoordinatorSales::create([
            'coordinator_user_id' => $coordinator->id,
            'sales_user_id' => $expiredSales->id,
            'ended_at' => today()->subDay(),
        ]);

        $current->refresh();

        $this->assertTrue($current->is_active);
        $this->assertSame(today()->toDateString(), $current->started_at->toDateString());
        $this->assertSame([$current->id], SalesCoordinatorSales::current()->pluck('id')->all());
        $this->assertTrue($coordinator->currentCoordinatorSales()->firstOrFail()->is($currentSales));
        $this->assertTrue($currentSales->currentSalesCoordinators()->firstOrFail()->is($coordinator));
    }

    public function test_history_allows_repeated_pair_and_role_scope_rejects_invalid_primary_roles(): void
    {
        $coordinator = $this->user('sales_coordinator');
        $sales = $this->user('sales');

        SalesCoordinatorSales::create([
            'coordinator_user_id' => $coordinator->id,
            'sales_user_id' => $sales->id,
            'is_active' => false,
            'ended_at' => today()->subYear(),
        ]);
        SalesCoordinatorSales::create([
            'coordinator_user_id' => $coordinator->id,
            'sales_user_id' => $sales->id,
        ]);
        SalesCoordinatorSales::create([
            'coordinator_user_id' => $sales->id,
            'sales_user_id' => $coordinator->id,
        ]);

        $this->assertSame(3, SalesCoordinatorSales::count());
        $this->assertSame(1, SalesCoordinatorSales::current()->withValidRoles()->count());
    }

    private function user(string $roleSlug): User
    {
        $role = Role::where('slug', $roleSlug)->firstOrFail();

        return User::factory()->create(['role_id' => $role->id]);
    }
}
