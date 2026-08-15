<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ContentItem;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesAccessRestrictionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeGoogleSheets();
    }

    public function test_sales_can_access_only_primary_modules_and_required_shared_endpoints(): void
    {
        [$branch, , $sales] = $this->salesContext();

        $this->actingAs($sales)->get(route('sales-pocketbook.index'))->assertOk();
        $this->actingAs($sales)->get(route('content-calendar.index'))->assertOk();
        $this->actingAs($sales)->get(route('profile.edit'))->assertOk();
        $this->actingAs($sales)->getJson(route('notifications.index'))->assertOk();
        $this->actingAs($sales)->getJson(route('presence.index', [
            'page_key' => 'sales-pocketbook',
            'branch_id' => $branch->id,
        ]))->assertOk();
        $this->actingAs($sales)->getJson(route('feedback-reports.history'))->assertOk();
    }

    public function test_sales_direct_urls_to_unrelated_modules_return_forbidden(): void
    {
        [, , $sales] = $this->salesContext();
        $blocked = [
            'dashboard',
            'database.index',
            'konsumen-progress.index',
            'dana-talangan.index',
            'lead-sources.index',
            'changelogs.index',
            'ai-chat.index',
            'feedback-reports.index',
            'branches.index',
            'projects.index',
            'admin-users.index',
            'admin.system-health',
            'admin.design-system',
        ];

        foreach ($blocked as $routeName) {
            $this->actingAs($sales)->get(route($routeName))->assertForbidden();
        }

        $this->actingAs($sales)->postJson(route('database.sync'))->assertForbidden();
        $this->actingAs($sales)->getJson(route('konsumen-progress.stage'))->assertForbidden();
        $this->actingAs($sales)->getJson(route('dana-talangan.sync-status'))->assertForbidden();
        $this->actingAs($sales)->getJson(route('presence.index', [
            'page_key' => 'database',
        ]))->assertForbidden();
    }

    public function test_sales_navigation_contains_only_allowed_modules_and_account_controls(): void
    {
        [, , $sales] = $this->salesContext();
        $response = $this->actingAs($sales)->get(route('sales-pocketbook.index'))->assertOk();

        $response->assertSee(route('sales-pocketbook.index'), false)
            ->assertSee(route('content-calendar.index'), false)
            ->assertSee(route('profile.edit'), false)
            ->assertSee(route('logout'), false)
            ->assertDontSee(route('dashboard'), false)
            ->assertDontSee(route('database.index'), false)
            ->assertDontSee(route('konsumen-progress.index'), false)
            ->assertDontSee(route('dana-talangan.index'), false)
            ->assertDontSee(route('changelogs.index'), false)
            ->assertDontSee('Oasis AI - Asisten Magang');
    }

    public function test_non_sales_dashboard_and_navigation_remain_available(): void
    {
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);
        foreach (['admin', 'manager', 'pusat', 'superadmin'] as $slug) {
            $user = $this->user($slug, $branch);
            $response = $this->actingAs($user)->get(route('dashboard'))->assertOk();
            $response->assertSee(route('dashboard'), false)
                ->assertSee(route('database.index'), false)
                ->assertSee(route('dana-talangan.index'), false);
        }
    }

    public function test_supplemental_sales_role_does_not_restrict_primary_manager(): void
    {
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);
        $manager = $this->user('manager', $branch);
        $salesRole = Role::firstOrCreate(['slug' => 'sales'], ['name' => 'Sales', 'is_superadmin' => false]);
        $manager->roles()->attach($salesRole);

        $this->assertTrue($manager->hasRole('sales'));
        $this->assertFalse($manager->isSales());
        $this->actingAs($manager)->get(route('dashboard'))->assertOk();
        $this->actingAs($manager)->get(route('database.index'))->assertOk();
        $this->actingAs($manager)->get(route('sales-pocketbook.index'))->assertOk()
            ->assertViewHas('monitoring', true);
    }

    public function test_supplemental_sales_role_does_not_grant_primary_staff_pocketbook_access(): void
    {
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);
        $staff = $this->user('staff', $branch);
        $salesRole = Role::firstOrCreate(['slug' => 'sales'], ['name' => 'Sales', 'is_superadmin' => false]);
        $staff->roles()->attach($salesRole);

        $this->assertTrue($staff->hasRole('sales'));
        $this->assertFalse($staff->isSales());
        $this->actingAs($staff)->get(route('sales-pocketbook.index'))->assertForbidden();
        $this->actingAs($staff)->post(route('sales-agendas.store'))->assertForbidden();
    }

    public function test_authenticated_sales_guest_redirect_and_notification_feed_respect_allowlist(): void
    {
        [, , $sales] = $this->salesContext();
        UserNotification::create([
            'user_id' => $sales->id,
            'type' => 'record_updated',
            'title' => 'Database berubah',
            'message' => 'Rahasia Database',
            'action_url' => route('database.index'),
            'related_type' => 'database_sheet_record',
            'related_id' => 99,
        ]);
        UserNotification::create([
            'user_id' => $sales->id,
            'type' => 'record_updated',
            'title' => 'Lead berubah',
            'message' => 'Lead Buku Saku diperbarui',
            'action_url' => route('sales-pocketbook.index'),
            'related_type' => 'sales_lead',
            'related_id' => 1,
        ]);
        $feedback = UserNotification::create([
            'user_id' => $sales->id,
            'type' => 'feedback_status_changed',
            'title' => 'Status laporan',
            'message' => 'Laporan diperbarui',
            'action_url' => route('dashboard'),
            'related_type' => 'FeedbackReport',
            'related_id' => 2,
        ]);

        $this->actingAs($sales)->get(route('login'))->assertRedirect(route('sales-pocketbook.index'));
        $response = $this->actingAs($sales)->getJson(route('notifications.index'))->assertOk()
            ->assertJsonPath('unread_count', 2)
            ->assertJsonCount(2, 'notifications');
        $response->assertJsonMissing(['message' => 'Rahasia Database'])
            ->assertJsonMissing(['action_url' => route('dashboard')]);
        $this->actingAs($sales)->postJson(route('notifications.read-all'))->assertOk();
        $this->assertNull(UserNotification::where('message', 'Rahasia Database')->sole()->read_at);
        $this->assertNotNull($feedback->fresh()->read_at);
    }

    public function test_sales_work_planner_edit_scope_is_creator_or_assignee_only(): void
    {
        [$branch, , $sales] = $this->salesContext();
        $other = $this->user('sales', $branch, 'Other Sales');
        $teamItem = $this->item($branch, $other, ['visibility' => 'team', 'title' => 'Team Visible']);
        $assignedItem = $this->item($branch, $other, ['visibility' => 'personal', 'title' => 'Assigned']);
        $assignedItem->assignees()->attach($sales);
        $otherBranch = Branch::create(['name' => 'Pati', 'code' => 'PTI', 'is_active' => true]);
        $crossBranch = $this->item($otherBranch, $other, ['visibility' => 'team', 'title' => 'Cross Branch']);

        $this->actingAs($sales)->getJson(route('content-calendar.detail', $teamItem))->assertOk();
        $this->actingAs($sales)->getJson(route('content-calendar.detail', $crossBranch))->assertForbidden();
        $this->actingAs($sales)->get(route('content-calendar.edit', $teamItem))->assertForbidden();
        $this->actingAs($sales)->delete(route('content-calendar.destroy', $teamItem))->assertForbidden();
        $this->actingAs($sales)->get(route('content-calendar.edit', $assignedItem))->assertOk();
    }

    private function salesContext(): array
    {
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Solo Project', 'is_active' => true]);
        $sales = $this->user('sales', $branch, 'Solo Sales');
        $sales->assignedProjects()->attach($project, ['is_primary' => true]);

        return [$branch, $project, $sales];
    }

    private function user(string $slug, ?Branch $branch = null, ?string $name = null): User
    {
        $role = Role::firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug), 'is_superadmin' => $slug === 'superadmin']);

        return User::factory()->create([
            'name' => $name ?? ucfirst($slug),
            'role_id' => $role->id,
            'branch_id' => $branch?->id,
            'password_changed_at' => now(),
        ]);
    }

    private function item(Branch $branch, User $creator, array $overrides = []): ContentItem
    {
        return ContentItem::create(array_merge([
            'branch_id' => $branch->id,
            'item_type' => 'task',
            'visibility' => 'personal',
            'title' => 'Planner Item',
            'scheduled_date' => today(),
            'deadline_date' => today(),
            'status' => 'todo',
            'created_by' => $creator->id,
        ], $overrides));
    }
}
