<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\User;
use App\Models\UserInvitation;
use App\Services\OperationalMaintenanceService;
use App\Services\UserProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserProvisioningServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_active_user_with_null_password_timestamp_and_false_flag_remains_eligible(): void
    {
        $role = Role::firstOrCreate(['slug' => 'superadmin'], ['name' => 'Super Admin', 'is_superadmin' => true]);
        $user = User::factory()->create([
            'role_id' => $role->id,
            'password_changed_at' => null,
            'must_change_password' => false,
        ]);

        $this->assertFalse($user->must_change_password);
        $this->assertTrue(app(OperationalMaintenanceService::class)->lifecycleEligibleBypassQuery()->whereKey($user)->exists());
    }

    public function test_superadmin_directly_activates_user_without_invitation_or_password_audit_data(): void
    {
        $superadmin = Role::firstOrCreate(['slug' => 'superadmin'], ['name' => 'Super Admin', 'is_superadmin' => true]);
        $role = Role::firstOrCreate(['slug' => 'staff'], ['name' => 'Staff', 'is_superadmin' => false]);
        $actor = User::factory()->create(['role_id' => $superadmin->id]);
        $temporaryPassword = 'Temporary-Secret-123!';

        $user = app(UserProvisioningService::class)->createDirectlyActivated([
            'name' => 'Pengguna Langsung',
            'email' => 'direct@example.test',
            'role_id' => $role->id,
        ], $temporaryPassword, $actor);

        $user->refresh();
        $this->assertSame(AccountStatus::Active, $user->account_status);
        $this->assertTrue($user->is_active);
        $this->assertTrue($user->must_change_password);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNotNull($user->activated_at);
        $this->assertNull($user->password_changed_at);
        $this->assertSame($actor->id, $user->created_by);
        $this->assertSame($actor->id, $user->updated_by);
        $this->assertTrue(Hash::check($temporaryPassword, $user->password));
        $this->assertNotSame($temporaryPassword, $user->getRawOriginal('password'));
        $this->assertSame(0, UserInvitation::where('user_id', $user->id)->count());
        $this->assertSame(0, ActivityLog::where('subject_id', $user->id)->count());
        $this->assertStringNotContainsString($temporaryPassword, ActivityLog::where('subject_id', $user->id)->get()->toJson());
    }

    public function test_non_superadmin_cannot_directly_activate_user(): void
    {
        $role = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin', 'is_superadmin' => false]);
        $actor = User::factory()->create(['role_id' => $role->id]);

        $this->expectException(\DomainException::class);

        app(UserProvisioningService::class)->createDirectlyActivated([
            'name' => 'Ditolak',
            'email' => 'denied@example.test',
            'role_id' => $role->id,
        ], 'Temporary-Secret-123!', $actor);
    }
}
