<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ConsumerApplication;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrdV3D32ConsumerInputTest extends TestCase
{
    use RefreshDatabase;

    public function test_br32_sas_can_open_consumer_and_process_forms_in_own_branch(): void
    {
        [$branch, $project, $sas] = $this->context();
        $application = ConsumerApplication::factory()->create(['branch_id' => $branch->id, 'project_id' => $project->id]);
        $this->assertTrue($sas->fresh()->hasPermission('consumer_progress.manage'));

        $this->actingAs($sas)->get(route('consumer-database.workspace.create'))->assertOk();
        $this->actingAs($sas)->get(route('consumer-nups.create'))->assertOk();
        foreach (['slik', 'psjb', 'pemberkasan', 'bank', 'sp3k', 'ppjb', 'garansi', 'kendala'] as $process) {
            $this->actingAs($sas)->postJson(route('consumer-process.'.$process, $application), [])->assertStatus(422);
        }

        $this->actingAs($sas)->postJson(route('consumer-nups.store'), [
            'nup_number' => 'D32-NUP-001',
            'registered_at' => '2026-11-21',
            'name' => 'Konsumen SAS',
            'branch_id' => $branch->id,
            'project_id' => $project->id,
        ])->assertCreated();
    }

    public function test_br32_sas_is_denied_marison_reconciliation_route(): void
    {
        [, , $sas] = $this->context();

        $this->actingAs($sas)->get(route('admin.marison-migrations.reconciliation.index'))->assertForbidden();
    }

    public function test_br32_sas_is_denied_other_branch_for_direct_forms_and_json_input(): void
    {
        [, , $sas] = $this->context();
        $foreignBranch = Branch::create(['code' => 'D32-F', 'name' => 'Cabang Lain', 'is_active' => true]);
        $foreignProject = LeadMaster::create(['branch_id' => $foreignBranch->id, 'project_name' => 'Proyek Lain', 'is_active' => true]);
        $application = ConsumerApplication::factory()->create(['branch_id' => $foreignBranch->id, 'project_id' => $foreignProject->id]);

        $this->actingAs($sas)->get(route('workspace-v2.transactions.process-form', [$application, 'psjb']))->assertForbidden();
        $this->actingAs($sas)->postJson(route('consumer-nups.store'), [
            'nup_number' => 'D32-NUP-FOREIGN',
            'registered_at' => '2026-11-21',
            'name' => 'Di Luar Scope',
            'branch_id' => $foreignBranch->id,
            'project_id' => $foreignProject->id,
        ])->assertForbidden();
    }

    private function context(): array
    {
        $branch = Branch::create(['code' => 'D32', 'name' => 'Cabang D32', 'is_active' => true]);
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Proyek D32', 'is_active' => true]);
        $sas = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'sas')->value('id'),
            'branch_id' => $branch->id,
            'email_verified_at' => now(),
            'password_changed_at' => now(),
        ]);
        $sas->branches()->syncWithoutDetaching([$branch->id => [
            'is_current' => true,
            'can_view' => true,
            'can_edit' => true,
            'can_sync' => true,
            'can_manage_members' => false,
        ]]);
        $sas->assignedProjects()->syncWithoutDetaching([$project->id => ['is_active' => true, 'is_primary' => true]]);

        return [$branch, $project, $sas];
    }
}
