<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Models\Branch;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\SalesCoordinatorSales;
use App\Models\SalesLead;
use App\Models\User;
use App\Services\UserAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SalesLeadDeactivationQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_br55_deactivation_is_immediate_and_active_lead_enters_last_team_queue(): void
    {
        $sales = $this->user('deactivate-sales@example.test', 'sales');
        $coordinator = $this->user('deactivate-coordinator@example.test', 'sales_coordinator');
        $actor = $this->user('deactivate-actor@example.test', 'superadmin');
        SalesCoordinatorSales::create([
            'coordinator_user_id' => $coordinator->id,
            'sales_user_id' => $sales->id,
            'is_active' => false,
            'started_at' => Carbon::today()->subMonths(2),
            'ended_at' => Carbon::yesterday(),
        ]);
        $lead = $this->lead($sales);

        app(UserAccountService::class)->deactivate($sales, $actor);

        $this->assertSame(AccountStatus::Inactive, $sales->fresh()->account_status);
        $this->actingAs($sales->fresh())->getJson(route('notifications.index'))
            ->assertForbidden()
            ->assertJsonPath('code', 'account_inactive');
        $this->assertNull($lead->fresh()->sales_user_id);
        $this->assertDatabaseHas('sales_lead_unowned_queues', [
            'sales_lead_id' => $lead->id,
            'former_sales_user_id' => $sales->id,
            'last_active_coordinator_user_id' => $coordinator->id,
            'queue_coordinator_user_id' => $coordinator->id,
            'status' => 'open',
        ]);
    }

    public function test_br55_deactivation_excludes_utj_and_gugur_leads(): void
    {
        $sales = $this->user('terminal-sales@example.test', 'sales');
        $actor = $this->user('terminal-actor@example.test', 'superadmin');
        $utj = $this->lead($sales, ['lead_stage' => 'utj']);
        $gugur = $this->lead($sales, ['lead_stage' => 'gugur', 'gugur_at' => Carbon::now()]);

        app(UserAccountService::class)->deactivate($sales, $actor);

        $this->assertDatabaseMissing('sales_lead_unowned_queues', ['sales_lead_id' => $utj->id]);
        $this->assertDatabaseMissing('sales_lead_unowned_queues', ['sales_lead_id' => $gugur->id]);
        $this->assertSame($sales->id, $utj->fresh()->sales_user_id);
    }

    public function test_br55_without_active_coordinator_leaves_queue_for_superadmin_only(): void
    {
        $sales = $this->user('fallback-sales@example.test', 'sales');
        $coordinator = $this->user('fallback-coordinator@example.test', 'sales_coordinator');
        $coordinator->update(['account_status' => AccountStatus::Inactive, 'is_active' => false]);
        $actor = $this->user('fallback-actor@example.test', 'superadmin');
        SalesCoordinatorSales::create([
            'coordinator_user_id' => $coordinator->id,
            'sales_user_id' => $sales->id,
            'is_active' => false,
            'started_at' => Carbon::today()->subMonths(2),
            'ended_at' => Carbon::yesterday(),
        ]);
        $lead = $this->lead($sales);

        app(UserAccountService::class)->deactivate($sales, $actor);

        $this->assertDatabaseHas('sales_lead_unowned_queues', [
            'sales_lead_id' => $lead->id,
            'queue_coordinator_user_id' => null,
            'status' => 'open',
        ]);
        $this->assertTrue($actor->fresh()->isSuperadmin());
        $this->actingAs($actor)->getJson(route('sales-leads.unowned-queue'))
            ->assertOk()
            ->assertJsonPath('queue.0.sales_lead_id', $lead->id);
    }

    private function lead(User $owner, array $attributes = []): SalesLead
    {
        $branch = Branch::query()->first() ?? Branch::create(['name' => 'Cabang Deactivate', 'code' => 'DCT']);
        $project = LeadMaster::query()->first() ?? LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Proyek Deactivate', 'is_active' => true]);

        return SalesLead::create(array_merge([
            'branch_id' => $branch->id,
            'project_id' => $project->id,
            'sales_user_id' => $owner->id,
            'customer_name' => 'Konsumen Deactivate',
            'phone' => '081234567890',
            'lead_date' => Carbon::today(),
            'lead_stage' => 'lead_baru',
        ], $attributes));
    }

    private function user(string $email, string $roleSlug): User
    {
        $user = User::create([
            'name' => $roleSlug,
            'email' => $email,
            'password' => 'password',
            'role_id' => Role::query()->where('slug', $roleSlug)->value('id'),
            'account_status' => 'active',
            'is_active' => true,
            'password_changed_at' => Carbon::now(),
        ]);
        $user->forceFill(['email_verified_at' => Carbon::now()])->saveQuietly();

        return $user;
    }
}
