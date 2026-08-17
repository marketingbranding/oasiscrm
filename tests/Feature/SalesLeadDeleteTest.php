<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\SalesLead;
use App\Models\SalesLeadConsumerLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalesLeadDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_sees_delete_action_and_can_soft_delete_eligible_lead(): void
    {
        [$branch, $project, $lead] = $this->context();
        $superadmin = $this->user('superadmin', $branch)->load('role');
        $this->assertTrue($superadmin->isSuperadmin());

        $this->actingAs($superadmin)->get(route('sales-leads.show', $lead))
            ->assertOk()
            ->assertSee('Hapus Lead');
        $this->actingAs($superadmin)->delete(route('sales-leads.destroy', $lead))
            ->assertRedirect(route('sales-pocketbook.index'));

        $this->assertSoftDeleted('sales_leads', ['id' => $lead->id]);
        $this->assertDatabaseHas('activity_log', ['event' => 'deleted', 'subject_id' => $lead->id]);
        $this->assertNull(SalesLead::find($lead->id));
    }

    public function test_non_superadmin_roles_cannot_see_or_call_delete(): void
    {
        [$branch, $project, $lead] = $this->context();

        foreach (['sales', 'sales_coordinator', 'branch_manager', 'admin', 'supervisor'] as $role) {
            $user = $this->user($role, $branch);
            $response = $this->actingAs($user)->get(route('sales-leads.show', $lead));
            if ($response->status() === 200) {
                $response->assertDontSee('Hapus Lead');
            }
            $this->actingAs($user)->delete(route('sales-leads.destroy', $lead))->assertForbidden();
        }
    }

    public function test_impersonating_user_cannot_delete_even_when_original_user_is_superadmin(): void
    {
        [$branch, $project, $lead] = $this->context();
        $superadmin = $this->user('superadmin', $branch);
        $coordinator = $this->user('sales_coordinator', $branch);

        $this->actingAs($superadmin)->withSession([
            'impersonation.original_user_id' => $superadmin->id,
            'impersonation.target_user_id' => $coordinator->id,
        ])->delete(route('sales-leads.destroy', $lead))->assertForbidden();
    }

    public function test_consumer_link_blocks_delete_and_preserves_lead(): void
    {
        [$branch, $project, $lead] = $this->context();
        $superadmin = $this->user('superadmin', $branch);
        SalesLeadConsumerLink::create([
            'sales_lead_id' => $lead->id,
            'branch_id' => $branch->id,
            'actor_id' => $superadmin->id,
            'operation_uuid' => (string) Str::uuid(),
            'sheet_name' => 'data_konsumen',
            'status' => 'completed',
            'sheet_type' => 'data_konsumen',
            'converted_at' => now(),
        ]);

        $this->actingAs($superadmin)->from(route('sales-leads.show', $lead))
            ->delete(route('sales-leads.destroy', $lead))
            ->assertRedirect()
            ->assertSessionHas('error', 'Lead ini sudah terhubung ke data konsumen dan tidak dapat dihapus.');
        $this->assertDatabaseHas('sales_leads', ['id' => $lead->id, 'deleted_at' => null]);
    }

    private function context(): array
    {
        $branch = Branch::create(['name' => 'Test Branch', 'code' => Str::upper(Str::random(6)), 'is_active' => true]);
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Test Project', 'is_active' => true]);
        $sales = $this->user('sales', $branch);
        $lead = SalesLead::create([
            'branch_id' => $branch->id,
            'project_id' => $project->id,
            'sales_user_id' => $sales->id,
            'lead_date' => '2026-08-03',
            'customer_name' => 'Delete Test Lead',
            'phone' => '081234567890',
            'created_by' => $sales->id,
            'updated_by' => $sales->id,
        ]);

        return [$branch, $project, $lead];
    }

    private function user(string $roleSlug, Branch $branch): User
    {
        return User::factory()->create([
            'role_id' => Role::where('slug', $roleSlug)->value('id'),
            'branch_id' => $branch->id,
            'password_changed_at' => now(),
        ]);
    }
}
