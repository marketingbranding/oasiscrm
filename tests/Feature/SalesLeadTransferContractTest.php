<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\SalesLead;
use App\Models\SalesLeadTransfer;
use App\Models\SalesLeadUnownedQueue;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SalesLeadTransferContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_br54_transfer_history_stores_source_target_reason_and_actor(): void
    {
        $lead = $this->lead();
        $source = $this->user('sales-source@example.test', 'sales');
        $target = $this->user('sales-target@example.test', 'sales');
        $actor = $this->user('coordinator@example.test', 'sales_coordinator');

        $transfer = SalesLeadTransfer::create([
            'sales_lead_id' => $lead->id,
            'from_sales_user_id' => $source->id,
            'to_sales_user_id' => $target->id,
            'actor_user_id' => $actor->id,
            'reason' => 'Tidak dapat dihubungi',
            'notes' => 'Alih untuk tindak lanjut.',
        ]);

        $this->assertDatabaseHas('sales_lead_transfers', [
            'id' => $transfer->id,
            'sales_lead_id' => $lead->id,
            'from_sales_user_id' => $source->id,
            'to_sales_user_id' => $target->id,
            'actor_user_id' => $actor->id,
            'reason' => 'Tidak dapat dihubungi',
        ]);
    }

    public function test_br55_unowned_queue_excludes_terminal_leads_by_contract(): void
    {
        $active = $this->lead();
        $terminal = $this->lead(['lead_stage' => 'gugur', 'gugur_at' => Carbon::now()]);
        $owner = $this->user('inactive-sales@example.test', 'sales');

        SalesLeadUnownedQueue::create([
            'sales_lead_id' => $active->id,
            'former_sales_user_id' => $owner->id,
            'queue_coordinator_user_id' => null,
            'status' => 'open',
        ]);

        $this->assertDatabaseHas('sales_lead_unowned_queues', ['sales_lead_id' => $active->id]);
        $this->assertDatabaseMissing('sales_lead_unowned_queues', ['sales_lead_id' => $terminal->id]);
    }

    public function test_br54_transfer_history_cannot_be_deleted(): void
    {
        $transfer = SalesLeadTransfer::create([
            'sales_lead_id' => $this->lead()->id,
            'from_sales_user_id' => $this->user('source-delete@example.test', 'sales')->id,
            'to_sales_user_id' => $this->user('target-delete@example.test', 'sales')->id,
            'actor_user_id' => $this->user('actor-delete@example.test', 'sales_coordinator')->id,
            'reason' => 'Alasan tes',
        ]);

        $this->expectException(\LogicException::class);
        $transfer->delete();
    }

    private function lead(array $attributes = []): SalesLead
    {
        $branch = Branch::query()->first() ?? Branch::create(['name' => 'Cabang Tes', 'code' => 'TST']);
        $project = LeadMaster::query()->first() ?? LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Proyek Tes', 'is_active' => true]);
        $owner = $this->user('lead-owner-'.uniqid().'@example.test', 'sales');

        return SalesLead::create(array_merge([
            'branch_id' => $branch->id,
            'project_id' => $project->id,
            'sales_user_id' => $owner->id,
            'customer_name' => 'Konsumen Tes',
            'phone' => '081234567890',
            'lead_date' => Carbon::today(),
            'lead_stage' => 'lead_baru',
        ], $attributes));
    }

    private function user(string $email, string $roleSlug): User
    {
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();

        return User::create([
            'name' => ucfirst(strstr($email, '@', true)),
            'email' => $email,
            'password' => 'password',
            'role_id' => $role->id,
            'account_status' => 'active',
            'is_active' => true,
            'password_changed_at' => Carbon::now(),
        ]);
    }
}
