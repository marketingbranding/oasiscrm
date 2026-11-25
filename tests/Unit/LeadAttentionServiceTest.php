<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\SalesLead;
use App\Models\User;
use App\Services\LeadAttentionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadAttentionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_wp23_attention_filter_does_not_duplicate_activity_joins(): void
    {
        $branch = Branch::create(['name' => 'Filter', 'code' => 'FI', 'is_active' => true]);
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Proyek Filter', 'is_active' => true]);
        $user = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'sales')->value('id'),
            'branch_id' => $branch->id,
            'password_changed_at' => now(),
        ]);
        $user->branches()->syncWithoutDetaching([$branch->id => ['can_view' => true, 'can_edit' => false, 'can_sync' => false]]);
        $user->assignedProjects()->attach($project, ['is_primary' => true, 'is_active' => true]);
        SalesLead::create([
            'branch_id' => $branch->id,
            'project_id' => $project->id,
            'sales_user_id' => $user->id,
            'lead_date' => now()->subDays(3)->toDateString(),
            'customer_name' => 'Lead Filter',
            'created_by' => $user->id,
        ]);

        $query = app(LeadAttentionService::class)->decorate(SalesLead::query()->where('sales_user_id', $user->id));

        $this->assertCount(1, app(LeadAttentionService::class)->whereNeedsAttention($query)->get());
    }
}
