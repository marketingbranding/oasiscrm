<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ContentItem;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\SalesLead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class SalesPocketbookExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_legacy_export_keeps_filtered_lead_and_agenda_sheets(): void
    {
        [$branch, $project, $sales] = $this->context();
        $manager = $this->user('manager', $branch);
        $this->lead($sales, $project, 'Lead Dalam Periode', '2026-07-20');
        $this->lead($sales, $project, 'Lead Di Luar Periode', '2026-07-01');
        $this->agenda($sales, $project);

        $response = $this->actingAs($manager)->get(route('sales-pocketbook.export', [
            'branch_id' => $branch->id,
            'project_id' => $project->id,
            'sales_user_id' => $sales->id,
            'period_type' => 'week',
            'week' => '2026-07-20',
        ]))->assertOk();

        $workbook = IOFactory::load($response->baseResponse->getFile()->getPathname());
        $this->assertSame(['REKAP MINGGUAN', 'LEAD HARIAN', 'AGENDA HARIAN'], $workbook->getSheetNames());
        $leadValues = collect($workbook->getSheetByName('LEAD HARIAN')->toArray())->flatten()->implode('|');
        $this->assertStringContainsString('Lead Dalam Periode', $leadValues);
        $this->assertStringNotContainsString('Lead Di Luar Periode', $leadValues);
        $this->assertSame('Agenda Konsumen', $workbook->getSheetByName('AGENDA HARIAN')->getCell('I2')->getValue());
        $workbook->disconnectWorksheets();
    }

    public function test_manager_export_rejects_inaccessible_branch_filter(): void
    {
        [$branch] = $this->context();
        $manager = $this->user('manager', $branch);
        $otherBranch = Branch::create(['name' => 'Pati', 'code' => 'PTI', 'is_active' => true]);

        $this->actingAs($manager)->get(route('sales-pocketbook.export', [
            'branch_id' => $otherBranch->id,
            'period_type' => 'week',
            'week' => '2026-07-20',
        ]))->assertForbidden();
    }

    private function context(): array
    {
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Solo Project', 'is_active' => true]);
        $sales = $this->user('sales', $branch, 'Solo Sales');
        $sales->assignedProjects()->attach($project, ['is_primary' => true]);

        return [$branch, $project, $sales];
    }

    private function user(string $slug, ?Branch $branch = null, ?string $name = null): User
    {
        $role = Role::firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug), 'is_superadmin' => false]);

        return User::factory()->create(['name' => $name ?? ucfirst($slug), 'role_id' => $role->id, 'branch_id' => $branch?->id, 'password_changed_at' => now()]);
    }

    private function lead(User $sales, LeadMaster $project, string $name, string $date): SalesLead
    {
        return SalesLead::create([
            'branch_id' => $project->branch_id,
            'project_id' => $project->id,
            'sales_user_id' => $sales->id,
            'lead_date' => $date,
            'customer_name' => $name,
            'created_by' => $sales->id,
        ]);
    }

    private function agenda(User $sales, LeadMaster $project): ContentItem
    {
        return ContentItem::create([
            'branch_id' => $project->branch_id,
            'project_name' => $project->project_name,
            'sales_project_id' => $project->id,
            'item_type' => 'agenda',
            'agenda_type' => ContentItem::SALES_AGENDA_TYPE,
            'visibility' => 'personal',
            'title' => 'Agenda Konsumen',
            'scheduled_date' => '2026-07-21',
            'status' => 'done',
            'activity_result' => 'Selesai',
            'completed_at' => '2026-07-21 10:00:00',
            'owner_user_id' => $sales->id,
            'created_by' => $sales->id,
        ]);
    }
}
