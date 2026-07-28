<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Models\Branch;
use App\Models\ContentItem;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\User;
use App\Models\UserInvitation;
use App\Services\UserAdministrationService;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class IdentityAccessManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_normalizes_email_records_metadata_and_uses_role_landing_pages(): void
    {
        $sales = $this->user('sales', email: 'sales@example.com');

        $response = $this->withHeader('User-Agent', 'Oasis IAM test agent')
            ->post('/login', ['email' => '  SALES@EXAMPLE.COM ', 'password' => 'password']);

        $response->assertRedirect(route('sales-pocketbook.index'));
        $this->assertAuthenticatedAs($sales);
        $sales->refresh();
        $this->assertNotNull($sales->last_login_at);
        $this->assertSame('127.0.0.1', $sales->last_login_ip);
        $this->assertSame('Oasis IAM test agent', $sales->last_login_user_agent);

        $this->post('/logout');
        $staff = $this->user('staff');
        $this->post('/login', ['email' => $staff->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_login_rejects_wrong_password_and_non_active_onboarding_states_without_disclosure(): void
    {
        $generic = 'Email atau kata sandi tidak valid. Jika Anda menerima undangan, gunakan tautan aktivasi dari email terbaru.';
        $active = $this->user('staff');
        $this->post('/login', ['email' => $active->email, 'password' => 'incorrect'])
            ->assertSessionHasErrors(['email' => $generic]);

        foreach ([AccountStatus::PendingInvitation, AccountStatus::Invited] as $status) {
            $user = $this->user('staff', status: $status);
            $this->post('/login', ['email' => $user->email, 'password' => 'password'])
                ->assertSessionHasErrors(['email' => $generic]);
            $this->assertGuest();
        }

        $inactive = $this->user('staff', status: AccountStatus::Inactive);
        $this->post('/login', ['email' => $inactive->email, 'password' => 'password'])
            ->assertSessionHasErrors(['email' => 'Akun Anda sudah dinonaktifkan. Hubungi administrator OASIS.']);
        $suspended = $this->user('staff', status: AccountStatus::Suspended);
        $this->post('/login', ['email' => $suspended->email, 'password' => 'password'])
            ->assertSessionHasErrors(['email' => 'Akun Anda sedang ditangguhkan. Hubungi administrator OASIS.']);
    }

    public function test_unverified_active_user_is_kept_out_of_crm_and_public_registration_is_absent(): void
    {
        $user = $this->user('staff', verified: false);

        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('verification.notice'));
        $this->get('/register')->assertNotFound();
        $this->post('/register', [])->assertNotFound();
        $this->assertFalse(Route::has('register'));
    }

    public function test_superadmin_and_pusat_can_create_drafts_and_send_invitations_but_staff_cannot(): void
    {
        Notification::fake();
        $branch = $this->branch('SLO');
        $staffRole = Role::where('slug', 'staff')->firstOrFail();

        foreach (['superadmin', 'pusat'] as $role) {
            $actor = $this->user($role);
            $email = "{$role}.invite@example.com";
            $this->actingAs($actor)->post(route('admin-users.store'), [
                'name' => "Undangan {$role}",
                'email' => $email,
                'role_id' => $staffRole->id,
                'branch_id' => $branch->id,
                'branch_ids' => [$branch->id],
                'submit_action' => $role === 'superadmin' ? 'draft' : 'send',
            ])->assertRedirect();

            $expected = $role === 'superadmin' ? AccountStatus::PendingInvitation : AccountStatus::Invited;
            $this->assertSame($expected, User::where('email', $email)->firstOrFail()->account_status);
        }

        $unauthorized = $this->user('staff', $branch);
        $this->actingAs($unauthorized)->post(route('admin-users.store'), [
            'name' => 'Tidak Sah', 'email' => 'unauthorized@example.com', 'role_id' => $staffRole->id,
            'branch_id' => $branch->id, 'branch_ids' => [$branch->id], 'submit_action' => 'send',
        ])->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'unauthorized@example.com']);
    }

    public function test_duplicate_email_is_rejected_and_mail_failure_leaves_a_recoverable_invited_account(): void
    {
        $actor = $this->user('superadmin');
        $branch = $this->branch('JKT');
        $role = Role::where('slug', 'staff')->firstOrFail();
        $existing = $this->user('staff', email: 'duplicate@example.com');
        $payload = [
            'name' => 'Duplikat', 'email' => strtoupper($existing->email), 'role_id' => $role->id,
            'branch_id' => $branch->id, 'branch_ids' => [$branch->id], 'submit_action' => 'draft',
        ];

        $this->actingAs($actor)->post(route('admin-users.store'), $payload)
            ->assertSessionHasErrors('email');
        $this->assertSame(1, User::where('email', $existing->email)->count());

        $this->mock(ChannelManager::class, function ($mock) {
            $mock->shouldReceive('send')->once()->andThrow(new \RuntimeException('mail transport unavailable'));
        });
        $payload['email'] = 'recoverable@example.com';
        $payload['submit_action'] = 'send';
        $this->actingAs($actor)->post(route('admin-users.store'), $payload)
            ->assertRedirect()->assertSessionHas('warning');

        $recoverable = User::where('email', 'recoverable@example.com')->firstOrFail();
        $this->assertSame(AccountStatus::Invited, $recoverable->account_status);
        $this->assertDatabaseHas('user_invitations', [
            'user_id' => $recoverable->id, 'sent_at' => null, 'accepted_at' => null, 'revoked_at' => null,
        ]);
    }

    public function test_revoked_invitation_cannot_be_used_and_invitation_expiry_is_72_hours(): void
    {
        Notification::fake();
        $actor = $this->user('superadmin');
        $target = $this->user('staff', status: AccountStatus::PendingInvitation);
        $rawToken = 'revocable-identity-invitation-token';
        $invitation = UserInvitation::create([
            'user_id' => $target->id, 'invited_by' => $actor->id,
            'token_hash' => hash('sha256', $rawToken), 'expires_at' => now()->addHours(72), 'sent_at' => now(),
        ]);
        $target->update(['account_status' => AccountStatus::Invited]);
        $this->assertTrue($invitation->expires_at->between(now()->addHours(71), now()->addHours(73)));
        $this->assertSame(64, strlen($invitation->token_hash));

        $this->actingAs($actor)->patch(route('admin-users.invitation.revoke', $target))->assertRedirect();
        $this->assertNotNull($invitation->refresh()->revoked_at);
        $this->post('/logout');
        $this->post(route('invitations.store', $rawToken), [
            'password' => 'new-secure-password', 'password_confirmation' => 'new-secure-password',
        ])->assertSessionHasErrors('invitation');
        $this->assertSame(AccountStatus::Invited, $target->refresh()->account_status);
    }

    public function test_lifecycle_actions_preserve_records_and_reactivation_restores_login(): void
    {
        $actor = $this->user('superadmin');
        $branch = $this->branch('SBY');
        $target = $this->user('staff', $branch);
        $record = ContentItem::create([
            'branch_id' => $branch->id, 'item_type' => 'task', 'visibility' => 'personal',
            'title' => 'Riwayat tetap ada', 'scheduled_date' => today(), 'deadline_date' => today(),
            'priority' => 'medium', 'status' => 'todo', 'created_by' => $target->id,
        ]);

        $this->actingAs($actor)->patch(route('admin-users.deactivate', $target), ['reason' => 'Kontrak selesai'])->assertRedirect();
        $this->assertSame(AccountStatus::Inactive, $target->refresh()->account_status);
        $this->assertNotNull($record->fresh());
        $this->post('/logout');
        $this->post('/login', ['email' => $target->email, 'password' => 'password'])->assertSessionHasErrors('email');

        $this->actingAs($actor)->patch(route('admin-users.reactivate', $target), ['reason' => 'Bergabung kembali'])->assertRedirect();
        $target->refresh();
        $this->assertSame(AccountStatus::Active, $target->account_status);
        $this->assertNull($target->deactivated_at);
        $this->post('/logout');
        $this->post('/login', ['email' => $target->email, 'password' => 'password']);
        $this->assertAuthenticatedAs($target);
        $this->assertDatabaseHas('activity_log', ['subject_id' => $target->id, 'event' => 'account_deactivated']);
        $this->assertDatabaseHas('activity_log', ['subject_id' => $target->id, 'event' => 'account_reactivated']);
    }

    public function test_password_reset_cannot_reactivate_suspended_or_inactive_accounts(): void
    {
        foreach ([AccountStatus::Suspended, AccountStatus::Inactive] as $status) {
            $user = $this->user('staff', status: $status);
            $token = app('auth.password.broker')->createToken($user);

            $this->post(route('password.store'), [
                'token' => $token, 'email' => strtoupper($user->email),
                'password' => 'replacement-password', 'password_confirmation' => 'replacement-password',
            ])->assertSessionHasErrors('email');

            $user->refresh();
            $this->assertSame($status, $user->account_status);
            $this->assertTrue(Hash::check('password', $user->password));
        }
    }

    public function test_seeded_role_permission_mappings_are_exact_and_superadmin_has_no_pivots(): void
    {
        foreach (PermissionCatalog::rolePermissions() as $roleSlug => $expected) {
            $actual = Role::where('slug', $roleSlug)->firstOrFail()->permissions()->pluck('slug')->all();
            sort($expected);
            sort($actual);
            $this->assertSame($expected, $actual, $roleSlug);
        }

        $superadmin = Role::where('slug', 'superadmin')->firstOrFail();
        $this->assertEqualsCanonicalizing(
            collect(PermissionCatalog::permissions())->pluck('slug')->all(),
            $superadmin->permissions()->pluck('slug')->all(),
        );
        $this->assertTrue($this->user('superadmin')->hasPermission('system_health.view'));
    }

    public function test_lower_rank_cannot_assign_higher_role_or_change_self_and_last_superadmin_is_protected(): void
    {
        $branch = $this->branch('MGL');
        $branchManager = $this->user('branch_manager', $branch);
        $pusatRole = Role::where('slug', 'pusat')->firstOrFail();

        $this->actingAs($branchManager)->post(route('admin-users.store'), [
            'name' => 'Terlalu Tinggi', 'email' => 'higher@example.com', 'role_id' => $pusatRole->id,
            'branch_id' => $branch->id, 'branch_ids' => [$branch->id], 'submit_action' => 'draft',
        ])->assertForbidden();
        $this->actingAs($branchManager)->get(route('admin-users.edit', $branchManager))->assertForbidden();

        $onlySuperadmin = $this->user('superadmin');
        $this->actingAs($onlySuperadmin)->patch(route('admin-users.suspend', $onlySuperadmin), ['reason' => 'Tidak boleh'])
            ->assertForbidden();

        $otherSuperadmin = $this->user('superadmin');
        $this->actingAs($otherSuperadmin)->patch(route('admin-users.deactivate', $onlySuperadmin), ['reason' => 'Rotasi'])
            ->assertRedirect();
        try {
            app(UserAdministrationService::class)->assertNotLastActiveSuperadmin($otherSuperadmin->refresh());
            $this->fail('The last active superadmin should be protected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }
        $this->assertSame(AccountStatus::Active, $otherSuperadmin->refresh()->account_status);
    }

    public function test_user_index_search_and_all_six_domain_filters_select_the_expected_user(): void
    {
        $actor = $this->user('superadmin');
        $branch = $this->branch('BDG');
        $otherBranch = $this->branch('BKS');
        $supervisor = $this->user('manager', $branch);
        $target = $this->user('staff', $branch, AccountStatus::Invited, 'needle@example.com');
        $target->update(['name' => 'Needle Identity', 'supervisor_user_id' => $supervisor->id]);
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Proyek Target', 'is_active' => true]);
        $target->assignedProjects()->attach($project->id, ['is_primary' => true, 'is_active' => true]);
        UserInvitation::create([
            'user_id' => $target->id, 'invited_by' => $actor->id,
            'token_hash' => hash('sha256', 'filter-token'), 'expires_at' => now()->addDay(), 'sent_at' => now(),
        ]);
        $decoy = $this->user('manager', $otherBranch, AccountStatus::Active, 'decoy@example.com');

        $filters = [
            ['search' => 'Needle Identity'],
            ['account_status' => AccountStatus::Invited->value],
            ['role_id' => $target->role_id],
            ['branch_id' => $branch->id],
            ['project_id' => $project->id],
            ['supervisor_user_id' => $supervisor->id],
            ['invitation_status' => 'usable'],
        ];
        foreach ($filters as $filter) {
            $users = $this->actingAs($actor)->get(route('admin-users.index', $filter))
                ->assertOk()->viewData('users');
            $this->assertContains($target->id, $users->pluck('id')->all(), json_encode($filter));
            $this->assertNotContains($decoy->id, $users->pluck('id')->all(), json_encode($filter));
        }
    }

    public function test_user_update_rejects_stale_optimistic_token_and_profile_cannot_change_org_fields(): void
    {
        $actor = $this->user('superadmin');
        $branch = $this->branch('DPS');
        $target = $this->user('staff', $branch);
        $originalRole = $target->role_id;

        $this->actingAs($actor)->putJson(route('admin-users.update', $target), [
            'expected_updated_at' => '2000-01-01 00:00:00', 'name' => 'Stale overwrite',
            'email' => $target->email, 'role_id' => $target->role_id,
            'branch_id' => $branch->id, 'branch_ids' => [$branch->id],
        ])->assertConflict()->assertJsonPath('code', 'record_modified');
        $this->assertNotSame('Stale overwrite', $target->fresh()->name);

        $otherRole = Role::where('slug', 'manager')->firstOrFail();
        $otherBranch = $this->branch('LPG');
        $this->actingAs($target)->patch(route('profile.update'), [
            'name' => 'Profil Baru', 'email' => $target->email,
            'role_id' => $otherRole->id, 'branch_id' => $otherBranch->id,
        ])->assertRedirect(route('profile.edit'));
        $target->refresh();
        $this->assertSame('Profil Baru', $target->name);
        $this->assertSame($originalRole, $target->role_id);
        $this->assertSame($branch->id, $target->branch_id);
    }

    public function test_phase_changelogs_exist_once_and_render_together(): void
    {
        $titles = [
            'Aktivasi Akun melalui Undangan',
            'Peran Organisasi dan Izin Akses',
            'Penugasan Organisasi yang Lebih Aman',
            'Onboarding dan pengelolaan akses pengguna',
            'Akses modul berdasarkan izin pengguna',
            'Konflik perubahan data pengguna ditangani dengan benar',
        ];
        foreach ($titles as $title) {
            $this->assertSame(1, DB::table('changelogs')->whereNull('version')->where('title', $title)->count(), $title);
        }

        $response = $this->actingAs($this->user('superadmin'))->get(route('changelogs.index'))->assertOk();
        foreach ($titles as $title) {
            $response->assertSeeText($title);
        }
    }

    private function user(
        string $role,
        ?Branch $branch = null,
        AccountStatus $status = AccountStatus::Active,
        ?string $email = null,
        bool $verified = true,
    ): User {
        $attributes = [
            'role_id' => Role::where('slug', $role)->firstOrFail()->id,
            'branch_id' => $branch?->id,
            'account_status' => $status,
            'email_verified_at' => $verified ? now() : null,
            'password_changed_at' => now(),
        ];
        if ($email !== null) {
            $attributes['email'] = $email;
        }

        return User::factory()->create($attributes);
    }

    private function branch(string $code): Branch
    {
        return Branch::create(['name' => "Cabang {$code}", 'code' => $code, 'is_active' => true]);
    }
}
