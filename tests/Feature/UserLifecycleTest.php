<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function superadmin(): User
    {
        $role = Role::firstOrCreate(['slug' => 'superadmin'], ['name' => 'Super Admin', 'is_superadmin' => true]);

        return User::factory()->create(['role_id' => $role->id, 'password_changed_at' => now()]);
    }

    private function userWithStatus(AccountStatus $status, ?Role $role = null): User
    {
        $role ??= Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin', 'is_superadmin' => false]);

        return User::factory()->create([
            'role_id' => $role->id,
            'password_changed_at' => now(),
            'account_status' => $status,
            'is_active' => $status === AccountStatus::Active,
            'email_verified_at' => $status === AccountStatus::Active ? now() : null,
        ]);
    }

    public function test_anonymize_replaces_personal_fields_and_blocks_access(): void
    {
        $actor = $this->superadmin();
        $target = $this->userWithStatus(AccountStatus::Active);

        $this->actingAs($actor)->patch(route('admin-users.anonymize', $target), ['reason' => 'permintaan penghapusan data pribadi'])
            ->assertRedirect();

        $target->refresh();
        $this->assertSame(AccountStatus::Anonymized, $target->account_status);
        $this->assertFalse($target->is_active);
        $this->assertNotNull($target->anonymized_at);
        $this->assertNull($target->email_verified_at);
        $this->assertNull($target->phone);
        $this->assertStringEndsWith('@invalid.oasis.local', $target->email);
        $this->assertStringContainsString('Pengguna Teranomimasi #'.$target->id, $target->name);
        $this->assertNotSame('active@example.com', $target->email);

        $this->assertDatabaseHas('activity_log', ['subject_id' => $target->id, 'event' => 'user_anonymized']);

        $this->actingAs($target)->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_anonymize_revokes_active_invitation_and_deletes_sessions(): void
    {
        $actor = $this->superadmin();
        $target = $this->userWithStatus(AccountStatus::Invited);
        $target->invitations()->create([
            'invited_by' => $actor->id,
            'token_hash' => hash('sha256', 'raw-token-abc'),
            'expires_at' => now()->addHours(72),
            'sent_at' => now(),
        ]);

        $this->actingAs($actor)->patch(route('admin-users.anonymize', $target), ['reason' => 'hapus undangan'])->assertRedirect();

        $this->assertSame(AccountStatus::Anonymized, $target->fresh()->account_status);
        $this->assertNull($target->invitations()->whereNull('revoked_at')->first());
    }

    public function test_email_release_only_for_deactivated_account(): void
    {
        $actor = $this->superadmin();
        $deactivated = $this->userWithStatus(AccountStatus::Inactive);
        $active = $this->userWithStatus(AccountStatus::Active);
        $activeEmail = $active->email;

        $this->actingAs($actor)->patch(route('admin-users.release-email', $deactivated), ['reason' => 'email akan dipakai ulang'])->assertRedirect();
        $deactivated->refresh();
        $this->assertStringEndsWith('@invalid.oasis.local', $deactivated->email);
        $this->assertSame(AccountStatus::Inactive, $deactivated->account_status);
        $this->assertDatabaseHas('activity_log', ['subject_id' => $deactivated->id, 'event' => 'user_email_released']);

        $this->actingAs($actor)->patch(route('admin-users.release-email', $active), ['reason' => 'coba lepas'])
            ->assertRedirect()
            ->assertSessionHas('warning');
        $this->assertSame($activeEmail, $active->fresh()->email);
    }

    public function test_safe_draft_can_be_permanently_deleted(): void
    {
        $actor = $this->superadmin();
        $draft = $this->userWithStatus(AccountStatus::PendingInvitation);

        $this->actingAs($actor)->delete(route('admin-users.destroy', $draft), ['reason' => 'draf salah'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('users', ['id' => $draft->id]);
        $this->assertDatabaseHas('activity_log', ['subject_id' => $draft->id, 'event' => 'user_draft_deleted']);
    }

    public function test_history_bearing_user_cannot_be_permanently_deleted(): void
    {
        $actor = $this->superadmin();
        $draft = $this->userWithStatus(AccountStatus::PendingInvitation);
        $branch = Branch::create(['name' => 'Jepara', 'code' => 'JPR', 'is_active' => true]);
        $draft->update(['branch_id' => $branch->id]);

        $this->actingAs($actor)->delete(route('admin-users.destroy', $draft), ['reason' => 'coba hapus'])
            ->assertRedirect()
            ->assertSessionHas('warning');

        $this->assertDatabaseHas('users', ['id' => $draft->id]);
    }

    public function test_non_draft_user_cannot_be_permanently_deleted(): void
    {
        $actor = $this->superadmin();
        $active = $this->userWithStatus(AccountStatus::Active);

        $this->actingAs($actor)->delete(route('admin-users.destroy', $active), ['reason' => 'coba hapus'])
            ->assertRedirect()
            ->assertSessionHas('warning');

        $this->assertDatabaseHas('users', ['id' => $active->id]);
    }

    public function test_anonymized_user_cannot_be_edited_or_reset(): void
    {
        $actor = $this->superadmin();
        $target = $this->userWithStatus(AccountStatus::Active);
        $branch = Branch::create(['name' => 'Jepara', 'code' => 'JPR', 'is_active' => true]);

        $this->actingAs($actor)->patch(route('admin-users.anonymize', $target), ['reason' => 'hapus data'])->assertRedirect();

        $this->actingAs($actor)->put(route('admin-users.update', $target), [
            'name' => 'Nama Baru', 'email' => 'baru@example.com', 'role_id' => $target->role_id,
            'branch_id' => $branch->id,
            'expected_updated_at' => $target->updated_at->copy()->utc()->format('Y-m-d H:i:s'),
        ])->assertForbidden();

        $this->actingAs($actor)->post(route('admin-users.reset-access', $target))->assertForbidden();
    }

    public function test_self_anonymize_and_self_delete_are_denied(): void
    {
        $actor = $this->superadmin();

        $this->actingAs($actor)->patch(route('admin-users.anonymize', $actor), ['reason' => 'coba sendiri'])->assertForbidden();
        $this->actingAs($actor)->delete(route('admin-users.destroy', $actor), ['reason' => 'coba sendiri'])->assertForbidden();
    }

    public function test_lifecycle_actions_require_permission(): void
    {
        $manager = Role::firstOrCreate(['slug' => 'manager'], ['name' => 'Manager', 'is_superadmin' => false]);
        $actor = $this->userWithStatus(AccountStatus::Active, $manager);
        $target = $this->userWithStatus(AccountStatus::Inactive);

        $this->actingAs($actor)->patch(route('admin-users.anonymize', $target), ['reason' => 'tidak diizinkan'])->assertForbidden();
        $this->actingAs($actor)->patch(route('admin-users.release-email', $target), ['reason' => 'tidak diizinkan'])->assertForbidden();
        $this->actingAs($actor)->delete(route('admin-users.destroy', $target), ['reason' => 'tidak diizinkan'])->assertForbidden();
    }

    public function test_anonymize_requires_reason(): void
    {
        $actor = $this->superadmin();
        $target = $this->userWithStatus(AccountStatus::Active);

        $this->actingAs($actor)->patch(route('admin-users.anonymize', $target), [])->assertSessionHasErrors('reason');
        $this->assertSame(AccountStatus::Active, $target->fresh()->account_status);
    }
}
