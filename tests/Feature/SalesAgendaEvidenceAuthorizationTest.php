<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ContentItem;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\SalesAgendaEvidence;
use App\Models\SalesCoordinatorSales;
use App\Models\User;
use App\Services\SalesAgendaEvidenceAuthorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
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

    public function test_coordinator_private_stream_uses_current_operational_assignment_and_monitoring_scope(): void
    {
        Storage::fake('agenda_evidence');
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SO', 'is_active' => true]);
        $otherBranch = Branch::create(['name' => 'Pati', 'code' => 'PA', 'is_active' => true]);
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Project', 'is_active' => true]);
        $otherProject = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Other', 'is_active' => true]);
        $coordinator = $this->user('sales_coordinator', $branch);
        $sales = $this->user('sales', $branch);
        $otherSales = $this->user('sales', $branch);
        $foreignSales = $this->user('sales', $otherBranch);
        $this->assignProject($sales, $project);
        $this->assignProject($otherSales, $otherProject);
        $this->assignProject($foreignSales, $otherProject);
        SalesCoordinatorSales::create(['coordinator_user_id' => $coordinator->id, 'sales_user_id' => $sales->id, 'is_active' => true, 'started_at' => today()->subDay(), 'ended_at' => today()->addDay()]);
        $agenda = $this->agenda($sales, $project);
        $evidence = SalesAgendaEvidence::create(['content_item_id' => $agenda->id, 'uploaded_by_user_id' => $sales->id, 'storage_path' => 'evidence/test.webp', 'original_name' => 'test.webp', 'mime_type' => 'image/webp', 'width' => 10, 'height' => 10, 'size_bytes' => 3, 'sha256' => hash('sha256', 'img')]);
        Storage::disk('agenda_evidence')->put('evidence/test.webp', 'img');

        $this->actingAs($coordinator)->get(route('sales-agendas.evidence.show', [$agenda, $evidence]))->assertOk()->assertHeader('Content-Type', 'image/webp');

        foreach ([[$otherSales, $otherProject], [$foreignSales, $otherProject]] as [$owner, $ownerProject]) {
            $deniedAgenda = $this->agenda($owner, $ownerProject);
            $deniedEvidence = SalesAgendaEvidence::create(['content_item_id' => $deniedAgenda->id, 'uploaded_by_user_id' => $owner->id, 'storage_path' => 'evidence/denied.webp', 'original_name' => 'denied.webp', 'mime_type' => 'image/webp', 'width' => 10, 'height' => 10, 'size_bytes' => 3, 'sha256' => hash('sha256', 'img')]);
            $this->actingAs($coordinator)->get(route('sales-agendas.evidence.show', [$deniedAgenda, $deniedEvidence]))->assertForbidden();
        }

        SalesCoordinatorSales::create(['coordinator_user_id' => $coordinator->id, 'sales_user_id' => $otherSales->id, 'is_active' => false, 'started_at' => today()->subMonth(), 'ended_at' => today()->subDay()]);
        $this->assertFalse(app(SalesAgendaEvidenceAuthorizationService::class)->canView($coordinator, $this->agenda($otherSales, $otherProject)));
    }

    private function assignProject(User $sales, LeadMaster $project): void
    {
        $sales->assignedProjects()->attach($project->id, ['is_primary' => true, 'is_active' => true, 'assignment_start_date' => today()->subDay(), 'assignment_end_date' => today()->addDay()]);
    }

    private function agenda(User $sales, LeadMaster $project): ContentItem
    {
        return ContentItem::create(['branch_id' => $project->branch_id, 'sales_project_id' => $project->id, 'item_type' => 'agenda', 'agenda_type' => ContentItem::SALES_AGENDA_TYPE, 'title' => 'Visit', 'scheduled_date' => now(), 'status' => 'planned', 'owner_user_id' => $sales->id, 'created_by' => $sales->id]);
    }

    private function user(string $slug, Branch $branch): User
    {
        $role = Role::firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);

        return User::factory()->create(['role_id' => $role->id, 'branch_id' => $branch->id, 'password_changed_at' => now()]);
    }
}
