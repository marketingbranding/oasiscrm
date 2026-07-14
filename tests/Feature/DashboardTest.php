<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_branch_admin_dashboard_renders_with_effective_branch_id(): void
    {
        $role = Role::create([
            'name' => 'Admin',
            'slug' => 'admin',
            'is_superadmin' => false,
        ]);
        $branch = Branch::create([
            'name' => 'Test Branch',
            'code' => 'TEST',
            'is_active' => true,
        ]);
        $user = User::factory()->create([
            'role_id' => $role->id,
            'branch_id' => $branch->id,
            'password_changed_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response
            ->assertOk()
            ->assertViewHas('selectedBranchId', $branch->id)
            ->assertSee('name="branch_id" value="'.$branch->id.'"', false);
    }
}
