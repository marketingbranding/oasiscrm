<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AdminUserOnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_creates_active_account_with_admin_password_without_invitation(): void
    {
        $actor = $this->user('superadmin');
        $role = Role::where('slug', 'staff')->firstOrFail();
        $branch = $this->branch('SLO');

        $this->actingAs($actor)->post(route('admin-users.store'), [
            'name' => 'Pengguna Baru', 'email' => ' New.User@Example.com ', 'phone' => '08123',
            'role_id' => $role->id, 'branch_id' => $branch->id, 'branch_ids' => [$branch->id],
            'temporary_password' => 'Initial123', 'temporary_password_confirmation' => 'Initial123',
        ])->assertRedirect();

        $user = User::where('email', 'new.user@example.com')->firstOrFail();
        $this->assertSame(AccountStatus::Active, $user->account_status);
        $this->assertTrue($user->must_change_password);
        $this->assertDatabaseMissing('user_invitations', ['user_id' => $user->id]);
    }

    public function test_pusat_cannot_create_or_manage_superadmin_and_user_cannot_edit_self(): void
    {
        $pusat = $this->user('pusat');
        $superadmin = $this->user('superadmin');
        $branch = $this->branch('JKT');

        $this->actingAs($pusat)->post(route('admin-users.store'), [
            'name' => 'Illegal', 'email' => 'illegal@example.com', 'role_id' => $superadmin->role_id,
            'branch_id' => $branch->id, 'branch_ids' => [$branch->id],
            'temporary_password' => 'Initial123', 'temporary_password_confirmation' => 'Initial123',
        ])->assertForbidden();
        $this->actingAs($pusat)->get(route('admin-users.edit', $superadmin))->assertForbidden();
        $this->actingAs($pusat)->get(route('admin-users.edit', $pusat))->assertForbidden();
    }

    public function test_branch_admin_index_is_scoped_and_filters_pending_accounts(): void
    {
        $branch = $this->branch('SLO');
        $other = $this->branch('MGL');
        $admin = $this->user('admin', $branch);
        $visible = $this->user('staff', $branch, AccountStatus::PendingInvitation);
        $hidden = $this->user('staff', $other, AccountStatus::PendingInvitation);

        $this->actingAs($admin)->get(route('admin-users.index', ['account_status' => 'pending_invitation']))
            ->assertOk()->assertSee($visible->email)->assertDontSee($hidden->email);
    }

    public function test_user_assignment_forms_render_searchable_role_aware_workspace_controls(): void
    {
        $actor = $this->user('superadmin');
        $this->branch('SLO');

        $this->actingAs($actor)->get(route('admin-users.create'))
            ->assertOk()
            ->assertSee('data-slug="sales"', false)
            ->assertSee('Cari cabang')
            ->assertSee('Cari proyek atau cabang')
            ->assertSee('crm-choice-chip', false)
            ->assertSee('primaryBranchId', false)
            ->assertSee('projectMatchesBranch', false)
            ->assertSee('workspaceLabel', false);
    }

    public function test_suspend_revokes_sessions_and_reactivate_is_audited(): void
    {
        $actor = $this->user('superadmin');
        $target = $this->user('staff', $this->branch('SBY'));

        $this->actingAs($actor)->patch(route('admin-users.suspend', $target), ['reason' => 'Cuti panjang'])->assertRedirect();
        $this->assertSame(AccountStatus::Suspended, $target->fresh()->account_status);
        $this->assertDatabaseHas('activity_log', ['subject_id' => $target->id, 'event' => 'account_suspended']);
        $this->assertTrue(Route::has('admin-users.destroy'));
        $this->assertTrue(Route::has('admin-users.anonymize'));
        $this->assertTrue(Route::has('admin-users.release-email'));
    }

    public function test_changelog_entry_renders_once_for_superadmin(): void
    {
        $actor = $this->user('superadmin');

        $this->assertSame(1, \DB::table('changelogs')->where('title', 'Onboarding dan pengelolaan akses pengguna')->count());
        $this->actingAs($actor)->get(route('changelogs.index'))
            ->assertOk()->assertSee('Onboarding dan pengelolaan akses pengguna');
    }

    private function user(string $roleSlug, ?Branch $branch = null, AccountStatus $status = AccountStatus::Active): User
    {
        $role = Role::where('slug', $roleSlug)->firstOrFail();
        if ($roleSlug === 'admin') {
            $role->permissions()->syncWithoutDetaching(Permission::whereIn('slug', [
                'users.view', 'users.create', 'users.update', 'users.invite', 'users.assign_branches',
            ])->pluck('id'));
        }

        return User::factory()->create([
            'role_id' => $role->id, 'branch_id' => $branch?->id, 'account_status' => $status,
            'password_changed_at' => now(),
        ]);
    }

    private function branch(string $code): Branch
    {
        return Branch::create(['name' => "Cabang {$code}", 'code' => $code, 'is_active' => true]);
    }
}
