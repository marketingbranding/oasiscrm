<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\SalesCoordinatorSales;
use App\Models\SalesLead;
use App\Models\SalesLeadUnownedQueue;
use App\Models\User;
use App\Services\SalesLeadTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SalesLeadTransferServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_br54_coordinator_transfers_owned_lead_inside_active_team_and_notifies_target(): void
    {
        [$coordinator, $source, $target] = $this->team();
        $lead = $this->lead($source);

        app(SalesLeadTransferService::class)->transfer($coordinator, [$lead->id], $target, 'Tidak dapat dihubungi');

        $this->assertSame($target->id, $lead->fresh()->sales_user_id);
        $this->assertDatabaseHas('sales_lead_transfers', [
            'sales_lead_id' => $lead->id,
            'from_sales_user_id' => $source->id,
            'to_sales_user_id' => $target->id,
            'actor_user_id' => $coordinator->id,
        ]);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $target->id,
            'type' => 'lead_transferred_to_me',
            'related_id' => $lead->id,
        ]);
    }

    public function test_br55_coordinator_transfers_queued_lead_and_closes_queue(): void
    {
        [$coordinator, $source, $target] = $this->team();
        $lead = $this->lead($source);
        $queue = SalesLeadUnownedQueue::create([
            'sales_lead_id' => $lead->id,
            'former_sales_user_id' => $source->id,
            'queue_coordinator_user_id' => $coordinator->id,
            'last_active_coordinator_user_id' => $coordinator->id,
            'status' => 'open',
        ]);

        app(SalesLeadTransferService::class)->transfer($coordinator, [], $target, 'Tidak dapat dihubungi', null, $queue->id);

        $this->assertSame($target->id, $lead->fresh()->sales_user_id);
        $this->assertDatabaseHas('sales_lead_unowned_queues', ['id' => $queue->id, 'status' => 'closed', 'closed_by' => $coordinator->id]);
        $this->assertDatabaseHas('sales_lead_transfers', ['sales_lead_id' => $lead->id]);
    }

    public function test_br55_superadmin_transfers_queue_without_coordinator_in_lead_branch(): void
    {
        [$coordinator, $source, $target] = $this->team();
        $actor = $this->user('queue-superadmin@example.test', 'superadmin');
        $lead = $this->lead($source);
        $target->update(['branch_id' => $lead->branch_id]);
        $queue = SalesLeadUnownedQueue::create([
            'sales_lead_id' => $lead->id,
            'former_sales_user_id' => $source->id,
            'queue_coordinator_user_id' => null,
            'last_active_coordinator_user_id' => $coordinator->id,
            'status' => 'open',
        ]);

        app(SalesLeadTransferService::class)->transfer($actor, [], $target, 'Tidak dapat dihubungi', null, $queue->id);

        $this->assertSame($target->id, $lead->fresh()->sales_user_id);
        $this->assertDatabaseHas('sales_lead_unowned_queues', ['id' => $queue->id, 'status' => 'closed', 'closed_by' => $actor->id]);
    }

    public function test_d27_coordinator_cannot_transfer_to_sales_outside_active_team(): void
    {
        [$coordinator, $source] = $this->team();
        $outside = $this->user('outside-transfer@example.test', 'sales');
        $lead = $this->lead($source);

        $this->expectException(ValidationException::class);
        app(SalesLeadTransferService::class)->transfer($coordinator, [$lead->id], $outside, 'Tidak dapat dihubungi');
    }

    public function test_br54_sales_supervisor_branch_manager_manager_and_admin_cabang_cannot_transfer_by_direct_url_or_json(): void
    {
        [, $source, $target] = $this->team();
        $lead = $this->lead($source);

        foreach (['sales', 'supervisor', 'branch_manager', 'manager', 'admin'] as $role) {
            $user = $this->user("{$role}-transfer-denied@example.test", $role);
            $payload = [
                'lead_ids' => [$lead->id],
                'target_user_id' => $target->id,
                'reason' => 'Tidak dapat dihubungi',
            ];

            $this->actingAs($user)->post(route('sales-leads.transfers.store'), $payload)->assertRedirect();
            $this->actingAs($user)->postJson(route('sales-leads.transfers.store'), $payload)->assertForbidden();
        }
    }

    public function test_br54_bulk_transfer_is_atomic_and_names_invalid_lead(): void
    {
        [$coordinator, $source, $target] = $this->team();
        $first = $this->lead($source);
        $second = $this->lead($source, ['lead_stage' => 'gugur', 'gugur_at' => Carbon::now()]);

        try {
            app(SalesLeadTransferService::class)->transfer($coordinator, [$first->id, $second->id], $target, 'Tidak dapat dihubungi');
            $this->fail('Expected validation failure.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString((string) $second->id, $exception->getMessage());
        }

        $this->assertSame($source->id, $first->fresh()->sales_user_id);
        $this->assertDatabaseCount('sales_lead_transfers', 0);
    }

    private function team(): array
    {
        $coordinator = $this->user('coordinator-transfer@example.test', 'sales_coordinator');
        $source = $this->user('source-transfer@example.test', 'sales');
        $target = $this->user('target-transfer@example.test', 'sales');
        SalesCoordinatorSales::create([
            'coordinator_user_id' => $coordinator->id,
            'sales_user_id' => $source->id,
            'is_active' => true,
        ]);
        SalesCoordinatorSales::create([
            'coordinator_user_id' => $coordinator->id,
            'sales_user_id' => $target->id,
            'is_active' => true,
        ]);

        return [$coordinator, $source, $target];
    }

    private function lead(User $owner, array $attributes = []): SalesLead
    {
        $branch = Branch::query()->first() ?? Branch::create(['name' => 'Cabang Transfer', 'code' => 'TRF']);
        $project = LeadMaster::query()->first() ?? LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Proyek Transfer', 'is_active' => true]);

        return SalesLead::create(array_merge([
            'branch_id' => $branch->id,
            'project_id' => $project->id,
            'sales_user_id' => $owner->id,
            'customer_name' => 'Konsumen Transfer',
            'phone' => '081234567890',
            'lead_date' => Carbon::today(),
            'lead_stage' => 'lead_baru',
        ], $attributes));
    }

    private function user(string $email, string $roleSlug): User
    {
        return User::create([
            'name' => $roleSlug,
            'email' => $email,
            'password' => 'password',
            'role_id' => Role::query()->where('slug', $roleSlug)->value('id'),
            'account_status' => 'active',
            'is_active' => true,
            'password_changed_at' => Carbon::now(),
        ]);
    }
}
