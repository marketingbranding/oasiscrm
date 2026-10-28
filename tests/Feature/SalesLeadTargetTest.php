<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\SalesLead;
use App\Models\SalesLeadTarget;
use App\Models\User;
use App\Services\SalesLeadTargetService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
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

    public function test_bulk_target_overwrites_existing_target_for_every_active_sales_user(): void
    {
        [$branch, , $firstSales] = $this->context();
        $secondSales = $this->user('sales', $branch, 'Second Sales');
        $superadmin = $this->user('superadmin', $branch, 'Superadmin');
        $service = app(SalesLeadTargetService::class);
        $month = CarbonImmutable::parse('2026-10-01');

        $service->upsert($firstSales, $month, 4, $superadmin);

        $count = $service->applyToAllActiveSales($superadmin, $month, 12);

        $this->assertSame(2, $count);
        $this->assertDatabaseHas('sales_lead_targets', [
            'sales_user_id' => $firstSales->id,
            'period_year' => 2026,
            'period_month' => 10,
            'lead_target' => 12,
            'target_leads' => 12,
        ]);
        $this->assertDatabaseHas('sales_lead_targets', [
            'sales_user_id' => $secondSales->id,
            'period_year' => 2026,
            'period_month' => 10,
            'lead_target' => 12,
            'target_leads' => 12,
        ]);
    }

    public function test_bulk_target_excludes_inactive_and_non_sales_users(): void
    {
        [$branch, , $activeSales] = $this->context();
        $inactiveSales = $this->user('sales', $branch, 'Inactive Sales');
        $inactiveSales->forceFill(['is_active' => false])->saveQuietly();
        $coordinator = $this->user('sales_coordinator', $branch, 'Coordinator');
        $superadmin = $this->user('superadmin', $branch, 'Superadmin');

        $count = app(SalesLeadTargetService::class)->applyToAllActiveSales(
            $superadmin,
            CarbonImmutable::parse('2026-10-01'),
            12,
        );

        $this->assertSame(1, $count);
        $this->assertDatabaseHas('sales_lead_targets', ['sales_user_id' => $activeSales->id]);
        $this->assertDatabaseMissing('sales_lead_targets', ['sales_user_id' => $inactiveSales->id]);
        $this->assertDatabaseMissing('sales_lead_targets', ['sales_user_id' => $coordinator->id]);
    }

    public function test_bulk_target_rolls_back_all_writes_when_one_target_save_fails(): void
    {
        [$branch, , $firstSales] = $this->context();
        $secondSales = $this->user('sales', $branch, 'Second Sales');
        $superadmin = $this->user('superadmin', $branch, 'Superadmin');
        $dispatcher = SalesLeadTarget::getEventDispatcher();

        SalesLeadTarget::saving(function (SalesLeadTarget $target) use ($secondSales): void {
            if ((int) $target->sales_user_id === (int) $secondSales->id) {
                throw new RuntimeException('forced target failure');
            }
        });

        try {
            app(SalesLeadTargetService::class)->applyToAllActiveSales(
                $superadmin,
                CarbonImmutable::parse('2026-10-01'),
                12,
            );
            $this->fail('Expected the bulk target transaction to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('forced target failure', $exception->getMessage());
        } finally {
            SalesLeadTarget::setEventDispatcher($dispatcher);
        }

        $this->assertDatabaseMissing('sales_lead_targets', ['sales_user_id' => $firstSales->id]);
        $this->assertDatabaseMissing('sales_lead_targets', ['sales_user_id' => $secondSales->id]);
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
