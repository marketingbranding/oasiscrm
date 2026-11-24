<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ContentItem;
use App\Models\LeadMaster;
use App\Models\OrganizationAssignment;
use App\Models\Role;
use App\Models\SalesLead;
use App\Models\SalesLeadTransfer;
use App\Models\User;
use App\Services\AppSettingResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TeamHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_br50_direct_child_gets_full_lead_and_agenda_history_while_distant_child_is_summary_only(): void
    {
        [$branch, $project, $viewer, $direct, $distant] = $this->graph();
        $lead = $this->lead($direct, $project, 'Lead Langsung');
        $distantLead = $this->lead($distant, $project, 'Lead Turunan');
        DB::table('sales_lead_status_histories')->insert([
            ['sales_lead_id' => $lead->id, 'branch_id' => $branch->id, 'status' => 'new', 'source' => 'test', 'changed_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ['sales_lead_id' => $distantLead->id, 'branch_id' => $branch->id, 'status' => 'new', 'source' => 'test', 'changed_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->agenda($direct, $project, 'Agenda Langsung');
        $this->agenda($distant, $project, 'Agenda Turunan');
        $this->enableFlag();

        $response = $this->actingAs($viewer)->get(route('team-history.index'));

        $response->assertOk()->assertSee('Lead Langsung')->assertSee('Agenda Langsung')->assertSee('Lead diperbarui')->assertDontSee('Lead Turunan')->assertDontSee('Agenda Turunan');
    }

    public function test_br51_outside_tree_cannot_open_team_history_and_flag_off_hides_page(): void
    {
        [, , $viewer] = $this->graph();
        $outside = $this->user('sales_coordinator', $viewer->branch, 'Di luar pohon');
        $this->enableFlag();

        $this->actingAs($outside)->get(route('team-history.index'))->assertForbidden();
        $this->actingAs($viewer)->get(route('team-history.index'))->assertOk();

        DB::table('app_settings')->updateOrInsert(['key' => 'org_hierarchical_monitoring'], ['value' => json_encode(false), 'group' => 'feature_flags', 'created_at' => now(), 'updated_at' => now()]);
        app(AppSettingResolver::class)->forget('org_hierarchical_monitoring');
        $this->actingAs($viewer)->get(route('team-history.index'))->assertNotFound();
    }

    public function test_br52_pusat_viewer_sees_descendants_across_branches(): void
    {
        [$branch, $project, $viewer, $direct] = $this->graph();
        $viewer->forceFill(['role_id' => Role::query()->where('slug', 'supervisor')->value('id'), 'scope_level' => 'pusat'])->saveQuietly();
        $otherBranch = Branch::create(['name' => 'Cabang Lain', 'code' => 'RWL', 'is_active' => true]);
        $otherProject = LeadMaster::create(['branch_id' => $otherBranch->id, 'project_name' => 'Proyek Lain', 'is_active' => true]);
        $direct->forceFill(['branch_id' => $otherBranch->id])->saveQuietly();
        OrganizationAssignment::query()->where('user_id', $direct->id)->update(['branch_id' => $otherBranch->id]);
        $lead = $this->lead($direct, $otherProject, 'Lead Lintas Cabang');
        DB::table('sales_lead_status_histories')->insert(['sales_lead_id' => $lead->id, 'branch_id' => $otherBranch->id, 'status' => 'new', 'source' => 'test', 'changed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->enableFlag();

        $this->actingAs($viewer)->get(route('team-history.index'))->assertOk()->assertSee('Lead Lintas Cabang');
    }

    public function test_br52_distant_activity_outside_viewer_project_scope_is_hidden(): void
    {
        [$branch, $project, $viewer, $direct, $distant] = $this->graph();
        $outsideBranch = Branch::create(['name' => 'Cabang Di Luar', 'code' => 'RWO', 'is_active' => true]);
        $outsideProject = LeadMaster::create(['branch_id' => $outsideBranch->id, 'project_name' => 'Proyek Di Luar', 'is_active' => true]);
        $lead = $this->lead($direct, $outsideProject, 'Lead Di Luar Scope');
        DB::table('sales_lead_status_histories')->insert(['sales_lead_id' => $lead->id, 'branch_id' => $branch->id, 'status' => 'new', 'source' => 'test', 'changed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->enableFlag();

        $this->actingAs($viewer)->get(route('team-history.index'))->assertOk()->assertDontSee('Lead Di Luar Scope');
    }

    public function test_br50_transfer_shows_incoming_and_outgoing_for_each_owner(): void
    {
        [$branch, $project, $viewer, $direct, $distant] = $this->graph();
        $lead = $this->lead($direct, $project, 'Lead Alih');
        SalesLeadTransfer::create(['sales_lead_id' => $lead->id, 'from_sales_user_id' => $direct->id, 'to_sales_user_id' => $distant->id, 'actor_user_id' => $viewer->id, 'reason' => 'Test']);
        $this->enableFlag();

        $this->actingAs($viewer)->get(route('team-history.index', ['type' => 'lead_transfer']))->assertOk()->assertSee('Alih Lead')->assertSee('Bawahan Langsung')->assertSee('Turunan Jauh');
    }

    public function test_br50_pagination_exposes_second_page(): void
    {
        [$branch, $project, $viewer, $direct] = $this->graph();
        for ($index = 0; $index < 51; $index++) {
            $lead = $this->lead($direct, $project, 'Lead Halaman '.$index);
            DB::table('sales_lead_status_histories')->insert(['sales_lead_id' => $lead->id, 'branch_id' => $branch->id, 'status' => 'new', 'source' => 'test', 'changed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->enableFlag();

        $this->actingAs($viewer)->get(route('team-history.index', ['type' => 'lead_status', 'page' => 2]))->assertOk()->assertSee('page=1')->assertSee('Lead Halaman');
    }

    public function test_br52_date_range_over_92_days_returns_422(): void
    {
        [, , $viewer] = $this->graph();
        $this->enableFlag();

        $this->actingAs($viewer)->get(route('team-history.index', ['date_from' => '2026-01-01', 'date_to' => '2026-04-04']))->assertStatus(422)->assertSee('Rentang tanggal maksimal 92 hari.');
    }

    public function test_br52_date_and_direct_child_filters_keep_scope(): void
    {
        [, $project, $viewer, $direct] = $this->graph();
        $lead = $this->lead($direct, $project, 'Lead Filter');
        DB::table('sales_lead_status_histories')->insert(['sales_lead_id' => $lead->id, 'branch_id' => $viewer->branch_id, 'status' => 'new', 'source' => 'test', 'changed_at' => '2026-01-02 10:00:00', 'created_at' => now(), 'updated_at' => now()]);
        $this->enableFlag();

        $this->actingAs($viewer)->get(route('team-history.index', ['user_id' => $direct->id, 'date_from' => '2026-01-01', 'date_to' => '2026-01-03', 'type' => 'lead_status']))->assertOk()->assertSee('Lead Filter');
        $this->actingAs($viewer)->get(route('team-history.index', ['user_id' => $viewer->id]))->assertForbidden();
    }

    private function graph(): array
    {
        $branch = Branch::create(['name' => 'Riwayat Tim', 'code' => 'RWT', 'is_active' => true]);
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Proyek Riwayat', 'is_active' => true]);
        $viewer = $this->user('sales_coordinator', $branch, 'Pemantau');
        $direct = $this->user('sales_coordinator', $branch, 'Bawahan Langsung');
        $distant = $this->user('sales', $branch, 'Turunan Jauh');
        OrganizationAssignment::create(['user_id' => $direct->id, 'parent_user_id' => $viewer->id, 'relationship_type' => OrganizationAssignment::REPORTS_TO, 'branch_id' => $branch->id, 'started_at' => today(), 'is_active' => true, 'is_primary' => true]);
        OrganizationAssignment::create(['user_id' => $distant->id, 'parent_user_id' => $direct->id, 'relationship_type' => OrganizationAssignment::REPORTS_TO, 'branch_id' => $branch->id, 'started_at' => today(), 'is_active' => true, 'is_primary' => true]);
        $direct->assignedProjects()->attach($project->id, ['is_active' => true, 'assignment_start_date' => today()->subDay(), 'assignment_end_date' => today()->addDay()]);
        $distant->assignedProjects()->attach($project->id, ['is_active' => true, 'assignment_start_date' => today()->subDay(), 'assignment_end_date' => today()->addDay()]);

        return [$branch, $project, $viewer, $direct, $distant];
    }

    private function user(string $role, Branch $branch, string $name): User
    {
        $user = User::factory()->create(['name' => $name, 'role_id' => Role::query()->where('slug', $role)->value('id'), 'branch_id' => $branch->id, 'is_active' => true, 'account_status' => 'active', 'password_changed_at' => now()]);
        $user->branches()->syncWithoutDetaching([$branch->id => ['membership_role' => 'primary', 'can_view' => true, 'can_edit' => false, 'can_sync' => false, 'can_manage_members' => false]]);

        return $user;
    }

    private function lead(User $sales, LeadMaster $project, string $name): SalesLead
    {
        return SalesLead::create(['branch_id' => $project->branch_id, 'project_id' => $project->id, 'sales_user_id' => $sales->id, 'lead_date' => today(), 'customer_name' => $name, 'source' => 'Test', 'platform' => 'Test', 'created_by' => $sales->id]);
    }

    private function agenda(User $sales, LeadMaster $project, string $title): ContentItem
    {
        return ContentItem::create(['branch_id' => $project->branch_id, 'project_name' => $project->project_name, 'item_type' => 'agenda', 'agenda_type' => ContentItem::SALES_AGENDA_TYPE, 'visibility' => 'personal', 'title' => $title, 'scheduled_date' => today(), 'status' => 'planned', 'owner_user_id' => $sales->id, 'sales_project_id' => $project->id, 'created_by' => $sales->id]);
    }

    private function enableFlag(): void
    {
        DB::table('app_settings')->updateOrInsert(['key' => 'org_hierarchical_monitoring'], ['value' => json_encode(true), 'group' => 'feature_flags', 'created_at' => now(), 'updated_at' => now()]);
        app(AppSettingResolver::class)->forget('org_hierarchical_monitoring');
    }
}
