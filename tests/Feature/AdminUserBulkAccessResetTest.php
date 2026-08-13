<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\ContentItem;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\SalesLead;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AdminUserBulkAccessResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_primary_superadmin_resets_pending_and_active_sales_atomically_without_changing_identity_or_ownership(): void
    {
        Notification::fake();
        $actor = $this->user('superadmin');
        $supervisor = $this->user('supervisor');
        $coordinator = $this->user('sales_coordinator');
        $branch = Branch::create(['name' => 'Cabang Bulk', 'code' => 'BLK', 'is_active' => true]);
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Proyek Bulk', 'is_active' => true]);
        $pending = $this->user('staff', [
            'name' => 'Pending Tetap',
            'email' => 'pending-bulk@example.test',
            'account_status' => AccountStatus::PendingInvitation,
            'is_active' => false,
            'email_verified_at' => null,
            'password' => 'pending-old-password',
        ]);
        $sales = $this->user('sales', [
            'name' => 'Sales Tetap',
            'email' => 'sales-bulk@example.test',
            'branch_id' => $branch->id,
            'supervisor_user_id' => $supervisor->id,
            'password' => 'sales-old-password',
        ]);
        $sales->branches()->updateExistingPivot($branch->id, [
            'membership_role' => 'primary',
            'can_view' => true,
            'can_edit' => false,
            'can_sync' => true,
            'can_manage_members' => false,
        ]);
        $sales->assignedProjects()->attach($project->id, [
            'is_primary' => true,
            'is_active' => true,
            'assignment_start_date' => today()->subDay(),
            'assignment_end_date' => today()->addMonth(),
        ]);
        $coordinator->currentCoordinatorSales()->attach($sales->id, [
            'is_active' => true,
            'started_at' => today()->subDay(),
            'ended_at' => today()->addMonth(),
        ]);
        $lead = SalesLead::create([
            'branch_id' => $branch->id,
            'project_id' => $project->id,
            'sales_user_id' => $sales->id,
            'lead_date' => today(),
            'customer_name' => 'Konsumen Tetap',
            'created_by' => $sales->id,
            'updated_by' => $sales->id,
        ]);
        $agenda = ContentItem::create([
            'branch_id' => $branch->id,
            'item_type' => 'agenda',
            'agenda_type' => ContentItem::SALES_AGENDA_TYPE,
            'title' => 'Agenda Tetap',
            'scheduled_date' => today(),
            'status' => 'planned',
            'owner_user_id' => $sales->id,
            'sales_project_id' => $project->id,
            'created_by' => $sales->id,
            'updated_by' => $sales->id,
        ]);
        $history = ActivityLog::create([
            'event' => 'existing_history',
            'description' => 'Riwayat lama tetap ada.',
            'subject_type' => User::class,
            'subject_id' => $sales->id,
            'causer_id' => $actor->id,
            'properties' => ['kept' => true],
        ]);
        $invitation = UserInvitation::create([
            'user_id' => $pending->id,
            'invited_by' => $actor->id,
            'token_hash' => hash('sha256', 'pending-token'),
            'expires_at' => now()->addDay(),
            'sent_at' => now(),
        ]);
        DB::table('sessions')->insert([
            'id' => 'bulk-target-session',
            'user_id' => $sales->id,
            'payload' => 'payload',
            'last_activity' => time(),
        ]);
        DB::table('password_reset_tokens')->insert([
            'email' => $sales->email,
            'token' => Hash::make('reset-token'),
            'created_at' => now(),
        ]);

        $identity = $sales->only(['id', 'name', 'email', 'role_id', 'branch_id', 'supervisor_user_id']);
        $branchPivot = DB::table('branch_user')->where('user_id', $sales->id)->where('branch_id', $branch->id)->first();
        $projectPivot = DB::table('project_user')->where('user_id', $sales->id)->where('project_id', $project->id)->first();
        $coordinatorPivot = DB::table('sales_coordinator_sales')->where('sales_user_id', $sales->id)->first();

        $this->actingAs($actor)->post(route('admin-users.bulk-reset-access'), [
            'user_ids' => [$pending->id, $sales->id],
        ])->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success', 'Akses 2 pengguna berhasil direset.');

        foreach ([$pending->fresh(), $sales->fresh()] as $target) {
            $this->assertSame(AccountStatus::Active, $target->account_status);
            $this->assertTrue($target->is_active);
            $this->assertNotNull($target->email_verified_at);
            $this->assertTrue($target->must_change_password);
            $this->assertNull($target->password_changed_at);
            $this->assertTrue(Hash::check('password', $target->password));
        }
        $this->assertSame($identity, $sales->fresh()->only(array_keys($identity)));
        $this->assertEquals($branchPivot, DB::table('branch_user')->where('user_id', $sales->id)->where('branch_id', $branch->id)->first());
        $this->assertEquals($projectPivot, DB::table('project_user')->where('user_id', $sales->id)->where('project_id', $project->id)->first());
        $this->assertEquals($coordinatorPivot, DB::table('sales_coordinator_sales')->where('sales_user_id', $sales->id)->first());
        $this->assertSame($sales->id, $lead->fresh()->sales_user_id);
        $this->assertSame($sales->id, $lead->fresh()->created_by);
        $this->assertSame($sales->id, $agenda->fresh()->owner_user_id);
        $this->assertSame($sales->id, $agenda->fresh()->created_by);
        $this->assertDatabaseHas('activity_log', ['id' => $history->id, 'event' => 'existing_history']);
        $this->assertDatabaseHas('user_invitations', ['id' => $invitation->id, 'user_id' => $pending->id]);
        $this->assertNotNull($invitation->fresh()->revoked_at);
        $this->assertDatabaseMissing('sessions', ['id' => 'bulk-target-session']);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $sales->email]);
        Notification::assertNothingSent();

        foreach ([$pending, $sales] as $target) {
            $logs = ActivityLog::where('event', 'user_access_reset_bulk')->where('subject_id', $target->id)->get();
            $this->assertCount(1, $logs);
            $serialized = $logs->toJson();
            $this->assertStringNotContainsString('"password":"password"', $serialized);
            $this->assertStringNotContainsString($target->fresh()->password, $serialized);
        }
    }

    public function test_reset_password_login_requires_change_then_only_new_password_works(): void
    {
        Notification::fake();
        $actor = $this->user('superadmin');
        $sales = $this->user('sales', ['email' => 'bulk-login@example.test', 'password' => 'old-password']);

        $this->actingAs($actor)->post(route('admin-users.bulk-reset-access'), ['user_ids' => [$sales->id]])->assertSessionHasNoErrors();
        $this->post(route('logout'));
        $this->post('/login', ['email' => $sales->email, 'password' => 'old-password']);
        $this->assertGuest();
        $this->post('/login', ['email' => $sales->email, 'password' => 'password'])->assertRedirect(route('password.change'));
        $this->get(route('dashboard'))->assertRedirect(route('password.change'));
        $this->put(route('password.change.update'), [
            'password' => 'new-bulk-password',
            'password_confirmation' => 'new-bulk-password',
        ])->assertRedirect(route($sales->landingRouteName()));
        $this->post(route('logout'));
        $this->post('/login', ['email' => $sales->email, 'password' => 'password']);
        $this->assertGuest();
        $this->post('/login', ['email' => $sales->email, 'password' => 'new-bulk-password']);
        $this->assertAuthenticatedAs($sales);
    }

    public function test_non_superadmin_and_supplemental_superadmin_are_forbidden(): void
    {
        $target = $this->user('sales');
        $staff = $this->user('staff');
        $supplemental = $this->user('staff');
        $supplemental->roles()->attach(Role::where('slug', 'superadmin')->firstOrFail());

        foreach ([$staff, $supplemental] as $actor) {
            $this->actingAs($actor)->post(route('admin-users.bulk-reset-access'), ['user_ids' => [$target->id]])->assertForbidden();
        }

        $this->assertTrue(Hash::check('password', $target->fresh()->password));
        $this->assertFalse($target->fresh()->must_change_password);
    }

    public function test_forged_missing_id_fails_validation_without_mutation(): void
    {
        $actor = $this->user('superadmin');
        $target = $this->user('sales', ['password' => 'unchanged-password']);

        $this->actingAs($actor)->post(route('admin-users.bulk-reset-access'), [
            'user_ids' => [$target->id, 999999],
        ])->assertSessionHasErrors(['user_ids.1']);

        $this->assertTrue(Hash::check('unchanged-password', $target->fresh()->password));
        $this->assertDatabaseMissing('activity_log', ['event' => 'user_access_reset_bulk']);
    }

    public function test_mixed_valid_with_self_or_anonymized_target_rolls_back_every_target(): void
    {
        foreach (['self', 'anonymized'] as $invalid) {
            $actor = $this->user('superadmin');
            $valid = $this->user('sales', ['password' => "unchanged-{$invalid}"]);
            $invalidTarget = $invalid === 'self' ? $actor : $this->user('staff', [
                'account_status' => AccountStatus::Anonymized,
                'is_active' => false,
                'anonymized_at' => now(),
            ]);

            $response = $this->actingAs($actor)->post(route('admin-users.bulk-reset-access'), [
                'user_ids' => [$valid->id, $invalidTarget->id],
            ]);
            $invalid === 'self' ? $response->assertForbidden() : $response->assertSessionHasErrors(['user_ids']);
            $this->assertTrue(Hash::check("unchanged-{$invalid}", $valid->fresh()->password));
            $this->assertSame(AccountStatus::Active, $valid->fresh()->account_status);
            $this->assertDatabaseMissing('activity_log', ['event' => 'user_access_reset_bulk', 'subject_id' => $valid->id]);
        }
    }

    public function test_bulk_reset_ui_is_visible_only_to_primary_superadmin(): void
    {
        $target = $this->user('sales', ['name' => 'Target Checkbox']);
        $superadmin = $this->user('superadmin');
        $staff = $this->user('pusat');

        $this->actingAs($superadmin)->get(route('admin-users.index'))
            ->assertOk()
            ->assertSee('aria-label="Pilih Target Checkbox"', false)
            ->assertSee('Konfirmasi Reset / Aktifkan Akses')
            ->assertSee('Password awal:')
            ->assertSee('Pengguna wajib mengganti password saat login pertama.')
            ->assertSee('Email undangan tidak dikirim.')
            ->assertSee('Password lama tidak lagi berlaku.');
        $this->actingAs($staff)->get(route('admin-users.index'))
            ->assertOk()
            ->assertDontSee('aria-label="Pilih Target Checkbox"', false)
            ->assertDontSee('Konfirmasi Reset / Aktifkan Akses')
            ->assertDontSee(route('admin-users.bulk-reset-access'), false);
    }

    private function user(string $role, array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'role_id' => Role::where('slug', $role)->firstOrFail()->id,
            'account_status' => AccountStatus::Active,
            'is_active' => true,
            'email_verified_at' => now(),
            'must_change_password' => false,
            'password_changed_at' => now(),
        ], $attributes));
    }
}
