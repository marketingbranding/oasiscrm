<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\SalesCoordinatorSales;
use App\Models\SalesLead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class ManagerBukuSakuScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_and_branch_manager_use_hierarchy_people_but_workspace_scoped_records(): void
    {
        $magelang = Branch::create(['name' => 'Magelang', 'code' => 'MGL', 'is_active' => true]);
        $solo = Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);
        $magelangProject = LeadMaster::create(['branch_id' => $magelang->id, 'project_name' => 'Proyek Magelang', 'is_active' => true]);
        $otherMagelangProject = LeadMaster::create(['branch_id' => $magelang->id, 'project_name' => 'Proyek Magelang Luar Scope', 'is_active' => true]);
        $soloProject = LeadMaster::create(['branch_id' => $solo->id, 'project_name' => 'Proyek Solo', 'is_active' => true]);

        foreach (['manager', 'branch_manager'] as $managerRole) {
            $manager = $this->user($managerRole, "{$managerRole} Magelang", $magelang);
            $manager->roles()->attach(Role::where('slug', 'sales')->value('id'));
            $supervisor = $this->user('supervisor', "SPV {$managerRole}", $magelang, $manager);
            $coordinator = $this->user('sales_coordinator', "Koordinator {$managerRole}", $magelang, $supervisor);
            $sales = $this->user('sales', "Sales {$managerRole}", $magelang);
            $sales->assignedProjects()->attach($magelangProject, ['is_primary' => true, 'is_active' => true]);
            SalesCoordinatorSales::create(['coordinator_user_id' => $coordinator->id, 'sales_user_id' => $sales->id]);

            $soloSupervisor = $this->user('supervisor', "SPV Solo {$managerRole}", $solo);
            $soloCoordinator = $this->user('sales_coordinator', "Koordinator Solo {$managerRole}", $solo, $soloSupervisor);
            $soloSales = $this->user('sales', "Sales Solo {$managerRole}", $solo);
            $soloSales->assignedProjects()->attach($soloProject, ['is_primary' => true, 'is_active' => true]);
            SalesCoordinatorSales::create(['coordinator_user_id' => $soloCoordinator->id, 'sales_user_id' => $soloSales->id]);

            $visible = $this->lead($sales, $magelangProject, "LEAD_VISIBLE_{$managerRole}");
            $outsideProject = $this->lead($sales, $otherMagelangProject, "LEAD_PROJECT_HIDDEN_{$managerRole}");
            $outsideBranch = $this->lead($soloSales, $soloProject, "LEAD_SOLO_HIDDEN_{$managerRole}");

            $page = $this->actingAs($manager)->get(route('sales-pocketbook.index'))->assertOk()
                ->assertViewIs('crm.sales-pocketbook.index')
                ->assertSee('Hierarki Tim Sales')
                ->assertSee($supervisor->name)->assertSee($coordinator->name)->assertSee($sales->name)
                ->assertSee($visible->customer_name)->assertDontSee($outsideProject->customer_name)
                ->assertDontSee($outsideBranch->customer_name)
                ->assertDontSee($soloSupervisor->name)->assertDontSee($soloCoordinator->name)->assertDontSee($soloSales->name);
            $page->assertSee('Buku Saku Sales');

            $agendaPage = $this->actingAs($manager)->get(route('sales-pocketbook.index', ['tab' => 'agenda']))->assertOk()
                ->assertSee('Monitoring Agenda')
                ->assertSee('Hierarki Tim Sales')
                ->assertDontSee('Isi Agenda Baru')
                ->assertDontSee('Jam Mulai')
                ->assertDontSee('Jam Selesai')
                ->assertDontSee('Simpan Agenda');
            $this->actingAs($manager)->post(route('sales-agendas.store'), [
                'scheduled_date' => now()->toDateString(),
                'sales_activity_category' => 'Cek Lokasi',
                'title' => 'Agenda terlarang',
            ])->assertForbidden();

            $export = $this->actingAs($manager)->get(route('sales-pocketbook.export'))->assertOk();
            $path = $export->baseResponse->getFile()->getPathname();
            $sheet = IOFactory::load($path)->getSheetByName('LEAD HARIAN');
            $values = collect($sheet->toArray())->flatten()->filter()->all();
            $this->assertContains($visible->customer_name, $values);
            $this->assertNotContains($outsideProject->customer_name, $values);
            $this->assertNotContains($outsideBranch->customer_name, $values);
            @unlink($path);
        }
    }

    private function user(string $role, string $name, Branch $branch, ?User $supervisor = null): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'role_id' => Role::where('slug', $role)->value('id'),
            'branch_id' => $branch->id,
            'supervisor_user_id' => $supervisor?->id,
            'password_changed_at' => now(),
        ]);
        $user->branches()->syncWithoutDetaching([$branch->id => ['can_view' => true, 'can_edit' => true, 'can_sync' => true]]);

        return $user;
    }

    private function lead(User $sales, LeadMaster $project, string $name): SalesLead
    {
        return SalesLead::create([
            'branch_id' => $project->branch_id,
            'project_id' => $project->id,
            'sales_user_id' => $sales->id,
            'lead_date' => now()->toDateString(),
            'customer_name' => $name,
            'created_by' => $sales->id,
        ]);
    }
}
