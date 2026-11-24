<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Models\ConsumerApplication;
use App\Models\LeadMaster;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SalesLead;
use App\Models\User;
use App\Services\ConsumerLeadLinkSuggestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class ConsumerLeadLinkSuggestionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_br_link_suggestion_scores_phone_name_project_sales_and_chronology_without_writing(): void
    {
        $viewer = $this->superadmin();
        $consumer = ConsumerApplication::factory()->create([
            'nama_konsumen' => 'Budi Santoso',
            'booking_date' => '2026-11-20',
        ]);
        $consumer->customer()->update(['name' => 'Budi Santoso', 'phone' => '0812-3456-7890']);
        $consumer->refresh();
        $sales = User::factory()->create(['branch_id' => $consumer->branch_id]);
        $consumer->update(['sales_user_id' => $sales->id]);
        $consumer->refresh();

        $otherProject = LeadMaster::query()->create([
            'branch_id' => $consumer->branch_id,
            'project_name' => 'Other Project',
            'is_active' => true,
        ]);
        $otherSales = User::factory()->create(['branch_id' => $consumer->branch_id]);
        $best = $this->lead($consumer, $sales, [
            'customer_name' => 'Budi Santoso',
            'phone' => '+62 812 3456 7890',
            'normalized_phone' => '6281234567890',
            'lead_date' => '2026-11-10',
        ]);
        $phoneOnly = $this->lead($consumer, $otherSales, [
            'customer_name' => 'Nama Berbeda',
            'phone' => '+62 812 3456 7890',
            'normalized_phone' => '6281234567890',
            'lead_date' => '2026-11-10',
        ]);
        $nameOnly = $this->lead($consumer, $otherSales, [
            'customer_name' => 'Budi Sntoso',
            'phone' => '0899-1111-2222',
            'normalized_phone' => '6289911112222',
            'lead_date' => '2026-11-01',
        ]);
        $projectOnly = $this->lead($consumer, $otherSales, [
            'customer_name' => 'Nama Proyek',
            'phone' => '0899-2222-3333',
            'normalized_phone' => '6289922223333',
            'lead_date' => '2026-11-01',
        ]);
        $salesOnly = $this->lead($consumer, $sales, [
            'customer_name' => 'Nama Sales',
            'phone' => '0899-3333-4444',
            'normalized_phone' => '6289933334444',
            'lead_date' => '2026-11-01',
        ]);
        $late = $this->lead($consumer, $otherSales, [
            'customer_name' => 'Nama Berbeda',
            'phone' => '+62 812 3456 7890',
            'normalized_phone' => '6281234567890',
            'lead_date' => '2026-12-01',
        ]);
        $otherProjectLead = $this->lead($consumer, $otherSales, [
            'project_id' => $otherProject->id,
            'customer_name' => 'Budi Santoso',
            'phone' => '0812-3456-7890',
            'normalized_phone' => '6281234567890',
            'lead_date' => '2026-11-01',
        ]);
        $before = $consumer->getAttributes();
        $snapshotKeys = ['branch_id', 'project_id', 'sales_user_id', 'lead_date', 'customer_name', 'phone', 'normalized_phone'];
        $leadSnapshots = collect([$best, $phoneOnly, $nameOnly, $projectOnly, $salesOnly, $late, $otherProjectLead])
            ->mapWithKeys(fn (SalesLead $lead): array => [$lead->id => $lead->only($snapshotKeys)])
            ->all();

        $suggestions = app(ConsumerLeadLinkSuggestionService::class)->suggest($consumer, $viewer);

        $this->assertSame($best->id, $suggestions->first()['lead_id']);
        $this->assertSame($best->id, $suggestions->first()['lead']->id);
        $this->assertTrue($suggestions->first()['rationale']['normalized_phone_match']);
        $this->assertTrue($suggestions->firstWhere('lead_id', $phoneOnly->id)['rationale']['normalized_phone_match']);
        $this->assertTrue($suggestions->first()['rationale']['same_sales']);
        $this->assertTrue($suggestions->first()['rationale']['chronological_plausibility']);
        $this->assertGreaterThan(0.9, $suggestions->first()['rationale']['name_similarity']);
        $this->assertGreaterThan(
            $suggestions->firstWhere('lead_id', $late->id)['score'],
            $suggestions->first()['score'],
        );
        $this->assertGreaterThan(0.5, $suggestions->firstWhere('lead_id', $nameOnly->id)['rationale']['name_similarity']);
        $this->assertFalse($suggestions->firstWhere('lead_id', $late->id)['rationale']['chronological_plausibility']);
        $this->assertFalse($suggestions->contains('lead_id', $otherProjectLead->id));
        $this->assertFalse($suggestions->contains('lead_id', $projectOnly->id));
        $this->assertSame($before, $consumer->fresh()->getAttributes());
        foreach ($leadSnapshots as $leadId => $attributes) {
            $this->assertEquals($attributes, SalesLead::query()->findOrFail($leadId)->only($snapshotKeys));
        }
        $this->assertDatabaseCount('consumer_lead_link_decisions', 0);
    }

    public function test_br_link_suggestion_excludes_leads_outside_viewer_organization_and_workspace_scope(): void
    {
        $branch = Branch::query()->create(['name' => 'Cabang Scope', 'code' => 'SCP', 'is_active' => true]);
        $otherBranch = Branch::query()->create(['name' => 'Cabang Lain', 'code' => 'OTH', 'is_active' => true]);
        $project = LeadMaster::query()->create(['branch_id' => $branch->id, 'project_name' => 'Scope Project', 'is_active' => true]);
        $viewer = $this->scopedViewer($branch);
        $consumer = ConsumerApplication::factory()->create(['branch_id' => $branch->id, 'project_id' => $project->id]);
        $consumer->customer()->update(['name' => 'Sari Putri', 'phone' => '081234567890']);
        $sales = User::factory()->create(['branch_id' => $branch->id]);
        $otherSales = User::factory()->create(['branch_id' => $otherBranch->id]);
        $visible = $this->lead($consumer, $sales, [
            'customer_name' => 'Sari Putri',
            'phone' => '081234567890',
            'normalized_phone' => '6281234567890',
        ]);
        $hidden = $this->lead($consumer, $otherSales, [
            'branch_id' => $otherBranch->id,
            'project_id' => $otherBranch->projects()->create(['project_name' => 'Other Project', 'is_active' => true])->id,
            'customer_name' => 'Sari Putri',
            'phone' => '081234567890',
            'normalized_phone' => '6281234567890',
        ]);

        $suggestions = app(ConsumerLeadLinkSuggestionService::class)->suggest($consumer, $viewer);

        $this->assertTrue($suggestions->contains('lead_id', $visible->id));
        $this->assertFalse($suggestions->contains('lead_id', $hidden->id));
    }

    public function test_br_link_suggestion_excludes_outside_project_and_invisible_sales(): void
    {
        $branch = Branch::query()->create(['name' => 'Cabang Project', 'code' => 'PRJ', 'is_active' => true]);
        $project = LeadMaster::query()->create(['branch_id' => $branch->id, 'project_name' => 'Allowed Project', 'is_active' => true]);
        $outsideProject = LeadMaster::query()->create(['branch_id' => $branch->id, 'project_name' => 'Outside Project', 'is_active' => true]);
        $viewer = $this->scopedProjectViewer($branch, $project);
        $consumer = ConsumerApplication::factory()->create(['branch_id' => $branch->id, 'project_id' => $project->id]);
        $consumer->customer()->update(['name' => 'Sari Putri', 'phone' => '081234567890']);
        $visibleSales = User::factory()->create(['branch_id' => $branch->id]);
        $invisibleSales = User::factory()->create(['branch_id' => $branch->id]);
        $visibleSales->assignedProjects()->attach($project->id, ['is_primary' => true, 'is_active' => true]);

        $visible = $this->lead($consumer, $visibleSales, [
            'customer_name' => 'Sari Putri',
            'phone' => '081234567890',
            'normalized_phone' => '6281234567890',
        ]);
        $outsideProjectLead = $this->lead($consumer, $visibleSales, [
            'project_id' => $outsideProject->id,
            'customer_name' => 'Sari Putri',
            'phone' => '081234567890',
            'normalized_phone' => '6281234567890',
        ]);
        $invisibleSalesLead = $this->lead($consumer, $invisibleSales, [
            'customer_name' => 'Sari Putri',
            'phone' => '081234567890',
            'normalized_phone' => '6281234567890',
        ]);

        $suggestions = app(ConsumerLeadLinkSuggestionService::class)->suggest($consumer, $viewer);

        $this->assertTrue($suggestions->contains('lead_id', $visible->id));
        $this->assertFalse($suggestions->contains('lead_id', $outsideProjectLead->id));
        $this->assertFalse($suggestions->contains('lead_id', $invisibleSalesLead->id));

        $outsideConsumer = ConsumerApplication::factory()->create(['branch_id' => $branch->id, 'project_id' => $outsideProject->id]);
        $this->assertCount(0, app(ConsumerLeadLinkSuggestionService::class)->suggest($outsideConsumer, $viewer));
    }

    public function test_br_link_suggestion_requires_active_verified_password_changed_permissioned_viewer(): void
    {
        $viewer = $this->superadmin();
        $consumer = ConsumerApplication::factory()->create();

        foreach ([
            ['is_active' => false],
            ['email_verified_at' => null],
            ['password_changed_at' => null],
            ['must_change_password' => true, 'password_changed_at' => null],
        ] as $attributes) {
            $viewer->forceFill([
                'is_active' => true,
                'email_verified_at' => now(),
                'password_changed_at' => now(),
                'must_change_password' => false,
                ...$attributes,
            ])->save();

            $this->assertCount(0, app(ConsumerLeadLinkSuggestionService::class)->suggest($consumer, $viewer));
        }

        $permissionless = User::factory()->create([
            'password_changed_at' => now(),
            'must_change_password' => false,
        ]);

        $this->assertCount(0, app(ConsumerLeadLinkSuggestionService::class)->suggest($consumer, $permissionless));
    }

    public function test_br_link_suggestion_orders_equal_scores_by_lead_id(): void
    {
        $viewer = $this->superadmin();
        $consumer = ConsumerApplication::factory()->create([
            'nama_konsumen' => 'Dewi Lestari',
            'booking_date' => '2026-11-20',
        ]);
        $consumer->customer()->update(['name' => 'Nama', 'phone' => null]);
        $sales = User::factory()->create(['branch_id' => $consumer->branch_id]);
        $first = $this->lead($consumer, $sales, ['customer_name' => 'Nama', 'phone' => '081100000001']);
        $second = $this->lead($consumer, $sales, ['customer_name' => 'Nama', 'phone' => '081100000002']);

        $suggestions = app(ConsumerLeadLinkSuggestionService::class)->suggest($consumer, $viewer);

        $this->assertSame([$first->id, $second->id], $suggestions->pluck('lead_id')->all());
    }

    public function test_br_link_suggestion_honors_limit_and_returns_collection(): void
    {
        $viewer = $this->superadmin();
        $consumer = ConsumerApplication::factory()->create([
            'nama_konsumen' => 'Dewi Lestari',
            'booking_date' => '2026-11-20',
        ]);
        $consumer->customer()->update(['name' => 'Dewi Lestari', 'phone' => '081200000000']);
        $sales = User::factory()->create(['branch_id' => $consumer->branch_id]);
        foreach (range(1, 3) as $number) {
            $this->lead($consumer, $sales, [
                'customer_name' => 'Dewi Lestari Satu '.$number,
                'phone' => '08120000000'.$number,
                'normalized_phone' => '628120000000'.$number,
            ]);
        }

        $suggestions = app(ConsumerLeadLinkSuggestionService::class)->suggest($consumer, $viewer, 2);

        $this->assertInstanceOf(Collection::class, $suggestions);
        $this->assertCount(2, $suggestions);
    }

    private function lead(ConsumerApplication $consumer, User $sales, array $attributes): SalesLead
    {
        return SalesLead::query()->create(array_merge([
            'branch_id' => $consumer->branch_id,
            'project_id' => $consumer->project_id,
            'sales_user_id' => $sales->id,
            'lead_date' => '2026-11-01',
            'customer_name' => 'Lead',
            'phone' => '089000000000',
        ], $attributes));
    }

    private function superadmin(): User
    {
        $role = Role::query()->firstOrCreate(
            ['slug' => 'superadmin'],
            ['name' => 'Superadmin', 'is_superadmin' => true, 'is_active' => true],
        );

        return User::factory()->create([
            'role_id' => $role->id,
            'email_verified_at' => now(),
            'password_changed_at' => now(),
            'must_change_password' => false,
        ]);
    }

    private function scopedProjectViewer(Branch $branch, LeadMaster $project): User
    {
        $role = Role::query()->create(['name' => 'Scoped project viewer', 'slug' => 'scoped-project-viewer', 'is_superadmin' => false, 'is_active' => true]);
        foreach (['consumer_progress.view_project', 'sales_pocketbook.view_project'] as $slug) {
            $permission = Permission::query()->firstOrCreate(
                ['slug' => $slug],
                ['name' => $slug, 'description' => $slug, 'group_name' => 'Test'],
            );
            $role->permissions()->syncWithoutDetaching($permission);
        }
        Permission::resetRegisteredSlugs();

        $viewer = User::factory()->create([
            'role_id' => $role->id,
            'branch_id' => $branch->id,
            'email_verified_at' => now(),
            'password_changed_at' => now(),
            'must_change_password' => false,
        ]);
        $viewer->assignedProjects()->attach($project->id, ['is_primary' => true, 'is_active' => true]);

        return $viewer;
    }

    private function scopedViewer(Branch $branch): User
    {
        $role = Role::query()->create(['name' => 'Scoped viewer', 'slug' => 'scoped-viewer', 'is_superadmin' => false, 'is_active' => true]);
        foreach (['consumer_progress.view_branch', 'sales_pocketbook.view_branch'] as $slug) {
            $permission = Permission::query()->firstOrCreate(
                ['slug' => $slug],
                ['name' => $slug, 'description' => $slug, 'group_name' => 'Test'],
            );
            $role->permissions()->syncWithoutDetaching($permission);
        }
        Permission::resetRegisteredSlugs();

        return User::factory()->create([
            'role_id' => $role->id,
            'branch_id' => $branch->id,
            'email_verified_at' => now(),
            'password_changed_at' => now(),
            'must_change_password' => false,
        ]);
    }
}
