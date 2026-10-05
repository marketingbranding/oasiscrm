<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\User;
use App\Services\WorkspaceAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesProjectAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_role_exists_after_migration_with_canonical_values(): void
    {
        $this->assertDatabaseHas('roles', [
            'slug' => 'sales',
            'name' => 'Sales',
            'description' => 'Tim penjualan dengan akses ke data sendiri.',
            'is_superadmin' => false,
        ]);
    }

    public function test_superadmin_can_create_sales_user_with_assigned_and_primary_project(): void
    {
        [$admin, $salesRole, $branch, $project, $contextCoordinator] = $this->assignmentContext();
        $secondary = $this->project($branch, 'Oasis Dua');

        $this->actingAs($admin)->post(route('admin-users.store'), $this->salesPayload($salesRole, $branch, [
            $project->id,
            $secondary->id,
        ], $secondary->id, $contextCoordinator->id))->assertRedirect();

        $sales = User::where('email', 'sales@example.com')->firstOrFail();
        $this->assertEqualsCanonicalizing([$project->id, $secondary->id], $sales->assignedProjects->pluck('id')->all());
        $this->assertTrue($sales->primaryAssignedProject()->first()->is($secondary));
        $this->assertTrue(app(WorkspaceAccessService::class)->canAccessProject($sales, $project));
        $this->assertEqualsCanonicalizing([$project->id, $secondary->id], app(WorkspaceAccessService::class)->accessibleProjectIds($sales));
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => User::class,
            'subject_id' => $sales->id,
            'event' => 'user_created',
        ]);
    }

    public function test_sales_creation_creates_authoritative_coordinator_mapping(): void
    {
        [$admin, $salesRole, $branch, $project] = $this->assignmentContext();
        $coordinatorRole = Role::where('slug', 'sales_coordinator')->firstOrFail();
        $coordinator = User::factory()->create([
            'role_id' => $coordinatorRole->id,
            'branch_id' => $branch->id,
            'account_status' => 'active',
            'is_active' => true,
            'password_changed_at' => now(),
        ]);

        $this->actingAs($admin)->post(route('admin-users.store'), $this->salesPayload($salesRole, $branch, [$project->id], null, $coordinator->id))->assertRedirect()->assertSessionHasNoErrors();

        $sales = User::where('email', 'sales@example.com')->firstOrFail();
        $this->assertDatabaseHas('sales_coordinator_sales', [
            'coordinator_user_id' => $coordinator->id,
            'sales_user_id' => $sales->id,
            'is_active' => true,
        ]);
    }

    public function test_sales_creation_rejects_coordinator_from_another_branch(): void
    {
        [$admin, $salesRole, $branch, $project] = $this->assignmentContext();
        $otherBranch = Branch::create(['name' => 'Magelang', 'code' => 'MGL', 'is_active' => true]);
        $coordinatorRole = Role::where('slug', 'sales_coordinator')->firstOrFail();
        $coordinator = User::factory()->create([
            'role_id' => $coordinatorRole->id,
            'branch_id' => $otherBranch->id,
            'account_status' => 'active',
            'is_active' => true,
            'password_changed_at' => now(),
        ]);

        $this->actingAs($admin)
            ->from(route('admin-users.create'))
            ->post(route('admin-users.store'), $this->salesPayload($salesRole, $branch, [$project->id], null, $coordinator->id))
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'sales@example.com']);
    }

    public function test_superadmin_creation_can_omit_organizational_placement(): void
    {
        [$admin] = $this->assignmentContext();
        $superadminRole = Role::where('slug', 'superadmin')->firstOrFail();

        $this->actingAs($admin)->post(route('admin-users.store'), [
            'name' => 'Second Superadmin',
            'email' => 'second-superadmin@example.com',
            'role_id' => $superadminRole->id,
            'submit_action' => 'draft',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $newUser = User::where('email', 'second-superadmin@example.com')->firstOrFail();
        $this->assertNull($newUser->branch_id);
        $this->assertCount(0, $newUser->assignedProjects);
    }

    public function test_sales_user_without_project_is_rejected_by_assignment_workflow(): void
    {
        [$admin, $salesRole, $branch] = $this->assignmentContext();

        $this->actingAs($admin)
            ->from(route('admin-users.create'))
            ->post(route('admin-users.store'), $this->salesPayload($salesRole, $branch, []))
            ->assertSessionHasErrors('assigned_project_ids');

        $this->assertDatabaseMissing('users', ['email' => 'sales@example.com']);
    }

    public function test_only_one_primary_is_kept_by_application_assignment_model(): void
    {
        [, $salesRole, $branch, $project] = $this->assignmentContext();
        $secondary = $this->project($branch, 'Oasis Dua');
        $sales = User::factory()->create(['role_id' => $salesRole->id, 'branch_id' => $branch->id]);

        $sales->assignedProjects()->attach($project->id, ['is_primary' => true]);
        $sales->assignedProjects()->attach($secondary->id, ['is_primary' => true]);

        $this->assertSame(1, $sales->assignedProjects()->wherePivot('is_primary', true)->count());
        $this->assertTrue($sales->primaryAssignedProject()->first()->is($secondary));
    }

    public function test_assignment_does_not_bypass_revoked_branch_access(): void
    {
        [, $salesRole, $branch, $project] = $this->assignmentContext();
        $sales = User::factory()->create(['role_id' => $salesRole->id, 'branch_id' => $branch->id]);
        $sales->assignedProjects()->attach($project->id, ['is_primary' => true]);
        $sales->branches()->updateExistingPivot($branch->id, ['can_view' => false]);

        $this->assertFalse(app(WorkspaceAccessService::class)->canAccessProject($sales, $project));
    }

    public function test_inactive_project_assignment_is_rejected(): void
    {
        [$admin, $salesRole, $branch, $project] = $this->assignmentContext();
        $project->update(['is_active' => false]);

        $this->actingAs($admin)
            ->post(route('admin-users.store'), $this->salesPayload($salesRole, $branch, [$project->id]))
            ->assertSessionHasErrors('assigned_project_ids.0');
    }

    public function test_project_branch_must_be_accessible_to_assigned_user(): void
    {
        [$admin, $salesRole, $branch] = $this->assignmentContext();
        $otherBranch = Branch::create(['name' => 'Magelang', 'code' => 'MGL', 'is_active' => true]);
        $otherProject = $this->project($otherBranch, 'Oasis Magelang');

        $this->actingAs($admin)
            ->post(route('admin-users.store'), $this->salesPayload($salesRole, $branch, [$otherProject->id]))
            ->assertSessionHasErrors('assigned_project_ids');
    }

    public function test_non_sales_user_can_receive_project_assignments(): void
    {
        [$admin, , $branch, $project] = $this->assignmentContext();
        $adminRole = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin', 'is_superadmin' => false]);
        $payload = $this->salesPayload($adminRole, $branch, [$project->id]);

        $this->actingAs($admin)->post(route('admin-users.store'), $payload)
            ->assertRedirect();

        $user = User::where('email', 'sales@example.com')->firstOrFail();
        $this->assertTrue($user->assignedProjects->contains($project));
    }

    public function test_superadmin_create_and_edit_ui_show_sales_projects_grouped_by_branch(): void
    {
        [$admin, $salesRole, $branch, $project] = $this->assignmentContext();
        $sales = User::factory()->create([
            'role_id' => $salesRole->id,
            'branch_id' => $branch->id,
            'password_changed_at' => now(),
        ]);
        $sales->assignedProjects()->attach($project->id, ['is_primary' => true]);

        $this->actingAs($admin)->get(route('admin-users.create'))
            ->assertOk()
            ->assertSee('Proyek Tambahan')
            ->assertSee($branch->name)
            ->assertSee($project->project_name);

        $this->actingAs($admin)->get(route('admin-users.edit', $sales))
            ->assertOk()
            ->assertSee($project->project_name)
            ->assertSee('value="'.$project->id.'"', false);
    }

    private function assignmentContext(): array
    {
        $superadminRole = Role::firstOrCreate(
            ['slug' => 'superadmin'],
            ['name' => 'Super Admin', 'description' => null, 'is_superadmin' => true],
        );
        $salesRole = Role::where('slug', 'sales')->firstOrFail();
        $admin = User::factory()->create([
            'role_id' => $superadminRole->id,
            'branch_id' => null,
            'password_changed_at' => now(),
        ]);
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);

        $coordinatorRole = Role::where('slug', 'sales_coordinator')->firstOrFail();
        $coordinator = User::factory()->create([
            'role_id' => $coordinatorRole->id,
            'branch_id' => $branch->id,
            'account_status' => 'active',
            'is_active' => true,
            'password_changed_at' => now(),
        ]);

        return [$admin, $salesRole, $branch, $this->project($branch, 'Oasis Solo'), $coordinator];
    }

    private function project(Branch $branch, string $name): LeadMaster
    {
        return LeadMaster::create([
            'branch_id' => $branch->id,
            'project_name' => $name,
            'is_active' => true,
        ]);
    }

    private function salesPayload(Role $role, Branch $branch, array $projectIds, ?int $primaryProjectId = null, ?int $coordinatorId = null): array
    {
        $payload = [
            'name' => 'Sales User',
            'email' => 'sales@example.com',
            'role_id' => $role->id,
            'branch_id' => $branch->id,
            'branch_ids' => [$branch->id],
            'assigned_project_ids' => $projectIds,
            'primary_project_id' => $primaryProjectId,
            'temporary_password' => 'Initial123',
            'temporary_password_confirmation' => 'Initial123',
        ];

        if ($role->slug === 'sales') {
            $payload['coordinator_user_id'] = $coordinatorId ?? User::query()
                ->where('branch_id', $branch->id)
                ->whereHas('role', fn ($query) => $query->where('slug', 'sales_coordinator'))
                ->value('id');
        }

        return $payload;
    }
}
