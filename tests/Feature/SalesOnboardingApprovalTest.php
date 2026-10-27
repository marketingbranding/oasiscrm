<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Models\Branch;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\SalesOnboardingProposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SalesOnboardingApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_in_scope_admin_creates_pending_sales_proposal_without_account_or_invitation(): void
    {
        Notification::fake();
        [$admin, $branch, $project, $coordinator] = $this->proposalContext();

        $response = $this->actingAs($admin)->post(route('admin-users.sales-proposals.store'), [
            ...$this->proposalPayload($branch, $project, $coordinator),
            'email' => 'proposed.sales@example.test',
        ]);

        $response->assertRedirect(route('admin-users.index'));
        $proposal = SalesOnboardingProposal::where('email', 'proposed.sales@example.test')->firstOrFail();

        $this->assertSame(SalesOnboardingProposal::STATUS_PENDING, $proposal->status);
        $this->assertSame($admin->id, $proposal->submitted_by);
        $this->assertDatabaseMissing('users', ['email' => $proposal->email]);
        $this->assertDatabaseCount('user_invitations', 0);
        Notification::assertNothingSent();
    }

    public function test_admin_cannot_propose_sales_for_another_branch(): void
    {
        [$admin, , , $coordinator] = $this->proposalContext();
        $foreignBranch = $this->branch('FOREIGN');
        $foreignProject = $this->project($foreignBranch, 'Foreign Project');

        $this->actingAs($admin)->post(route('admin-users.sales-proposals.store'), $this->proposalPayload($foreignBranch, $foreignProject, $coordinator))
            ->assertForbidden();

        $this->assertDatabaseCount('sales_onboarding_proposals', 0);
    }

    public function test_admin_cannot_propose_sales_for_a_project_or_coordinator_outside_scope(): void
    {
        [$admin, $branch, $project, $coordinator] = $this->proposalContext();
        $foreignBranch = $this->branch('OTHER');
        $foreignProject = $this->project($foreignBranch, 'Other Project');
        $foreignCoordinator = $this->user('sales_coordinator', $foreignBranch, 'Other Coordinator');

        $this->actingAs($admin)->post(route('admin-users.sales-proposals.store'), [
            ...$this->proposalPayload($branch, $foreignProject, $coordinator),
            'email' => 'foreign-project@example.test',
        ])->assertForbidden();
        $this->actingAs($admin)->post(route('admin-users.sales-proposals.store'), [
            ...$this->proposalPayload($branch, $project, $foreignCoordinator),
            'email' => 'foreign-coordinator@example.test',
        ])->assertForbidden();
    }

    public function test_proposal_rejects_non_sales_role_even_when_the_request_is_tampered(): void
    {
        [$admin, $branch, $project, $coordinator] = $this->proposalContext();
        $staff = Role::where('slug', 'staff')->firstOrFail();

        $this->actingAs($admin)->post(route('admin-users.sales-proposals.store'), [
            ...$this->proposalPayload($branch, $project, $coordinator),
            'role_id' => $staff->id,
            'email' => 'tampered-role@example.test',
        ])->assertSessionHasErrors('role_id');

        $this->assertDatabaseCount('sales_onboarding_proposals', 0);
    }

    public function test_pending_sales_proposal_has_no_login_account_before_approval(): void
    {
        [$admin, $branch, $project, $coordinator] = $this->proposalContext();
        $this->actingAs($admin)->post(route('admin-users.sales-proposals.store'), [
            ...$this->proposalPayload($branch, $project, $coordinator),
            'email' => 'pending.sales@example.test',
        ])->assertRedirect();

        $this->post('/logout');
        $this->post('/login', ['email' => 'pending.sales@example.test', 'password' => 'Marison123'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'pending.sales@example.test']);
    }

    public function test_non_superadmin_cannot_approve_a_pending_sales_proposal(): void
    {
        [$admin, $branch, $project, $coordinator] = $this->proposalContext();
        $this->actingAs($admin)->post(route('admin-users.sales-proposals.store'), [
            ...$this->proposalPayload($branch, $project, $coordinator),
            'email' => 'denied.approval@example.test',
        ])->assertRedirect();
        $proposal = SalesOnboardingProposal::firstOrFail();

        $this->actingAs($admin)->post(route('admin-users.sales-proposals.approve', $proposal), [
            'decision_token' => $proposal->decision_token,
        ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'denied.approval@example.test']);
        $this->assertSame(SalesOnboardingProposal::STATUS_PENDING, $proposal->fresh()->status);
    }

    public function test_admin_cannot_use_general_user_creation_to_bypass_sales_proposal_approval(): void
    {
        [$admin, $branch, $project, $coordinator] = $this->proposalContext();
        $sales = Role::where('slug', 'sales')->firstOrFail();

        $this->actingAs($admin)->post(route('admin-users.store'), [
            'name' => 'Bypassed Sales',
            'email' => 'bypassed.sales@example.test',
            'role_id' => $sales->id,
            'branch_id' => $branch->id,
            'branch_ids' => [$branch->id],
            'primary_project_id' => $project->id,
            'assigned_project_ids' => [$project->id],
            'supervisor_user_id' => $coordinator->id,
            'temporary_password' => 'Initial123',
            'temporary_password_confirmation' => 'Initial123',
        ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'bypassed.sales@example.test']);
    }

    public function test_superadmin_approval_provisions_sales_with_assignments_and_without_exposing_password(): void
    {
        Notification::fake();
        [$admin, $branch, $project, $coordinator] = $this->proposalContext();
        $this->actingAs($admin)->post(route('admin-users.sales-proposals.store'), [
            ...$this->proposalPayload($branch, $project, $coordinator),
            'email' => 'approved.sales@example.test',
        ])->assertRedirect();
        $proposal = SalesOnboardingProposal::firstOrFail();
        $superadmin = $this->user('superadmin', null, 'Superadmin');

        $response = $this->actingAs($superadmin)->post(route('admin-users.sales-proposals.approve', $proposal), [
            'decision_token' => $proposal->decision_token,
        ]);

        $response->assertRedirect(route('admin-users.sales-proposals.index'));
        $user = User::where('email', 'approved.sales@example.test')->firstOrFail();
        $proposal->refresh();

        $this->assertSame(SalesOnboardingProposal::STATUS_APPROVED, $proposal->status);
        $this->assertSame(AccountStatus::Active, $user->account_status);
        $this->assertTrue($user->is_active);
        $this->assertTrue($user->must_change_password);
        $this->assertNull($user->password_changed_at);
        $this->assertTrue(Hash::check('Marison123', $user->password));
        $this->assertTrue($user->branches()->whereKey($branch->id)->exists());
        $this->assertTrue($user->assignedProjects()->whereKey($project->id)->wherePivot('is_primary', true)->wherePivot('is_active', true)->exists());
        $this->assertDatabaseHas('sales_coordinator_sales', [
            'sales_user_id' => $user->id,
            'coordinator_user_id' => $coordinator->id,
            'is_active' => true,
        ]);
        $this->assertDatabaseCount('user_invitations', 0);
        Notification::assertNothingSent();
        $this->assertStringNotContainsString('Marison123', $response->getContent());
        $this->assertStringNotContainsString('Marison123', $user->activityLogs()->get()->toJson());
        $this->assertStringNotContainsString('Marison123', $proposal->toJson());
        $this->assertStringNotContainsString('Marison123', file_get_contents(resource_path('views/crm/admin-users/sales-proposals.blade.php')));
    }

    public function test_sales_onboarding_changelog_is_deployed_once(): void
    {
        $this->assertSame(1, DB::table('changelogs')->whereNull('version')->where('title', 'Persetujuan onboarding Sales')->count());
    }

    /** @return array{0: User, 1: Branch, 2: LeadMaster, 3: User} */
    private function proposalContext(): array
    {
        $branch = $this->branch('LOCAL');
        $project = $this->project($branch, 'Local Project');
        $admin = $this->user('admin', $branch, 'Local Admin');
        $coordinator = $this->user('sales_coordinator', $branch, 'Local Coordinator');

        return [$admin, $branch, $project, $coordinator];
    }

    private function proposalPayload(Branch $branch, LeadMaster $project, User $coordinator): array
    {
        return [
            'name' => 'Proposed Sales',
            'email' => 'proposed.sales@example.test',
            'phone' => '08123456789',
            'role_id' => Role::where('slug', 'sales')->value('id'),
            'branch_id' => $branch->id,
            'project_id' => $project->id,
            'coordinator_user_id' => $coordinator->id,
        ];
    }

    private function user(string $roleSlug, ?Branch $branch, string $name): User
    {
        return User::factory()->create([
            'name' => $name,
            'email' => str($name)->slug('-').'@example.test',
            'role_id' => Role::where('slug', $roleSlug)->value('id'),
            'branch_id' => $branch?->id,
            'account_status' => AccountStatus::Active,
            'password_changed_at' => now(),
        ]);
    }

    private function branch(string $code): Branch
    {
        return Branch::create(['name' => "Cabang {$code}", 'code' => $code, 'is_active' => true]);
    }

    private function project(Branch $branch, string $name): LeadMaster
    {
        return LeadMaster::create(['branch_id' => $branch->id, 'project_name' => $name, 'is_active' => true]);
    }
}
