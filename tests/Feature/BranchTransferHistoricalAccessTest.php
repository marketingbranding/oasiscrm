<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
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
use App\Services\SalesLeadService;
use App\Services\WorkspaceAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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

    public function test_transfer_preserves_an_omitted_secondary_membership_as_historical(): void
    {
        $oldBranch = $this->branch('OLD');
        $secondaryBranch = $this->branch('SEC');
        $newBranch = $this->branch('NEW');
        $sales = $this->user('sales', $oldBranch);
        $superadmin = $this->user('superadmin');
        $oldProject = LeadMaster::create(['branch_id' => $oldBranch->id, 'project_name' => 'Proyek Lama', 'is_active' => true]);
        $newProject = LeadMaster::create(['branch_id' => $newBranch->id, 'project_name' => 'Proyek Baru', 'is_active' => true]);
        $sales->assignedProjects()->attach($oldProject->id, ['is_primary' => true, 'is_active' => true]);
        $sales->branches()->attach($secondaryBranch->id, [
            'is_current' => true,
            'can_view' => true,
            'can_edit' => true,
            'can_sync' => true,
            'can_manage_members' => true,
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

        $membership = DB::table('branch_user')->where('user_id', $sales->id)->where('branch_id', $secondaryBranch->id)->first();

        $this->assertNotNull($membership);
        $this->assertFalse((bool) $membership->is_current);
        $this->assertTrue((bool) $membership->can_view);
        $this->assertFalse((bool) $membership->can_edit);
        $this->assertFalse((bool) $membership->can_sync);
        $this->assertFalse((bool) $membership->can_manage_members);
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

    public function test_original_branch_admin_and_branch_manager_can_update_historical_lead_through_http_without_reassigning_owner(): void
    {
        [$sales, $oldBranch, , $lead] = $this->transferredSalesLead();
        $oldProject = LeadMaster::query()->where('branch_id', $oldBranch->id)->firstOrFail();

        foreach (['admin', 'branch_manager'] as $role) {
            $operator = $this->user($role, $oldBranch);
            $response = $this->actingAs($operator)->get(route('sales-leads.edit', $lead));
            $response->assertSee($sales->name)
                ->assertSee('id="edit-lead-branch"', false)
                ->assertSee('id="edit-lead-project"', false)
                ->assertSee('id="edit-lead-sales"', false)
                ->assertSee('pemilik historis', false)
                ->assertSee('lead historis, baca-saja', false)
                ->assertSee('name="branch_id" value="'.$oldBranch->id.'"', false)
                ->assertSee('name="project_id" value="'.$lead->project_id.'"', false)
                ->assertSee('disabled', false);

            $this->actingAs($operator)->put(route('sales-leads.update', $lead), [
                'branch_id' => $oldBranch->id,
                'project_id' => $oldProject->id,
                'sales_user_id' => $sales->id,
                'lead_date' => $lead->lead_date->toDateString(),
                'customer_name' => "Lead Historis Diperbarui {$role}",
                'phone' => $lead->phone,
                'source' => 'Referral',
                'platform' => 'WhatsApp',
                'campaign_name' => 'Follow Up Data Lama',
                'current_status' => 'no_response',
                'operation_uuid' => (string) Str::uuid(),
                'expected_updated_at' => app(OptimisticLockService::class)->token($lead),
            ])->assertRedirect()->assertSessionHasNoErrors();

            $lead->refresh();
            $this->assertSame($sales->id, $lead->sales_user_id);
            $this->assertSame("Lead Historis Diperbarui {$role}", $lead->customer_name);
        }
    }

    public function test_historical_edit_shows_inactive_owner_as_labeled_read_only_value_without_offering_other_inactive_sales(): void
    {
        [$sales, $oldBranch, , $lead] = $this->transferredSalesLead();
        $oldProject = LeadMaster::query()->where('branch_id', $oldBranch->id)->firstOrFail();
        $inactiveAlternative = $this->user('sales', $oldBranch);
        $inactiveAlternative->assignedProjects()->attach($oldProject->id, ['is_primary' => true, 'is_active' => true]);
        $inactiveAlternative->forceFill(['name' => 'Sales Tidak Aktif Lain', 'account_status' => AccountStatus::Inactive, 'is_active' => false])->saveQuietly();
        $sales->forceFill(['name' => 'Pemilik Historis Nonaktif', 'account_status' => AccountStatus::Inactive, 'is_active' => false])->saveQuietly();
        $operator = $this->user('admin', $oldBranch);

        $this->actingAs($operator)->get(route('sales-leads.edit', $lead))
            ->assertSee('Pemilik Historis Nonaktif')
            ->assertSee('pemilik historis, nonaktif', false)
            ->assertSee('name="sales_user_id" value="'.$sales->id.'"', false)
            ->assertDontSee('Sales Tidak Aktif Lain');
    }

    public function test_historical_http_edit_rejects_reassignment_to_a_new_sales_owner(): void
    {
        [$sales, $oldBranch, , $lead] = $this->transferredSalesLead();
        $oldProject = LeadMaster::query()->where('branch_id', $oldBranch->id)->firstOrFail();
        $newOwner = $this->user('sales', $oldBranch);
        $newOwner->assignedProjects()->attach($oldProject->id, ['is_primary' => true, 'is_active' => true]);
        $operator = $this->user('admin', $oldBranch);

        $this->actingAs($operator)->put(route('sales-leads.update', $lead), [
            'branch_id' => $oldBranch->id,
            'project_id' => $oldProject->id,
            'sales_user_id' => $newOwner->id,
            'lead_date' => $lead->lead_date->toDateString(),
            'customer_name' => 'Tidak Boleh Dipindahkan',
            'phone' => $lead->phone,
            'source' => 'Referral',
            'platform' => 'WhatsApp',
            'campaign_name' => 'Follow Up Data Lama',
            'current_status' => 'no_response',
            'operation_uuid' => (string) Str::uuid(),
            'expected_updated_at' => app(OptimisticLockService::class)->token($lead),
        ])->assertSessionHasErrors('sales_user_id');

        $this->assertDatabaseHas('sales_leads', [
            'id' => $lead->id,
            'sales_user_id' => $sales->id,
            'customer_name' => 'Konsumen Lama',
        ]);
    }

    public function test_historical_http_edit_rejects_reassignment_to_a_new_branch(): void
    {
        [$sales, $oldBranch, $newBranch, $lead] = $this->transferredSalesLead();
        $oldProject = LeadMaster::query()->where('branch_id', $oldBranch->id)->firstOrFail();
        $operator = $this->user('admin', $oldBranch);

        $payload = $this->historicalLeadPayload($lead, $sales, $oldBranch, $oldProject);
        $payload['branch_id'] = $newBranch->id;

        $this->actingAs($operator)->put(route('sales-leads.update', $lead), $payload)
            ->assertSessionHasErrors('branch_id');

        $this->assertDatabaseHas('sales_leads', [
            'id' => $lead->id,
            'branch_id' => $oldBranch->id,
            'project_id' => $oldProject->id,
            'sales_user_id' => $sales->id,
            'customer_name' => 'Konsumen Lama',
            'current_status' => 'no_response',
        ]);
    }

    public function test_historical_http_edit_rejects_reassignment_to_a_new_project(): void
    {
        [$sales, $oldBranch, , $lead] = $this->transferredSalesLead();
        $oldProject = LeadMaster::query()->where('branch_id', $oldBranch->id)->firstOrFail();
        $newProject = LeadMaster::create([
            'branch_id' => $oldBranch->id,
            'project_name' => 'Proyek Lama Lainnya',
            'is_active' => true,
        ]);
        $operator = $this->user('branch_manager', $oldBranch);

        $payload = $this->historicalLeadPayload($lead, $sales, $oldBranch, $oldProject);
        $payload['project_id'] = $newProject->id;

        $this->actingAs($operator)->put(route('sales-leads.update', $lead), $payload)
            ->assertSessionHasErrors('project_id');

        $this->assertDatabaseHas('sales_leads', [
            'id' => $lead->id,
            'branch_id' => $oldBranch->id,
            'project_id' => $oldProject->id,
            'sales_user_id' => $sales->id,
            'customer_name' => 'Konsumen Lama',
            'current_status' => 'no_response',
        ]);
    }

    public function test_historical_http_edit_rejects_a_lifecycle_status_change(): void
    {
        [$sales, $oldBranch, , $lead] = $this->transferredSalesLead();
        $oldProject = LeadMaster::query()->where('branch_id', $oldBranch->id)->firstOrFail();
        $operator = $this->user('admin', $oldBranch);

        $payload = $this->historicalLeadPayload($lead, $sales, $oldBranch, $oldProject);
        $payload['current_status'] = 'discussion';

        $this->actingAs($operator)->put(route('sales-leads.update', $lead), $payload)
            ->assertSessionHasErrors('current_status');

        $this->assertDatabaseHas('sales_leads', [
            'id' => $lead->id,
            'branch_id' => $oldBranch->id,
            'project_id' => $oldProject->id,
            'sales_user_id' => $sales->id,
            'customer_name' => 'Konsumen Lama',
            'current_status' => 'no_response',
        ]);
    }

    public function test_historical_service_rejects_forged_identity_and_status_changes_after_locking(): void
    {
        [$sales, $oldBranch, $newBranch, $lead] = $this->transferredSalesLead();
        $oldProject = LeadMaster::query()->where('branch_id', $oldBranch->id)->firstOrFail();
        $newProject = LeadMaster::create([
            'branch_id' => $oldBranch->id,
            'project_name' => 'Proyek Lama Lainnya',
            'is_active' => true,
        ]);
        $newOwner = $this->user('sales', $oldBranch);
        $newOwner->assignedProjects()->attach($oldProject->id, ['is_primary' => true, 'is_active' => true]);
        $operator = $this->user('admin', $oldBranch);
        $service = app(SalesLeadService::class);
        $payload = $this->historicalLeadPayload($lead, $sales, $oldBranch, $oldProject);

        foreach ([
            ['field' => 'branch_id', 'value' => $newBranch->id],
            ['field' => 'project_id', 'value' => $newProject->id],
            ['field' => 'sales_user_id', 'value' => $newOwner->id],
            ['field' => 'current_status', 'value' => 'discussion'],
        ] as $forged) {
            try {
                $service->update($lead->fresh(), array_replace($payload, [
                    $forged['field'] => $forged['value'],
                ]), $operator);
                $this->fail('Historical lead identity was changed through the service.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($forged['field'], $exception->errors());
            }

            $this->assertDatabaseHas('sales_leads', [
                'id' => $lead->id,
                'branch_id' => $oldBranch->id,
                'project_id' => $oldProject->id,
                'sales_user_id' => $sales->id,
                'customer_name' => 'Konsumen Lama',
                'current_status' => 'no_response',
            ]);
        }
    }

    public function test_original_branch_admin_and_branch_manager_cannot_run_historical_lifecycle_mutations(): void
    {
        [, $oldBranch, , $lead] = $this->transferredSalesLead();
        $policy = app(SalesLeadPolicy::class);

        foreach (['admin', 'branch_manager'] as $role) {
            $operator = $this->user($role, $oldBranch);

            $this->assertFalse($policy->updateStage($operator, $lead), $role.' updateStage');
            $this->assertFalse($policy->reverseStage($operator, $lead), $role.' reverseStage');
            $this->assertFalse($policy->updateLifecycleStatus($operator, $lead), $role.' updateLifecycleStatus');
            $this->assertFalse($policy->recordSiteVisit($operator, $lead), $role.' recordSiteVisit');
            $this->assertFalse($policy->markUtjDirect($operator, $lead), $role.' markUtjDirect');
            $this->assertFalse($policy->convertToConsumer($operator, $lead), $role.' convertToConsumer');
            $this->assertFalse($policy->submitToSlik($operator, $lead), $role.' submitToSlik');
            $this->assertFalse($policy->markSlikRejected($operator, $lead), $role.' markSlikRejected');
            $this->assertFalse($policy->convertToFreelance($operator, $lead), $role.' convertToFreelance');
        }
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

    public function test_historical_team_content_is_not_visible_when_transferred_user_does_not_own_it(): void
    {
        $oldBranch = $this->branch('OLD');
        $newBranch = $this->branch('NEW');
        $owner = $this->user('staff', $oldBranch);
        $transferred = $this->user('staff', $oldBranch);
        $superadmin = $this->user('superadmin');
        $item = ContentItem::create([
            'branch_id' => $oldBranch->id,
            'item_type' => 'task',
            'visibility' => 'team',
            'title' => 'Konten tim historis',
            'scheduled_date' => today(),
            'deadline_date' => today(),
            'priority' => 'medium',
            'status' => 'todo',
            'created_by' => $owner->id,
        ]);

        $this->actingAs($superadmin)->put(route('admin-users.update', $transferred), [
            'name' => $transferred->name,
            'email' => $transferred->email,
            'role_id' => $transferred->role_id,
            'branch_id' => $newBranch->id,
            'branch_ids' => [$newBranch->id],
            'expected_updated_at' => app(OptimisticLockService::class)->token($transferred),
        ])->assertRedirect();

        $this->assertFalse(app(ContentItemPolicy::class)->view($transferred->fresh(), $item));
        $this->assertSame(0, ContentItem::query()->visibleTo($transferred->fresh())->whereKey($item)->count());
    }

    public function test_manage_user_scope_excludes_users_with_only_historical_branch_memberships(): void
    {
        $oldBranch = $this->branch('OLD');
        $newBranch = $this->branch('NEW');
        $transferred = $this->user('staff', $oldBranch);
        $manager = $this->user('branch_manager', $oldBranch);
        $superadmin = $this->user('superadmin');

        $this->actingAs($superadmin)->put(route('admin-users.update', $transferred), [
            'name' => $transferred->name,
            'email' => $transferred->email,
            'role_id' => $transferred->role_id,
            'branch_id' => $newBranch->id,
            'branch_ids' => [$newBranch->id],
            'expected_updated_at' => app(OptimisticLockService::class)->token($transferred),
        ])->assertRedirect();

        $visibleIds = app(OrganizationScopeService::class)->visibleUserIds($manager, 'work_planner', 'manage');

        $this->assertNotContains($transferred->id, $visibleIds);
    }

    public function test_current_state_migration_preserves_existing_membership_count_and_current_flags(): void
    {
        $first = $this->branch('ONE');
        $second = $this->branch('TWO');
        $user = $this->user('staff', $first);
        $user->branches()->attach($second->id, ['can_view' => true, 'can_edit' => true]);

        $memberships = DB::table('branch_user')->where('user_id', $user->id)->orderBy('branch_id')->get();

        $this->assertCount(2, $memberships);
        $this->assertTrue($memberships->every(fn (object $membership): bool => (bool) $membership->is_current));
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

    /** @return array<string, mixed> */
    private function historicalLeadPayload(SalesLead $lead, User $sales, Branch $branch, LeadMaster $project): array
    {
        return [
            'branch_id' => $branch->id,
            'project_id' => $project->id,
            'sales_user_id' => $sales->id,
            'lead_date' => $lead->lead_date->toDateString(),
            'customer_name' => 'Konsumen Lama',
            'phone' => $lead->phone,
            'source' => 'Referral',
            'platform' => 'WhatsApp',
            'campaign_name' => 'Follow Up Data Lama',
            'current_status' => 'no_response',
            'operation_uuid' => (string) Str::uuid(),
            'expected_updated_at' => app(OptimisticLockService::class)->token($lead),
        ];
    }
}
