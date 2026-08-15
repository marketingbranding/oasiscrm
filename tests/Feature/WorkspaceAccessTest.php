<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Services\OptimisticLockService;
use App\Services\WorkspaceAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WorkspaceAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeGoogleSheets();
    }

    public function test_user_can_have_multiple_unique_branch_memberships(): void
    {
        [$user, $primary, $secondary] = $this->branchUser();

        $user->branches()->syncWithoutDetaching([$secondary->id => ['can_view' => true]]);
        $user->branches()->syncWithoutDetaching([$secondary->id => ['can_view' => true]]);

        $this->assertCount(2, $user->fresh()->branches);
        $this->assertSame(1, DB::table('branch_user')->where('user_id', $user->id)->where('branch_id', $secondary->id)->count());
        $this->assertDatabaseHas('branch_user', ['user_id' => $user->id, 'branch_id' => $primary->id]);
    }

    public function test_workspace_access_respects_membership_permissions_and_legacy_primary_fallback(): void
    {
        [$user, $primary, $secondary] = $this->branchUser();
        $access = app(WorkspaceAccessService::class);
        $user->branches()->syncWithoutDetaching([$secondary->id => [
            'can_view' => true,
            'can_edit' => false,
            'can_sync' => true,
            'can_manage_members' => false,
        ]]);

        $this->assertTrue($access->canViewBranch($user, $primary));
        $this->assertTrue($access->canViewBranch($user, $secondary));
        $this->assertFalse($access->canEditBranch($user, $secondary));
        $this->assertTrue($access->canSyncBranch($user, $secondary));
        $this->assertEqualsCanonicalizing([$primary->id, $secondary->id], $access->accessibleBranchIds($user));

        DB::table('branch_user')->where('user_id', $user->id)->delete();
        $user->unsetRelation('branches');
        $this->assertTrue($access->canViewBranch($user, $primary));
        $this->assertTrue($access->canEditBranch($user, $primary));
        $this->assertFalse($access->canViewBranch($user, $secondary));
    }

    public function test_superadmin_and_pusat_keep_global_compatibility_access_to_active_branches(): void
    {
        $active = Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);
        $inactive = Branch::create(['name' => 'Lama', 'code' => 'OLD', 'is_active' => false]);
        $superadmin = $this->userWithRole('superadmin', true);
        $pusat = $this->userWithRole('pusat');
        $access = app(WorkspaceAccessService::class);

        foreach ([$superadmin, $pusat] as $user) {
            $this->assertTrue($access->canViewBranch($user, $active));
            $this->assertTrue($access->canEditBranch($user, $active));
            $this->assertTrue($access->canSyncBranch($user, $active));
            $this->assertFalse($access->canViewBranch($user, $inactive));
        }
    }

    public function test_admin_can_create_user_with_primary_and_multiple_memberships(): void
    {
        $admin = $this->userWithRole('superadmin', true);
        $role = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin', 'is_superadmin' => false]);
        $pusat = Branch::create(['name' => 'Kantor Pusat', 'code' => 'PST', 'is_active' => true]);
        $magelang = Branch::create(['name' => 'Magelang', 'code' => 'MGL', 'is_active' => true]);

        $this->actingAs($admin)->post(route('admin-users.store'), [
            'name' => 'Robby',
            'email' => 'robby@example.com',
            'role_id' => $role->id,
            'branch_id' => $pusat->id,
            'branch_ids' => [$magelang->id],
            'membership_permissions' => [
                $pusat->id => ['can_edit' => 1, 'can_sync' => 1],
                $magelang->id => ['can_sync' => 1],
            ],
            'submit_action' => 'draft',
        ])->assertRedirect();

        $robby = User::where('email', 'robby@example.com')->firstOrFail();
        $this->assertSame($pusat->id, $robby->branch_id);
        $this->assertEqualsCanonicalizing([$pusat->id, $magelang->id], $robby->branches->pluck('id')->all());
        $this->assertTrue((bool) $robby->branches->firstWhere('id', $magelang->id)->pivot->can_view);
    }

    public function test_admin_update_can_explicitly_change_memberships_without_deleting_user(): void
    {
        $admin = $this->userWithRole('superadmin', true);
        [$user, $primary, $secondary] = $this->branchUser();
        $third = Branch::create(['name' => 'Jepara', 'code' => 'JPR', 'is_active' => true]);
        $user->branches()->syncWithoutDetaching([$secondary->id => ['can_view' => true]]);

        $this->actingAs($admin)->put(route('admin-users.update', $user), [
            'name' => $user->name,
            'email' => $user->email,
            'role_id' => $user->role_id,
            'branch_id' => $primary->id,
            'branch_ids' => [$primary->id, $third->id],
            'membership_permissions' => [$third->id => ['can_edit' => 1]],
            'is_active' => 1,
            'expected_updated_at' => app(OptimisticLockService::class)->token($user),
        ])->assertRedirect();

        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertEqualsCanonicalizing([$primary->id, $third->id], $user->fresh()->branches->pluck('id')->all());
        $this->assertDatabaseMissing('branch_user', ['user_id' => $user->id, 'branch_id' => $secondary->id]);
    }

    public function test_inactive_branch_cannot_be_assigned_as_primary_or_membership(): void
    {
        $admin = $this->userWithRole('superadmin', true);
        $role = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin', 'is_superadmin' => false]);
        $inactive = Branch::create(['name' => 'Nonaktif', 'code' => 'OFF', 'is_active' => false]);

        $this->actingAs($admin)->from(route('admin-users.create'))->post(route('admin-users.store'), [
            'name' => 'Invalid User',
            'email' => 'invalid@example.com',
            'role_id' => $role->id,
            'branch_id' => $inactive->id,
            'branch_ids' => [$inactive->id],
            'submit_action' => 'draft',
        ])->assertSessionHasErrors(['branch_id', 'branch_ids.0']);

        $this->assertDatabaseMissing('users', ['email' => 'invalid@example.com']);
    }

    public function test_removing_secondary_membership_does_not_delete_user_and_primary_removal_is_blocked(): void
    {
        $admin = $this->userWithRole('superadmin', true);
        [$user, $primary, $secondary] = $this->branchUser();
        $user->branches()->syncWithoutDetaching([$secondary->id => ['can_view' => true]]);

        $this->actingAs($admin)->delete(route('branches.remove-admin', $user), ['branch_id' => $secondary->id])
            ->assertSessionHas('success');
        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('branch_user', ['user_id' => $user->id, 'branch_id' => $secondary->id]);

        $this->actingAs($admin)->delete(route('branches.remove-admin', $user), ['branch_id' => $primary->id])
            ->assertSessionHas('error');
        $this->assertDatabaseHas('branch_user', ['user_id' => $user->id, 'branch_id' => $primary->id]);
    }

    public function test_multi_branch_user_can_open_authorized_database_branch_but_not_other_branch(): void
    {
        [$user, , $secondary] = $this->branchUser();
        $unauthorized = Branch::create(['name' => 'Pati', 'code' => 'PTI', 'is_active' => true]);
        $user->branches()->syncWithoutDetaching([$secondary->id => ['can_view' => true]]);

        $this->actingAs($user)->get(route('database.index', ['branch_id' => $secondary->id]))->assertOk();
        $this->actingAs($user)->get(route('database.index', ['branch_id' => $unauthorized->id]))->assertForbidden();
        $this->actingAs($user)->get(route('konsumen-progress.index', ['branch_id' => $unauthorized->id]))->assertForbidden();
        $this->actingAs($user)->postJson(route('database.sync'), ['branch_id' => $unauthorized->id])->assertForbidden();
        $this->actingAs($user)->postJson(route('konsumen-progress.sync'), ['branch_id' => $unauthorized->id])->assertForbidden();
    }

    public function test_ai_uses_membership_branch_and_sync_permission(): void
    {
        [$user, , $secondary] = $this->branchUser();
        $unauthorized = Branch::create(['name' => 'Pati', 'code' => 'PTI', 'is_active' => true]);
        $user->branches()->syncWithoutDetaching([$secondary->id => ['can_view' => true, 'can_sync' => false]]);
        config(['ai.routing_mode' => 'hybrid', 'ai.synthesize_tool_results' => false]);
        Http::fake();

        $this->actingAs($user)->postJson(route('ai-chat.chat'), [
            'message' => 'jumlah akad cabang Magelang',
        ])->assertOk()
            ->assertSeeText('untuk Magelang')
            ->assertJsonPath('message.actions', []);

        $user->branches()->updateExistingPivot($secondary->id, ['can_sync' => true]);
        $this->actingAs($user)->postJson(route('ai-chat.chat'), [
            'message' => 'jumlah akad cabang Magelang',
        ])->assertOk()
            ->assertJsonPath('message.actions.0.payload.branch_id', $secondary->id);

        $this->actingAs($user)->postJson(route('ai-chat.chat'), [
            'message' => 'jumlah akad cabang Pati',
        ])->assertOk()->assertSeeText('Anda tidak memiliki akses ke cabang Pati.');
        Http::assertNothingSent();
    }

    private function branchUser(): array
    {
        $role = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin', 'is_superadmin' => false]);
        $primary = Branch::create(['name' => 'Kantor Pusat', 'code' => 'PST', 'is_active' => true]);
        $secondary = Branch::create(['name' => 'Magelang', 'code' => 'MGL', 'is_active' => true]);
        $user = User::factory()->create([
            'role_id' => $role->id,
            'branch_id' => $primary->id,
            'password_changed_at' => now(),
        ]);

        return [$user, $primary, $secondary];
    }

    private function userWithRole(string $slug, bool $superadmin = false): User
    {
        $role = Role::firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug), 'is_superadmin' => $superadmin]);

        return User::factory()->create(['role_id' => $role->id, 'branch_id' => null, 'password_changed_at' => now()]);
    }
}
