<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AdminUserDirectActivationTest extends TestCase
{
    use RefreshDatabase;

    public function test_primary_superadmin_directly_activates_user_with_assignments_and_safe_audit(): void
    {
        $actor = User::factory()->create(['role_id' => Role::where('slug', 'superadmin')->value('id'), 'password_changed_at' => now()]);
        $role = Role::where('slug', 'staff')->firstOrFail();
        $branch = Branch::create(['name' => 'Cabang Direct', 'code' => 'DIR', 'is_active' => true]);
        $password = 'Temporary123';

        $this->actingAs($actor)->post(route('admin-users.store'), [
            'name' => 'Pengguna Direct',
            'email' => 'direct-controller@example.test',
            'role_id' => $role->id,
            'branch_id' => $branch->id,
            'branch_ids' => [$branch->id],
            'provisioning_mode' => 'direct',
            'temporary_password' => $password,
            'temporary_password_confirmation' => $password,
            'submit_action' => 'activate',
        ])->assertRedirect()->assertSessionHas('success', 'Akun berhasil dibuat dan diaktifkan. Pengguna wajib mengganti password saat login pertama.');

        $user = User::where('email', 'direct-controller@example.test')->firstOrFail();
        $this->assertSame(AccountStatus::Active, $user->account_status);
        $this->assertTrue(Hash::check($password, $user->password));
        $this->assertTrue($user->branches()->whereKey($branch->id)->exists());
        $this->assertFalse(UserInvitation::where('user_id', $user->id)->exists());
        $this->assertDatabaseHas('activity_log', ['subject_id' => $user->id, 'event' => 'user_created']);
        $this->assertDatabaseHas('activity_log', ['subject_id' => $user->id, 'event' => 'user_directly_activated']);
        $this->assertStringNotContainsString($password, ActivityLog::where('subject_id', $user->id)->get()->toJson());
    }

    public function test_primary_superadmin_directly_activates_primary_sales_with_all_assignments_and_complete_state(): void
    {
        $actor = User::factory()->create(['role_id' => Role::where('slug', 'superadmin')->value('id'), 'password_changed_at' => now()]);
        $role = Role::where('slug', 'sales')->firstOrFail();
        $supervisor = User::factory()->create(['role_id' => Role::where('slug', 'supervisor')->value('id'), 'password_changed_at' => now()]);
        $branch = Branch::create(['name' => 'Cabang Sales Direct', 'code' => 'SDR', 'is_active' => true]);
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Proyek Sales Direct', 'is_active' => true]);
        $supervisor->branches()->attach($branch->id, ['can_view' => true]);
        $supervisor->forceFill(['branch_id' => $branch->id])->save();

        $this->actingAs($actor)->post(route('admin-users.store'), $this->payload($role, $branch, [
            'email' => 'sales-direct@example.test',
            'primary_project_id' => $project->id,
            'assigned_project_ids' => [$project->id],
            'supervisor_user_id' => $supervisor->id,
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $user = User::where('email', 'sales-direct@example.test')->firstOrFail();
        $this->assertSame($role->id, $user->role_id);
        $this->assertSame($branch->id, $user->branch_id);
        $this->assertSame($supervisor->id, $user->supervisor_user_id);
        $this->assertTrue($user->branches()->whereKey($branch->id)->exists());
        $this->assertTrue($user->assignedProjects()->whereKey($project->id)->wherePivot('is_primary', true)->wherePivot('is_active', true)->exists());
        $this->assertSame(AccountStatus::Active, $user->account_status);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNotNull($user->activated_at);
        $this->assertTrue($user->is_active);
        $this->assertTrue($user->must_change_password);
        $this->assertNull($user->password_changed_at);
        $this->assertSame($actor->id, $user->created_by);
        $this->assertSame($actor->id, $user->updated_by);
        $this->assertFalse(UserInvitation::where('user_id', $user->id)->exists());
        $audit = ActivityLog::where('subject_id', $user->id)->get()->toJson();
        $this->assertStringNotContainsString('Temporary123', $audit);
        $this->assertStringNotContainsString($user->password, $audit);
    }

    public function test_assignment_domain_failure_rolls_back_directly_activated_user(): void
    {
        $actor = User::factory()->create(['role_id' => Role::where('slug', 'superadmin')->value('id'), 'password_changed_at' => now()]);
        $role = Role::where('slug', 'sales')->firstOrFail();
        $branch = Branch::create(['name' => 'Cabang Rollback', 'code' => 'RBK', 'is_active' => true]);

        $this->actingAs($actor)->post(route('admin-users.store'), $this->payload($role, $branch, [
            'email' => 'rollback-direct@example.test',
        ]))->assertSessionHasErrors(['assigned_project_ids']);

        $this->assertDatabaseMissing('users', ['email' => 'rollback-direct@example.test']);
        $this->assertDatabaseMissing('users', ['email' => 'rollback-direct@example.test', 'account_status' => AccountStatus::Active->value]);
    }

    public function test_direct_role_users_complete_mandatory_password_change_and_reach_landing(): void
    {
        foreach (['sales', 'sales_coordinator', 'supervisor'] as $slug) {
            $actor = User::factory()->create(['role_id' => Role::where('slug', 'superadmin')->value('id'), 'password_changed_at' => now()]);
            $role = Role::where('slug', $slug)->firstOrFail();
            $branch = Branch::create(['name' => "Cabang {$slug}", 'code' => strtoupper(substr($slug, 0, 3)).$role->id, 'is_active' => true]);
            $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => "Proyek {$slug}", 'is_active' => true]);
            $email = "direct-{$slug}@example.test";
            $overrides = ['email' => $email];
            if ($slug === 'sales') {
                $overrides += ['primary_project_id' => $project->id, 'assigned_project_ids' => [$project->id]];
            }

            $this->actingAs($actor)->post(route('admin-users.store'), $this->payload($role, $branch, $overrides))->assertSessionHasNoErrors();
            $this->post(route('logout'));
            $this->post('/login', ['email' => $email, 'password' => 'Temporary123'])->assertRedirect(route('password.change'));
            $user = User::where('email', $email)->firstOrFail();
            $this->assertAuthenticatedAs($user);
            $this->get(route($user->landingRouteName()))->assertRedirect(route('password.change'));
            $newPassword = "Changed123{$role->id}";
            $this->put(route('password.change.update'), ['password' => $newPassword, 'password_confirmation' => $newPassword])
                ->assertRedirect(route($user->landingRouteName()));
            $this->get(route($user->landingRouteName()))->assertOk();

            $user->refresh();
            $this->assertFalse($user->must_change_password);
            $this->assertNotNull($user->password_changed_at);
            $this->assertTrue(Hash::check($newPassword, $user->password));
            $this->post(route('logout'));
        }
    }

    public function test_reset_access_for_active_direct_user_preserves_activation_and_creates_no_invitation(): void
    {
        Notification::fake();
        $actor = User::factory()->create(['role_id' => Role::where('slug', 'superadmin')->value('id'), 'password_changed_at' => now()]);
        $role = Role::where('slug', 'staff')->firstOrFail();
        $branch = Branch::create(['name' => 'Cabang Reset Direct', 'code' => 'RSD', 'is_active' => true]);
        $this->actingAs($actor)->post(route('admin-users.store'), $this->payload($role, $branch, ['email' => 'reset-direct@example.test']));
        $user = User::where('email', 'reset-direct@example.test')->firstOrFail();
        $activatedAt = $user->activated_at;

        $this->post(route('admin-users.reset-access', $user))->assertSessionHas('success');

        $user->refresh();
        $this->assertSame(AccountStatus::Active, $user->account_status);
        $this->assertTrue($user->is_active);
        $this->assertTrue($activatedAt->equalTo($user->activated_at));
        $this->assertFalse(UserInvitation::where('user_id', $user->id)->exists());
        Notification::assertSentTo($user, ResetPassword::class);
        $this->assertDatabaseHas('activity_log', ['subject_id' => $user->id, 'event' => 'password_reset_requested']);
    }

    public function test_non_primary_superadmin_cannot_forge_direct_activation(): void
    {
        $staff = Role::where('slug', 'staff')->firstOrFail();
        $superadmin = Role::where('slug', 'superadmin')->firstOrFail();
        $actor = User::factory()->create(['role_id' => $staff->id, 'password_changed_at' => now()]);
        $actor->roles()->attach($superadmin);
        $branch = Branch::create(['name' => 'Cabang Forged', 'code' => 'FRG', 'is_active' => true]);

        $this->actingAs($actor)->post(route('admin-users.store'), $this->payload($staff, $branch))->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'forged-direct@example.test']);
    }

    public function test_direct_activation_rejects_weak_mismatched_or_contradictory_submission(): void
    {
        $actor = User::factory()->create(['role_id' => Role::where('slug', 'superadmin')->value('id'), 'password_changed_at' => now()]);
        $role = Role::where('slug', 'staff')->firstOrFail();
        $branch = Branch::create(['name' => 'Cabang Invalid', 'code' => 'INV', 'is_active' => true]);

        $this->actingAs($actor)->post(route('admin-users.store'), $this->payload($role, $branch, [
            'temporary_password' => 'weak',
            'temporary_password_confirmation' => 'different123',
            'submit_action' => 'send',
            'send_immediately' => true,
        ]))->assertSessionHasErrors(['temporary_password', 'submit_action', 'send_immediately']);

        $this->assertDatabaseMissing('users', ['email' => 'forged-direct@example.test']);
    }

    private function payload(Role $role, Branch $branch, array $overrides = []): array
    {
        return array_merge([
            'name' => 'Forged Direct',
            'email' => 'forged-direct@example.test',
            'role_id' => $role->id,
            'branch_id' => $branch->id,
            'branch_ids' => [$branch->id],
            'provisioning_mode' => 'direct',
            'temporary_password' => 'Temporary123',
            'temporary_password_confirmation' => 'Temporary123',
            'submit_action' => 'activate',
        ], $overrides);
    }
}
