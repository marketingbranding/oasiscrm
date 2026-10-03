<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Models\Branch;
use App\Models\OrganizationAssignment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\OrganizationGraphService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationWorkspaceAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_organization_workspace_is_permission_gated_and_renders_for_authorized_user(): void
    {
        $branch = Branch::create(['name' => 'SLO', 'code' => 'SLO', 'is_active' => true]);
        $denied = $this->user('sales', $branch);
        $allowed = $this->user('superadmin', $branch);

        $this->actingAs($denied)->get(route('organization.index'))->assertForbidden();
        $this->actingAs($allowed)->get(route('organization.index'))->assertOk()->assertSeeText('Struktur Organisasi');
    }

    public function test_stale_organization_move_returns_conflict(): void
    {
        $branch = Branch::create(['name' => 'MGL', 'code' => 'MGL', 'is_active' => true]);
        $actor = $this->user('superadmin', $branch);
        $first = $this->user('sales_coordinator', $branch);
        $second = $this->user('sales_coordinator', $branch);
        $sales = $this->user('sales', $branch);
        $assignment = app(OrganizationGraphService::class)->assign($sales, $first);

        app(OrganizationGraphService::class)->move($actor, $sales, $second, expectedAssignmentId: $assignment->id, expectedVersion: 1);

        $this->actingAs($actor)->patchJson(route('organization.move', $sales), [
            'parent_user_id' => $first->id,
            'expected_assignment_id' => $assignment->id,
            'expected_version' => 1,
        ])->assertConflict()->assertJsonPath('message', 'Struktur organisasi telah berubah. Muat ulang lalu coba lagi.');

        $this->assertSame(1, OrganizationAssignment::query()->current()->where('user_id', $sales->id)->count());
        $this->assertSame(2, OrganizationAssignment::query()->where('user_id', $sales->id)->count());
    }

    public function test_organization_move_backend_rejects_unauthorized_json_and_invalid_scope_or_graph_targets(): void
    {
        $branch = Branch::create(['name' => 'SEC', 'code' => 'SEC', 'is_active' => true]);
        $otherBranch = Branch::create(['name' => 'OUT', 'code' => 'OUT', 'is_active' => true]);
        $denied = $this->user('sales', $branch);
        $actor = $this->user('superadmin', $branch);
        $target = $this->user('sales', $branch);
        $validParent = $this->user('sales_coordinator', $branch);
        $foreignParent = $this->user('sales_coordinator', $otherBranch);
        $invalidParent = $this->user('sales', $branch);

        $this->actingAs($denied)->patchJson(route('organization.move', $target), [
            'parent_user_id' => $validParent->id,
        ])->assertForbidden();

        $this->actingAs($actor)->patchJson(route('organization.move', $target), [
            'parent_user_id' => $foreignParent->id,
        ])->assertStatus(422);

        $this->actingAs($actor)->patchJson(route('organization.move', $target), [
            'parent_user_id' => $invalidParent->id,
        ])->assertStatus(422);

        $this->actingAs($actor)->patchJson(route('organization.move', $target), [
            'parent_user_id' => $target->id,
        ])->assertStatus(422);

        $first = $this->user('supervisor', $branch);
        $second = $this->user('supervisor', $branch);
        app(OrganizationGraphService::class)->assign($second, $first);
        $this->actingAs($actor)->patchJson(route('organization.move', $first), [
            'parent_user_id' => $second->id,
        ])->assertStatus(422);

        $inactiveParent = $this->user('supervisor', $branch);
        $inactiveParent->forceFill(['is_active' => false, 'account_status' => AccountStatus::Inactive])->saveQuietly();
        $this->actingAs($actor)->patchJson(route('organization.move', $target), [
            'parent_user_id' => $inactiveParent->id,
        ])->assertStatus(422);
    }

    public function test_role_administration_is_superadmin_gated_and_accepts_registered_permissions_only(): void
    {
        $branch = Branch::create(['name' => 'BDG', 'code' => 'BDG', 'is_active' => true]);
        $denied = $this->user('sales', $branch);
        $admin = $this->user('superadmin', $branch);

        $this->actingAs($denied)->get(route('roles.index'))->assertForbidden();
        $this->actingAs($admin)->get(route('roles.index'))->assertOk()->assertSeeText('Role reporting rules');
        $this->actingAs($admin)->post(route('roles.store'), [
            'name' => 'Reviewer Operasional',
            'slug' => 'reviewer_operasional',
            'authority_level' => 15,
            'description' => 'Role custom untuk review.',
        ])->assertRedirect();

        $role = Role::query()->where('slug', 'reviewer_operasional')->firstOrFail();
        $permission = Permission::query()->where('slug', 'organization.view')->firstOrFail();
        $this->actingAs($admin)->put(route('roles.permissions.update', $role), [
            'permission_ids' => [$permission->id],
        ])->assertRedirect();
        $this->assertTrue($role->fresh()->permissions()->whereKey($permission)->exists());
    }

    private function user(string $role, Branch $branch): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $role)->value('id'),
            'branch_id' => $branch->id,
            'is_active' => true,
            'account_status' => AccountStatus::Active,
            'email_verified_at' => now(),
            'password_changed_at' => now(),
        ]);
    }
}
