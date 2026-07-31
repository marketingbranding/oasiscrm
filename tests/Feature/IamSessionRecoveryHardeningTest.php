<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Models\Role;
use App\Models\User;
use App\Services\UserAccountService;
use App\Services\UserLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class IamSessionRecoveryHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function role(string $slug, bool $superadmin = false): Role
    {
        return Role::firstOrCreate(['slug' => $slug], ['name' => $slug, 'is_superadmin' => $superadmin]);
    }

    private function activeUser(string $roleSlug, bool $verified = true, bool $passwordChanged = true): User
    {
        $role = $this->role($roleSlug, $roleSlug === 'superadmin');

        return User::factory()->create([
            'role_id' => $role->id,
            'password_changed_at' => $passwordChanged ? now() : null,
            'account_status' => AccountStatus::Active,
            'is_active' => true,
            'email_verified_at' => $verified ? now() : null,
            'remember_token' => Str::random(60),
        ]);
    }

    private function addSession(User $user): string
    {
        $id = Str::random(40);
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'payload' => base64_encode('data'),
            'last_activity' => time(),
        ]);

        return $id;
    }

    private function addResetToken(User $user): void
    {
        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => hash('sha256', 'reset-token'),
            'created_at' => now(),
        ]);
    }

    public function test_suspend_clears_remember_token_sessions_and_reset_tokens(): void
    {
        $actor = $this->activeUser('superadmin');
        $target = $this->activeUser('staff');
        $other = $this->activeUser('staff');
        $targetSession = $this->addSession($target);
        $otherSession = $this->addSession($other);
        $this->addResetToken($target);

        $this->actingAs($actor)->patch(route('admin-users.suspend', $target), ['reason' => 'Cuti'])->assertRedirect();

        $target->refresh();
        $this->assertSame(AccountStatus::Suspended, $target->account_status);
        $this->assertNotSame(null, $target->remember_token);
        $this->assertDatabaseMissing('sessions', ['id' => $targetSession]);
        $this->assertDatabaseHas('sessions', ['id' => $otherSession]);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $target->email]);
    }

    public function test_deactivate_clears_remember_token_and_target_sessions_only(): void
    {
        $actor = $this->activeUser('superadmin');
        $target = $this->activeUser('staff');
        $other = $this->activeUser('staff');
        $targetSession = $this->addSession($target);
        $otherSession = $this->addSession($other);

        $this->actingAs($actor)->patch(route('admin-users.deactivate', $target), ['reason' => 'Pensiun'])->assertRedirect();

        $target->refresh();
        $this->assertSame(AccountStatus::Inactive, $target->account_status);
        $this->assertNotSame(null, $target->remember_token);
        $this->assertDatabaseMissing('sessions', ['id' => $targetSession]);
        $this->assertDatabaseHas('sessions', ['id' => $otherSession]);
    }

    public function test_anonymize_clears_remember_token_sessions_and_reset_tokens(): void
    {
        $actor = $this->activeUser('superadmin');
        $target = $this->activeUser('staff');
        $other = $this->activeUser('staff');
        $targetSession = $this->addSession($target);
        $otherSession = $this->addSession($other);
        $this->addResetToken($target);

        $this->actingAs($actor)->patch(route('admin-users.anonymize', $target), ['reason' => 'Hapus data pribadi'])->assertRedirect();

        $target->refresh();
        $this->assertSame(AccountStatus::Anonymized, $target->account_status);
        $this->assertNotSame(null, $target->remember_token);
        $this->assertDatabaseMissing('sessions', ['id' => $targetSession]);
        $this->assertDatabaseHas('sessions', ['id' => $otherSession]);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $target->email]);
    }

    public function test_reactivate_does_not_restore_old_session_or_remember_token(): void
    {
        $actor = $this->activeUser('superadmin');
        $target = $this->activeUser('staff');

        $this->actingAs($actor)->patch(route('admin-users.suspend', $target), ['reason' => 'Cuti'])->assertRedirect();
        $target->refresh();
        $clearedToken = $target->remember_token;

        $this->actingAs($actor)->patch(route('admin-users.reactivate', $target), ['reason' => 'Kembali'])->assertRedirect();

        $target->refresh();
        $this->assertSame(AccountStatus::Active, $target->account_status);
        $this->assertSame($clearedToken, $target->remember_token);
        $this->assertDatabaseMissing('sessions', ['user_id' => $target->id]);
    }

    public function test_actor_cannot_suspend_or_deactivate_self(): void
    {
        $actor = $this->activeUser('superadmin');

        $this->actingAs($actor)->patch(route('admin-users.suspend', $actor), ['reason' => 'Sendiri'])->assertForbidden();
        $this->actingAs($actor)->patch(route('admin-users.deactivate', $actor), ['reason' => 'Sendiri'])->assertForbidden();
        $this->assertSame(AccountStatus::Active, $actor->fresh()->account_status);
    }

    public function test_last_active_superadmin_cannot_be_suspended_or_deactivated(): void
    {
        $actor = $this->activeUser('superadmin');
        $other = $this->activeUser('staff');
        $lifecycle = app(UserLifecycleService::class);

        $this->expectException(\DomainException::class);
        $lifecycle->assertCriticalCapabilityContinuity($actor->fresh());
        $this->assertSame(AccountStatus::Active, $other->fresh()->account_status);
    }

    public function test_critical_capability_guard_blocks_last_maintenance_bypass_holder(): void
    {
        $target = $this->activeUser('pusat');
        $lifecycle = app(UserLifecycleService::class);

        try {
            $lifecycle->assertCriticalCapabilityContinuity($target);
            $this->fail('Expected DomainException for last maintenance bypass holder.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('satu-satunya akun aktif', $exception->getMessage());
            $this->assertStringContainsString('maintenance', $exception->getMessage());
        }
    }

    public function test_critical_capability_guard_allows_when_another_eligible_holder_exists(): void
    {
        $actor = $this->activeUser('superadmin');
        $target = $this->activeUser('pusat');
        $lifecycle = app(UserLifecycleService::class);

        $lifecycle->assertCriticalCapabilityContinuity($target);
        $this->assertSame(AccountStatus::Active, $actor->fresh()->account_status);
    }

    public function test_inactive_and_unverified_users_do_not_count_as_capability_holders(): void
    {
        $target = $this->activeUser('pusat');
        $this->activeUser('pusat', verified: false);
        $this->activeUser('pusat', passwordChanged: false);
        $lifecycle = app(UserLifecycleService::class);

        $this->expectException(\DomainException::class);
        $lifecycle->assertCriticalCapabilityContinuity($target);
    }

    public function test_supplemental_roles_do_not_count_as_capability_holders(): void
    {
        $target = $this->activeUser('admin');
        $supplemental = $this->activeUser('staff');
        $supplemental->roles()->attach($this->role('admin'));

        $lifecycle = app(UserLifecycleService::class);

        $this->expectException(\DomainException::class);
        $lifecycle->assertCriticalCapabilityContinuity($target);
    }

    public function test_superadmin_wildcard_counts_as_capability_holder(): void
    {
        $target = $this->activeUser('pusat');
        $this->activeUser('superadmin');
        $lifecycle = app(UserLifecycleService::class);

        $lifecycle->assertCriticalCapabilityContinuity($target);
        $this->assertTrue(true);
    }

    public function test_sequential_transitions_cannot_remove_all_capability_holders(): void
    {
        $a = $this->activeUser('pusat');
        $b = $this->activeUser('pusat');
        $actor = $this->activeUser('staff');
        $accounts = app(UserAccountService::class);

        $accounts->suspend($b, $actor);
        $this->assertSame(AccountStatus::Suspended, $b->fresh()->account_status);

        try {
            $accounts->suspend($a, $actor);
            $this->fail('Expected DomainException when disabling the final capability holder.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('satu-satunya akun aktif', $exception->getMessage());
        }
        $this->assertSame(AccountStatus::Active, $a->fresh()->account_status);
    }

    public function test_guard_does_not_block_when_action_removes_no_capability(): void
    {
        $actor = $this->activeUser('superadmin');
        $target = $this->activeUser('staff');
        $accounts = app(UserAccountService::class);

        $accounts->suspend($target, $actor);
        $this->assertSame(AccountStatus::Suspended, $target->fresh()->account_status);
    }

    public function test_revoked_access_event_is_audited_without_sensitive_values(): void
    {
        $actor = $this->activeUser('superadmin');
        $target = $this->activeUser('staff');

        $this->actingAs($actor)->patch(route('admin-users.anonymize', $target), ['reason' => 'Permintaan data'])->assertRedirect();

        $log = DB::table('activity_log')->where('subject_id', $target->id)->where('event', 'user_anonymized')->first();
        $this->assertNotNull($log);
        $props = json_decode($log->properties, true);
        $this->assertSame(['sessions', 'remember_token', 'password_reset_tokens'], $props['new']['access_revoked']);
        $this->assertArrayNotHasKey('token', $props['new']);
        $this->assertArrayNotHasKey('password', $props['new']);
    }
}
