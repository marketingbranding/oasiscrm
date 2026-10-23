<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\SalesLead;
use App\Models\User;
use App\Services\SalesLeadTargetService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\TestCase;

class SalesLeadTargetTest extends TestCase
{
    use RefreshDatabase;

    public function test_unset_target_keeps_actual_count_and_does_not_report_zero_achievement(): void
    {
        [$branch, $project, $sales] = $this->context();
        $this->lead($sales, $project, ['lead_date' => '2026-10-05']);

        $summary = app(SalesLeadTargetService::class)->summary($sales, CarbonImmutable::parse('2026-10-01'));

        $this->assertSame(1, $summary['actual']);
        $this->assertNull($summary['target']);
        $this->assertNull($summary['achievement']);
    }

    public function test_target_achievement_uses_sales_pic_not_the_user_who_created_the_lead(): void
    {
        [$branch, $project, $sales] = $this->context();
        $coordinator = $this->user('sales_coordinator', $branch, 'Koordinator');
        $superadmin = $this->user('superadmin', $branch, 'Superadmin');
        $this->lead($sales, $project, [
            'created_by' => $coordinator->id,
            'lead_date' => '2026-10-05',
        ]);
        app(SalesLeadTargetService::class)->upsert($sales, CarbonImmutable::parse('2026-10-01'), 4, $superadmin);

        $summary = app(SalesLeadTargetService::class)->summary($sales, CarbonImmutable::parse('2026-10-01'));

        $this->assertSame(1, $summary['actual']);
        $this->assertSame(4, $summary['target']);
        $this->assertSame(25.0, $summary['achievement']);
    }

    public function test_non_superadmin_cannot_set_a_target(): void
    {
        [$branch, , $sales] = $this->context();

        $this->expectException(AccessDeniedHttpException::class);
        app(SalesLeadTargetService::class)->upsert($sales, CarbonImmutable::parse('2026-10-01'), 4, $sales);
    }

    public function test_target_administration_is_only_available_to_superadmin(): void
    {
        [$branch, , $sales] = $this->context();
        $superadmin = $this->user('superadmin', $branch, 'Superadmin');
        $superadmin->forceFill(['email_verified_at' => now()])->save();

        $this->actingAs($sales)->get(route('sales-lead-targets.index'))->assertForbidden();
        $this->actingAs($superadmin)->get(route('sales-lead-targets.index', ['month' => '2026-10']))
            ->assertOk()
            ->assertSee('Target Lead Bulanan');
    }

    private function context(): array
    {
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);
        $project = LeadMaster::create([
            'branch_id' => $branch->id,
            'project_name' => 'Solo Project',
            'is_active' => true,
        ]);
        $sales = $this->user('sales', $branch, 'Solo Sales');
        $sales->assignedProjects()->attach($project, ['is_primary' => true]);

        return [$branch, $project, $sales];
    }

    private function user(string $roleSlug, ?Branch $branch = null, ?string $name = null): User
    {
        $role = Role::firstOrCreate([
            'slug' => $roleSlug,
        ], [
            'name' => ucfirst($roleSlug),
            'is_superadmin' => $roleSlug === 'superadmin',
        ]);

        return User::factory()->create([
            'name' => $name ?? ucfirst($roleSlug),
            'role_id' => $role->id,
            'branch_id' => $branch?->id,
            'password_changed_at' => now(),
        ]);
    }

    private function lead(User $sales, LeadMaster $project, array $overrides = []): SalesLead
    {
        return SalesLead::create(array_merge([
            'branch_id' => $project->branch_id,
            'project_id' => $project->id,
            'sales_user_id' => $sales->id,
            'lead_date' => '2026-10-05',
            'customer_name' => 'Lead Test',
            'created_by' => $sales->id,
        ], $overrides));
    }
}
