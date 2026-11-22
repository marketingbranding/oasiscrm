<?php

namespace Tests\Feature;

use App\Models\AccessGrant;
use App\Models\Branch;
use App\Models\ContentItem;
use App\Models\LeadMaster;
use App\Models\OrganizationAssignment;
use App\Models\Role;
use App\Models\SalesCoordinatorSales;
use App\Models\SalesDailyPlan;
use App\Models\SalesLead;
use App\Models\User;
use App\Services\AccessGrantService;
use App\Services\OptimisticLockService;
use App\Services\OrganizationScopeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class AccessGrantScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_br53_coordinator_view_grant_renders_foreign_lead_and_xlsx_export_contains_it(): void
    {
        $context = $this->context();

        $this->actingAs($context['coordinator'])
            ->get(route('sales-pocketbook.index', ['tab' => 'lead']))
            ->assertOk()
            ->assertDontSee('Lead Sales Lain');

        $this->grant($context['coordinator'], $context['foreignSales'], false);

        $this->actingAs($context['coordinator'])
            ->get(route('sales-pocketbook.index', ['tab' => 'lead']))
            ->assertOk()
            ->assertSee('Lead Sales Lain');

        $response = $this->actingAs($context['coordinator'])
            ->get(route('coordinator-leads.export'))
            ->assertOk();
        $workbook = IOFactory::load($response->baseResponse->getFile()->getPathname());
        $rows = collect($workbook->getActiveSheet()->toArray());

        $this->assertTrue($rows->flatten()->contains('Lead Sales Lain'));
    }

    public function test_br53_view_only_agenda_grant_renders_detail_but_forbids_submitted_correction(): void
    {
        Storage::fake('agenda_evidence');
        $context = $this->context();
        $agenda = $this->agenda($context['foreignSales'], $context['project'], 'Agenda Grant', 'planned');
        $plan = SalesDailyPlan::create([
            'branch_id' => $context['branch']->id,
            'sales_user_id' => $context['foreignSales']->id,
            'work_date' => today(),
            'status' => 'submitted',
        ]);
        $agenda->update(['daily_plan_id' => $plan->id]);
        $this->grant($context['coordinator'], $context['foreignSales'], false, 'sales_daily_plans');

        $this->actingAs($context['coordinator'])
            ->get(route('sales-agendas.show', $agenda))
            ->assertOk()
            ->assertSee('Agenda Grant');

        $this->actingAs($context['coordinator'])
            ->patch(route('sales-agendas.update', $agenda), [
                'activity_result' => 'Tidak boleh',
                'expected_updated_at' => app(OptimisticLockService::class)->token($agenda),
            ])
            ->assertForbidden();

        $this->assertSame('planned', $agenda->fresh()->status);
        $this->assertNull($agenda->fresh()->activity_result);
    }

    public function test_br53_edit_agenda_grant_updates_submitted_agenda(): void
    {
        Storage::fake('agenda_evidence');
        $context = $this->context();
        $agenda = $this->agenda($context['foreignSales'], $context['project'], 'Agenda Grant Edit', 'planned');
        $plan = SalesDailyPlan::create([
            'branch_id' => $context['branch']->id,
            'sales_user_id' => $context['foreignSales']->id,
            'work_date' => today(),
            'status' => 'submitted',
        ]);
        $agenda->update(['daily_plan_id' => $plan->id]);
        $this->grant($context['coordinator'], $context['foreignSales'], true, 'sales_daily_plans');
        Storage::disk('agenda_evidence')->put('grant-edit.jpg', 'evidence');
        $agenda->evidence()->create([
            'uploaded_by_user_id' => $context['foreignSales']->id,
            'storage_path' => 'grant-edit.jpg',
            'original_name' => 'grant-edit.jpg',
            'mime_type' => 'image/jpeg',
            'width' => 10,
            'height' => 10,
            'size_bytes' => 8,
            'sha256' => hash('sha256', 'evidence'),
        ]);

        $this->actingAs($context['coordinator'])
            ->patch(route('sales-agendas.update', $agenda), [
                'activity_result' => 'Hasil agenda diperbaiki',
                'expected_updated_at' => app(OptimisticLockService::class)->token($agenda),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('content_items', [
            'id' => $agenda->id,
            'activity_result' => 'Hasil agenda diperbaiki',
            'status' => 'done',
        ]);
    }

    public function test_br53_missing_expiry_returns_422_with_inline_error(): void
    {
        $context = $this->context();
        $superadmin = $this->user('superadmin', $context['branch'], 'Superadmin');

        $this->actingAs($superadmin)
            ->from(route('admin.access-grants.index'))
            ->post(route('admin.access-grants.store'), [
                'grantee_user_id' => $context['coordinator']->id,
                'subject_user_id' => $context['foreignSales']->id,
                'module' => 'sales_leads',
                'can_view' => 1,
                'can_edit' => 0,
                'reason' => 'Cuti',
            ])
            ->assertSessionHasErrors('expires_at');

        $this->assertDatabaseCount('access_grants', 0);
    }

    public function test_br53_include_descendants_true_includes_subordinate_leads(): void
    {
        $context = $this->context();
        $subordinate = $this->user('sales', $context['branch'], 'Sales Turunan');
        $subordinate->assignedProjects()->attach($context['project'], ['is_primary' => true, 'is_active' => true]);
        OrganizationAssignment::create([
            'user_id' => $subordinate->id,
            'parent_user_id' => $context['foreignSales']->id,
            'relationship_type' => OrganizationAssignment::REPORTS_TO,
            'branch_id' => $context['branch']->id,
            'started_at' => today(),
            'is_active' => true,
            'is_primary' => true,
            'source' => 'test',
        ]);
        $subordinateLead = $this->lead($subordinate, $context['project'], 'Lead Turunan');
        $grant = $this->grant($context['coordinator'], $context['foreignSales'], false);
        $grant->update(['include_descendants' => true]);

        $this->actingAs($context['coordinator'])
            ->get(route('sales-pocketbook.index', ['tab' => 'lead']))
            ->assertOk()
            ->assertSee('Lead Sales Lain')
            ->assertSee($subordinateLead->customer_name);
    }

    public function test_br53_include_descendants_false_excludes_subordinate_leads(): void
    {
        $context = $this->context();
        $subordinate = $this->user('sales', $context['branch'], 'Sales Turunan');
        $subordinate->assignedProjects()->attach($context['project'], ['is_primary' => true, 'is_active' => true]);
        OrganizationAssignment::create([
            'user_id' => $subordinate->id,
            'parent_user_id' => $context['foreignSales']->id,
            'relationship_type' => OrganizationAssignment::REPORTS_TO,
            'branch_id' => $context['branch']->id,
            'started_at' => today(),
            'is_active' => true,
            'is_primary' => true,
            'source' => 'test',
        ]);
        $subordinateLead = $this->lead($subordinate, $context['project'], 'Lead Turunan');
        $this->grant($context['coordinator'], $context['foreignSales'], false);

        $this->actingAs($context['coordinator'])
            ->get(route('sales-pocketbook.index', ['tab' => 'lead']))
            ->assertOk()
            ->assertSee('Lead Sales Lain')
            ->assertDontSee($subordinateLead->customer_name);
    }

    public function test_br53_out_of_workspace_subject_denies_lead_and_agenda_access(): void
    {
        Storage::fake('agenda_evidence');
        $context = $this->context();
        $outsideBranch = Branch::create(['name' => 'Magelang', 'code' => 'MGL', 'is_active' => true]);
        $outsideProject = LeadMaster::create(['branch_id' => $outsideBranch->id, 'project_name' => 'Magelang Project', 'is_active' => true]);
        $outsideSales = $this->user('sales', $outsideBranch, 'Sales Luar Workspace');
        $outsideSales->assignedProjects()->attach($outsideProject, ['is_primary' => true, 'is_active' => true]);
        $outsideLead = $this->lead($outsideSales, $outsideProject, 'Lead Luar Workspace');
        $outsideAgenda = $this->agenda($outsideSales, $outsideProject, 'Agenda Luar Workspace', 'planned');
        $this->grant($context['coordinator'], $outsideSales, true);
        $this->grant($context['coordinator'], $outsideSales, true, 'sales_daily_plans');

        $this->actingAs($context['coordinator'])->get(route('sales-leads.edit', $outsideLead))->assertForbidden();
        $this->actingAs($context['coordinator'])->get(route('sales-agendas.show', $outsideAgenda))->assertForbidden();
        $this->actingAs($context['coordinator'])->get(route('sales-pocketbook.index', ['tab' => 'lead']))
            ->assertOk()
            ->assertDontSee('Lead Luar Workspace');
        $response = $this->actingAs($context['coordinator'])->get(route('coordinator-leads.export'))->assertOk();
        $rows = collect(IOFactory::load($response->baseResponse->getFile()->getPathname())->getActiveSheet()->toArray());
        $this->assertFalse($rows->flatten()->contains('Lead Luar Workspace'));
    }

    public function test_br53_expired_grant_is_not_active_at_query_time(): void
    {
        $grantee = User::factory()->create();
        $subject = User::factory()->create();
        AccessGrant::create(['grantee_user_id' => $grantee->id, 'subject_user_id' => $subject->id, 'module' => 'sales_leads', 'can_view' => true, 'expires_at' => now()->addDay(), 'reason' => 'cuti', 'granted_by' => $grantee->id]);
        Carbon::setTestNow(now()->addDays(2));
        $this->assertSame([], app(AccessGrantService::class)->grantedUserIds($grantee, 'sales_leads'));
        Carbon::setTestNow();
    }

    public function test_br53_expiry_beyond_setting_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $grantee = User::factory()->create();
        $subject = User::factory()->create();
        $actor = User::factory()->create(['role_id' => Role::query()->where('slug', 'superadmin')->value('id')]);
        app(AccessGrantService::class)->create($actor, ['grantee' => $grantee, 'subject' => $subject, 'module' => 'sales_leads', 'can_view' => true, 'can_edit' => false, 'expires_at' => now()->addDays(91), 'reason' => 'cuti']);
    }

    public function test_br53_coordinator_without_grant_cannot_view_foreign_lead_direct_url(): void
    {
        $context = $this->context();
        $this->actingAs($context['coordinator'])->get(route('sales-leads.edit', $context['foreignLead']))->assertForbidden();
    }

    public function test_br53_daily_plan_grant_does_not_open_lead_or_work_planner_scope(): void
    {
        $context = $this->context();
        $this->grant($context['coordinator'], $context['foreignSales'], false, 'sales_daily_plans');

        $scope = app(OrganizationScopeService::class);
        $this->assertNotContains($context['foreignSales']->id, $scope->visibleUserIds($context['coordinator'], 'sales_pocketbook', 'view', 'sales_leads'));
        $this->assertNotContains($context['foreignSales']->id, $scope->visibleUserIds($context['coordinator'], 'work_planner', 'view'));
    }

    public function test_br53_lead_grant_does_not_open_daily_plan_or_work_planner_scope(): void
    {
        $context = $this->context();
        $this->grant($context['coordinator'], $context['foreignSales'], false, 'sales_leads');

        $scope = app(OrganizationScopeService::class);
        $this->assertNotContains($context['foreignSales']->id, $scope->visibleUserIds($context['coordinator'], 'sales_pocketbook', 'view', 'sales_daily_plans'));
        $this->assertNotContains($context['foreignSales']->id, $scope->visibleUserIds($context['coordinator'], 'work_planner', 'view'));
    }

    public function test_br53_active_view_grant_exposes_foreign_lead_but_not_edit(): void
    {
        $context = $this->context();
        $this->grant($context['coordinator'], $context['foreignSales'], false);
        $this->actingAs($context['coordinator'])->get(route('sales-pocketbook.index', ['tab' => 'lead']))->assertOk()->assertSee('Lead Sales Lain');
        $this->actingAs($context['coordinator'])->get(route('sales-leads.edit', $context['foreignLead']))->assertForbidden();
    }

    public function test_br53_edit_grant_allows_foreign_lead_update_but_not_transfer(): void
    {
        $context = $this->context();
        $this->grant($context['coordinator'], $context['foreignSales'], true);
        $this->actingAs($context['coordinator'])->get(route('sales-leads.edit', $context['foreignLead']))->assertOk();
        $this->actingAs($context['coordinator'])->post(route('coordinator-leads.transfers.store'), ['lead_ids' => [$context['foreignLead']->id], 'target_user_id' => $context['sales']->id, 'reason' => 'Penyesuaian pembagian tim'])->assertSessionHasErrors('lead_ids');
        $this->assertSame($context['foreignSales']->id, $context['foreignLead']->fresh()->sales_user_id);
    }

    public function test_br53_non_superadmin_cannot_manage_grants_by_direct_url_or_json(): void
    {
        $context = $this->context();
        $this->actingAs($context['coordinator'])->get(route('admin.access-grants.index'))->assertForbidden();
        $this->actingAs($context['coordinator'])->getJson(route('admin.access-grants.index'))->assertForbidden();
    }

    public function test_br53_non_coordinator_can_not_receive_edit_grant(): void
    {
        $context = $this->context();
        $this->expectException(ValidationException::class);
        app(AccessGrantService::class)->create($this->user('superadmin', $context['branch'], 'Superadmin'), [
            'grantee' => $context['sales'], 'subject' => $context['foreignSales'], 'module' => 'sales_leads',
            'can_view' => true, 'can_edit' => true, 'expires_at' => now()->addDays(2), 'reason' => 'Cuti',
        ]);
    }

    public function test_br53_revoked_grant_loses_access_immediately(): void
    {
        $context = $this->context();
        $grant = $this->grant($context['coordinator'], $context['foreignSales'], false);
        app(AccessGrantService::class)->revoke($this->user('superadmin', $context['branch'], 'Superadmin'), $grant, 'Selesai');
        $this->assertSame([], app(AccessGrantService::class)->grantedUserIds($context['coordinator'], 'sales_leads'));
    }

    public function test_br53_superadmin_create_and_revoke_are_audited_and_notify_grantee(): void
    {
        $context = $this->context();
        $superadmin = $this->user('superadmin', $context['branch'], 'Superadmin');
        $response = $this->actingAs($superadmin)->post(route('admin.access-grants.store'), [
            'grantee_user_id' => $context['coordinator']->id,
            'subject_user_id' => $context['foreignSales']->id,
            'module' => 'sales_leads', 'can_view' => 1, 'can_edit' => 0,
            'expires_at' => now()->addDays(2)->format('Y-m-d H:i'), 'reason' => 'Cuti',
        ])->assertRedirect();
        $grant = AccessGrant::query()->latest('id')->firstOrFail();
        $this->assertDatabaseHas('activity_log', ['event' => 'access_grant_created', 'subject_id' => $grant->id]);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $context['coordinator']->id, 'type' => 'access_grant_created']);
        $this->actingAs($superadmin)->patch(route('admin.access-grants.revoke', $grant), ['reason' => 'Kembali bekerja'])->assertRedirect();
        $this->assertDatabaseHas('activity_log', ['event' => 'access_grant_revoked', 'subject_id' => $grant->id]);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $context['coordinator']->id, 'type' => 'access_grant_revoked']);
    }

    private function grant(User $grantee, User $subject, bool $edit, string $module = 'sales_leads'): AccessGrant
    {
        return AccessGrant::create(['grantee_user_id' => $grantee->id, 'subject_user_id' => $subject->id, 'module' => $module, 'can_view' => true, 'can_edit' => $edit, 'expires_at' => now()->addDays(2), 'reason' => 'cuti', 'granted_by' => $grantee->id]);
    }

    /** @return array<string, mixed> */
    private function context(): array
    {
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Solo Project', 'is_active' => true]);
        $sales = $this->user('sales', $branch, 'Sales Tim');
        $foreignSales = $this->user('sales', $branch, 'Sales Lain');
        $coordinator = $this->user('sales_coordinator', $branch, 'Tim');
        foreach ([$sales, $foreignSales] as $salesUser) {
            $salesUser->assignedProjects()->attach($project, ['is_primary' => true, 'is_active' => true]);
        }
        SalesCoordinatorSales::create(['coordinator_user_id' => $coordinator->id, 'sales_user_id' => $sales->id]);

        return ['branch' => $branch, 'project' => $project, 'sales' => $sales, 'foreignSales' => $foreignSales, 'coordinator' => $coordinator, 'foreignLead' => $this->lead($foreignSales, $project, 'Lead Sales Lain')];
    }

    private function user(string $roleSlug, Branch $branch, string $name): User
    {
        return User::factory()->create(['name' => $name, 'role_id' => Role::query()->where('slug', $roleSlug)->value('id'), 'branch_id' => $branch->id, 'password_changed_at' => now()]);
    }

    private function lead(User $sales, LeadMaster $project, string $name): SalesLead
    {
        return SalesLead::create(['branch_id' => $project->branch_id, 'project_id' => $project->id, 'sales_user_id' => $sales->id, 'lead_date' => today(), 'customer_name' => $name, 'phone' => '081234567890', 'source' => 'Online', 'platform' => 'Instagram', 'current_status' => 'no_response', 'created_by' => $sales->id, 'updated_by' => $sales->id]);
    }

    private function agenda(User $sales, LeadMaster $project, string $title, string $status): ContentItem
    {
        return ContentItem::create([
            'branch_id' => $project->branch_id,
            'project_name' => $project->project_name,
            'sales_project_id' => $project->id,
            'item_type' => 'agenda',
            'agenda_type' => ContentItem::SALES_AGENDA_TYPE,
            'visibility' => 'personal',
            'title' => $title,
            'sales_activity_category' => 'Follow-up',
            'scheduled_date' => today(),
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
            'duration_minutes' => 60,
            'status' => $status,
            'owner_user_id' => $sales->id,
            'created_by' => $sales->id,
        ]);
    }
}
