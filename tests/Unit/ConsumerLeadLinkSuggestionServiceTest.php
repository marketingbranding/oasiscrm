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
use App\Services\PhoneNormalizationService;
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

        $best = $this->lead($consumer, $sales, [
            'customer_name' => 'Budi Santoso',
            'phone' => '+62 812 3456 7890',
            'normalized_phone' => app(PhoneNormalizationService::class)->normalize('+62 812 3456 7890'),
            'lead_date' => '2026-11-10',
        ]);
        $nameOnly = $this->lead($consumer, $sales, [
            'customer_name' => 'Budi Sntoso',
            'phone' => '0899-1111-2222',
            'normalized_phone' => '6289911112222',
            'lead_date' => '2026-11-01',
            'project_id' => $consumer->project_id,
        ]);
        $late = $this->lead($consumer, $sales, [
            'customer_name' => 'Budi Santoso',
            'phone' => '+62 812 3456 7890',
            'normalized_phone' => '6281234567890',
            'lead_date' => '2026-12-01',
        ]);
        $before = $consumer->getAttributes();

        $suggestions = app(ConsumerLeadLinkSuggestionService::class)->suggest($consumer, $viewer);

        $this->assertSame($best->id, $suggestions->first()['lead_id']);
        $this->assertSame($best->id, $suggestions->first()['lead']->id);
        $this->assertTrue($suggestions->first()['rationale']['normalized_phone_match']);
        $this->assertTrue($suggestions->first()['rationale']['same_project']);
        $this->assertTrue($suggestions->first()['rationale']['same_sales']);
        $this->assertTrue($suggestions->first()['rationale']['chronological_plausibility']);
        $this->assertGreaterThan(0.9, $suggestions->first()['rationale']['name_similarity']);
        $this->assertGreaterThan(
            $suggestions->firstWhere('lead_id', $late->id)['score'],
            $suggestions->first()['score'],
        );
        $this->assertGreaterThan(0.5, $suggestions->firstWhere('lead_id', $nameOnly->id)['rationale']['name_similarity']);
        $this->assertFalse($suggestions->firstWhere('lead_id', $late->id)['rationale']['chronological_plausibility']);
        $this->assertSame($before, $consumer->fresh()->getAttributes());
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
                'customer_name' => 'Dewi Lestari '.$number,
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
            'password_changed_at' => now(),
        ]);
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
            'password_changed_at' => now(),
        ]);
    }
}
