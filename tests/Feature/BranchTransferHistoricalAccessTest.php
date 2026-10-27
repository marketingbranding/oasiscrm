<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ContentItem;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\SalesLead;
use App\Models\User;
use App\Policies\ContentItemPolicy;
use App\Policies\SalesLeadPolicy;
use App\Services\OptimisticLockService;
use App\Services\OrganizationScopeService;
use App\Services\WorkspaceAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BranchTransferHistoricalAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_transfer_preserves_lead_ownership_and_marks_previous_branch_historical(): void
    {
        $oldBranch = $this->branch('OLD');
        $newBranch = $this->branch('NEW');
        $sales = $this->user('sales', $oldBranch);
        $superadmin = $this->user('superadmin');
        $project = LeadMaster::create(['branch_id' => $oldBranch->id, 'project_name' => 'Proyek Lama', 'is_active' => true]);
        $sales->assignedProjects()->attach($project->id, ['is_primary' => true, 'is_active' => true]);
        $newProject = LeadMaster::create(['branch_id' => $newBranch->id, 'project_name' => 'Proyek Baru', 'is_active' => true]);
        $lead = SalesLead::create([
            'branch_id' => $oldBranch->id,
            'project_id' => $project->id,
            'sales_user_id' => $sales->id,
            'lead_date' => today(),
            'customer_name' => 'Konsumen Lama',
            'phone' => '081234567890',
            'current_status' => 'no_response',
        ]);

        $this->actingAs($superadmin)->put(route('admin-users.update', $sales), [
            'name' => $sales->name,
            'email' => $sales->email,
            'role_id' => $sales->role_id,
            'branch_id' => $newBranch->id,
            'branch_ids' => [$newBranch->id],
            'primary_project_id' => $newProject->id,
            'assigned_project_ids' => [$newProject->id],
            'expected_updated_at' => app(OptimisticLockService::class)->token($sales),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $oldMembership = DB::table('branch_user')->where('user_id', $sales->id)->where('branch_id', $oldBranch->id)->first();
        $newMembership = DB::table('branch_user')->where('user_id', $sales->id)->where('branch_id', $newBranch->id)->first();

        $this->assertSame($newBranch->id, $sales->fresh()->branch_id);
        $this->assertFalse((bool) $oldMembership->is_current, json_encode((array) $oldMembership));
        $this->assertTrue((bool) $oldMembership->can_view);
        $this->assertFalse((bool) $oldMembership->can_edit);
        $this->assertFalse((bool) $oldMembership->can_sync);
        $this->assertFalse((bool) $oldMembership->can_manage_members);
        $this->assertTrue((bool) $newMembership->is_current);
        $this->assertSame($oldBranch->id, $lead->fresh()->branch_id);
        $this->assertSame($sales->id, $lead->fresh()->sales_user_id);
        $this->assertDatabaseHas('activity_log', ['subject_id' => $sales->id, 'event' => 'branch_assignments_changed']);
    }

    public function test_non_superadmin_cannot_transfer_a_user_between_branches(): void
    {
        $oldBranch = $this->branch('OLD');
        $newBranch = $this->branch('NEW');
        $admin = $this->user('admin', $oldBranch);
        $target = $this->user('sales', $oldBranch);

        $this->actingAs($admin)->put(route('admin-users.update', $target), [
            'name' => $target->name,
            'email' => $target->email,
            'role_id' => $target->role_id,
            'branch_id' => $newBranch->id,
            'branch_ids' => [$newBranch->id],
            'expected_updated_at' => app(OptimisticLockService::class)->token($target),
        ])->assertForbidden();

        $this->assertSame($oldBranch->id, $target->fresh()->branch_id);
        $this->assertDatabaseMissing('branch_user', ['user_id' => $target->id, 'branch_id' => $newBranch->id]);
    }

    public function test_transferred_sales_user_can_view_owned_historical_lead_but_cannot_edit_or_sync_it(): void
    {
        [$sales, $oldBranch, $newBranch, $lead] = $this->transferredSalesLead();
        $access = app(WorkspaceAccessService::class);
        $scope = app(OrganizationScopeService::class);

        $this->assertTrue(app(SalesLeadPolicy::class)->view($sales, $lead));
        $this->assertFalse(app(SalesLeadPolicy::class)->update($sales, $lead));
        $this->assertNotContains($oldBranch->id, $access->accessibleBranchIds($sales));
        $this->assertContains($oldBranch->id, $access->historicalBranchIds($sales));
        $this->assertFalse($access->canSyncBranch($sales, $oldBranch));
        $this->assertContains($newBranch->id, $scope->branchIds($sales, 'sales_pocketbook', 'view'));
        $this->assertNotContains($oldBranch->id, $scope->branchIds($sales, 'sales_pocketbook', 'sync'));
    }

    public function test_original_branch_admin_and_branch_manager_can_edit_historical_lead(): void
    {
        [$sales, $oldBranch, , $lead] = $this->transferredSalesLead();

        foreach (['admin', 'branch_manager'] as $role) {
            $operator = $this->user($role, $oldBranch);

            $this->assertTrue(app(SalesLeadPolicy::class)->update($operator, $lead), $role);
        }

        $this->assertSame($sales->id, $lead->fresh()->sales_user_id);
    }

    public function test_historical_content_owned_by_transferred_user_is_view_only(): void
    {
        $oldBranch = $this->branch('OLD');
        $newBranch = $this->branch('NEW');
        $owner = $this->user('staff', $oldBranch);
        $superadmin = $this->user('superadmin');
        $item = ContentItem::create([
            'branch_id' => $oldBranch->id,
            'item_type' => 'task',
            'visibility' => 'personal',
            'title' => 'Tugas historis',
            'scheduled_date' => today(),
            'deadline_date' => today(),
            'priority' => 'medium',
            'status' => 'todo',
            'created_by' => $owner->id,
        ]);

        $this->actingAs($superadmin)->put(route('admin-users.update', $owner), [
            'name' => $owner->name,
            'email' => $owner->email,
            'role_id' => $owner->role_id,
            'branch_id' => $newBranch->id,
            'branch_ids' => [$newBranch->id],
            'expected_updated_at' => app(OptimisticLockService::class)->token($owner),
        ])->assertRedirect();

        $policy = app(ContentItemPolicy::class);

        $this->assertTrue($policy->view($owner->fresh(), $item));
        $this->assertFalse($policy->update($owner->fresh(), $item));
    }

    public function test_returning_to_historical_branch_does_not_restore_sales_edit_access(): void
    {
        [$sales, $oldBranch, $newBranch, $lead] = $this->transferredSalesLead();
        $superadmin = $this->user('superadmin');
        $oldProject = LeadMaster::query()->where('branch_id', $oldBranch->id)->firstOrFail();

        $this->actingAs($superadmin)->put(route('admin-users.update', $sales), [
            'name' => $sales->name,
            'email' => $sales->email,
            'role_id' => $sales->role_id,
            'branch_id' => $oldBranch->id,
            'branch_ids' => [$oldBranch->id],
            'primary_project_id' => $oldProject->id,
            'assigned_project_ids' => [$oldProject->id],
            'expected_updated_at' => app(OptimisticLockService::class)->token($sales),
        ])->assertRedirect();

        $sales->refresh();
        $oldMembership = DB::table('branch_user')->where('user_id', $sales->id)->where('branch_id', $oldBranch->id)->first();
        $this->assertSame($oldBranch->id, $sales->branch_id);
        $this->assertFalse((bool) $oldMembership->is_current);
        $this->assertTrue(app(SalesLeadPolicy::class)->view($sales, $lead));
        $this->assertFalse(app(SalesLeadPolicy::class)->update($sales, $lead));
        $this->assertNotContains($oldBranch->id, app(WorkspaceAccessService::class)->accessibleBranchIds($sales));
        $this->assertContains($newBranch->id, app(WorkspaceAccessService::class)->historicalBranchIds($sales));
    }

    /** @return array{0: User, 1: Branch, 2: Branch, 3: SalesLead} */
    private function transferredSalesLead(): array
    {
        $oldBranch = $this->branch('OLD');
        $newBranch = $this->branch('NEW');
        $sales = $this->user('sales', $oldBranch);
        $superadmin = $this->user('superadmin');
        $project = LeadMaster::create(['branch_id' => $oldBranch->id, 'project_name' => 'Proyek Lama', 'is_active' => true]);
        $sales->assignedProjects()->attach($project->id, ['is_primary' => true, 'is_active' => true]);
        $newProject = LeadMaster::create(['branch_id' => $newBranch->id, 'project_name' => 'Proyek Baru', 'is_active' => true]);
        $lead = SalesLead::create([
            'branch_id' => $oldBranch->id,
            'project_id' => $project->id,
            'sales_user_id' => $sales->id,
            'lead_date' => today(),
            'customer_name' => 'Konsumen Lama',
            'phone' => '081234567890',
            'current_status' => 'no_response',
        ]);

        $this->actingAs($superadmin)->put(route('admin-users.update', $sales), [
            'name' => $sales->name,
            'email' => $sales->email,
            'role_id' => $sales->role_id,
            'branch_id' => $newBranch->id,
            'branch_ids' => [$newBranch->id],
            'primary_project_id' => $newProject->id,
            'assigned_project_ids' => [$newProject->id],
            'expected_updated_at' => app(OptimisticLockService::class)->token($sales),
        ])->assertRedirect();

        return [$sales->fresh(), $oldBranch, $newBranch, $lead->fresh()];
    }

    private function user(string $roleSlug, ?Branch $branch = null): User
    {
        return User::factory()->create([
            'role_id' => Role::where('slug', $roleSlug)->firstOrFail()->id,
            'branch_id' => $branch?->id,
            'password_changed_at' => now(),
        ]);
    }

    private function branch(string $code): Branch
    {
        return Branch::create(['name' => "Cabang {$code}", 'code' => $code, 'is_active' => true]);
    }
}
