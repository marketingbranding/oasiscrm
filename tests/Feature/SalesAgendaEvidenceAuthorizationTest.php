<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ContentItem;
use App\Models\Role;
use App\Models\User;
use App\Services\SalesAgendaEvidenceAuthorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesAgendaEvidenceAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_primary_roles_control_mutation_branch_read_and_archive_authority_without_supplemental_elevation(): void
    {
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SO', 'is_active' => true]);
        $otherBranch = Branch::create(['name' => 'Pati', 'code' => 'PA', 'is_active' => true]);
        $sales = $this->user('sales', $branch);
        $otherSales = $this->user('sales', $branch);
        $admin = $this->user('admin', $branch);
        $superadmin = $this->user('superadmin', $otherBranch);
        $staff = $this->user('staff', $branch);
        $staff->roles()->attach(Role::where('slug', 'superadmin')->firstOrFail());
        $agenda = ContentItem::create(['branch_id' => $branch->id, 'item_type' => 'agenda', 'agenda_type' => ContentItem::SALES_AGENDA_TYPE, 'title' => 'Visit', 'scheduled_date' => now(), 'status' => 'planned', 'owner_user_id' => $sales->id, 'created_by' => $sales->id]);
        $access = app(SalesAgendaEvidenceAuthorizationService::class);
        $this->assertTrue($access->canMutate($sales, $agenda));
        $this->assertFalse($access->canMutate($otherSales, $agenda));
        $this->assertTrue($access->canView($admin, $agenda));
        $this->assertTrue($access->canView($superadmin, $agenda));
        $this->assertFalse($access->canManageArchives($staff));
        $agenda->update(['status' => 'cancelled']);
        $this->assertFalse($access->canMutate($sales, $agenda));
        $this->assertTrue($access->canView($sales, $agenda));
    }

    private function user(string $slug, Branch $branch): User
    {
        $role = Role::firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);

        return User::factory()->create(['role_id' => $role->id, 'branch_id' => $branch->id, 'password_changed_at' => now()]);
    }
}
