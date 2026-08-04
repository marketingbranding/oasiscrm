<?php

namespace Tests\Feature;

use App\Enums\SalesLeadStatus;
use App\Exceptions\SalesLeadSpreadsheetContractException;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\ContentItem;
use App\Models\LeadMaster;
use App\Models\LeadSource;
use App\Models\Role;
use App\Models\SalesLead;
use App\Models\SalesLeadLifecycleSyncStatus;
use App\Models\User;
use App\Services\OptimisticLockService;
use App\Services\PhoneNormalizationService;
use App\Services\SalesLeadSheetOptionService;
use App\Services\SalesLeadSpreadsheetWriter;
use App\Services\SalesSheetIdentityService;
use App\ValueObjects\SalesLeadSpreadsheetWriteResult;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class SalesPocketbookTest extends TestCase
{
    use RefreshDatabase;

    public function test_phone_normalization_handles_local_country_and_formatted_numbers(): void
    {
        $service = app(PhoneNormalizationService::class);

        $this->assertSame('628123456789', $service->normalize('0812-3456-789'));
        $this->assertSame('628123456789', $service->normalize('+62 812 3456 789'));
        $this->assertSame('628123456789', $service->normalize('8123456789'));
    }

    public function test_schema_and_stage_contract_use_only_corrected_domain_names(): void
    {
        foreach (['sales_user_id', 'customer_name', 'contacted_at', 'met_at', 'surveyed_at', 'utj_at', 'documents_completed_at', 'akad_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('sales_leads', $column), "Missing {$column}");
        }
        foreach (['sales_id', 'name', 'follow_up_at', 'visit_at', 'booking_at'] as $column) {
            $this->assertFalse(Schema::hasColumn('sales_leads', $column), "Unexpected {$column}");
        }
        $this->assertSame([
            'contacted_at' => 'DIHUBUNGI',
            'met_at' => 'TATAP MUKA',
            'surveyed_at' => 'SURVEY',
            'utj_at' => 'UTJ',
            'documents_completed_at' => 'BERKAS AWAL LENGKAP',
            'akad_at' => 'AKAD',
        ], SalesLead::STAGES);
    }

    public function test_lead_sources_have_exact_canonical_active_taxonomy(): void
    {
        $this->assertSame([
            'Canvasing', 'Event', 'Freelance', 'Iklan Pusat', 'Online', 'Pameran', 'Refferal',
        ], LeadSource::where('is_active', true)->orderBy('name')->pluck('name')->all());
        $this->assertTrue(LeadSource::where('name', 'Referensi')->where('is_active', false)->exists());
    }

    public function test_lead_source_standardization_deactivates_custom_sources_without_rewriting_creation_time(): void
    {
        $migration = require database_path('migrations/2026_07_27_000005_standardize_lead_sources.php');
        $migration->down();

        $canonical = LeadSource::where('name', 'Online')->firstOrFail();
        $createdAt = $canonical->created_at;
        $custom = LeadSource::create(['name' => 'Sumber Custom', 'is_active' => true]);
        $inactiveCustom = LeadSource::create(['name' => 'Sumber Nonaktif', 'is_active' => false]);

        $migration->up();

        $this->assertSame(0, $custom->fresh()->is_active);
        $this->assertTrue($canonical->fresh()->created_at->equalTo($createdAt));
        $this->assertSame(7, LeadSource::where('is_active', true)->count());

        $stableTimestamp = '2020-01-01 00:00:00';
        DB::table('lead_sources')->whereIn('id', [$canonical->id, $custom->id])->update(['updated_at' => $stableTimestamp]);
        $migration->up();
        $this->assertSame($stableTimestamp, $canonical->fresh()->updated_at->format('Y-m-d H:i:s'));
        $this->assertSame($stableTimestamp, $custom->fresh()->updated_at->format('Y-m-d H:i:s'));

        $migration->down();
        $this->assertSame(1, $custom->fresh()->is_active);
        $this->assertSame(0, $inactiveCustom->fresh()->is_active);
    }

    public function test_historical_inactive_source_can_be_retained_but_not_selected_for_another_lead(): void
    {
        [, $project, $sales] = $this->salesContext();
        $legacy = LeadSource::where('name', 'Referensi')->firstOrFail();
        $otherLegacy = LeadSource::where('name', 'Website')->firstOrFail();
        $lead = $this->lead($sales, $project);
        $lead->update(['lead_source_id' => $legacy->id, 'source_name_snapshot' => 'Referensi Historis']);

        $this->actingAs($sales)->put(route('sales-leads.update', $lead), $this->payload($sales, $project, [
            'lead_source_id' => $legacy->id,
            'expected_updated_at' => app(OptimisticLockService::class)->token($lead),
        ]))->assertRedirect();
        $this->assertSame('Referensi Historis', $lead->fresh()->source_name_snapshot);

        $this->actingAs($sales)->put(route('sales-leads.update', $lead->fresh()), $this->payload($sales, $project, [
            'lead_source_id' => $otherLegacy->id,
            'expected_updated_at' => app(OptimisticLockService::class)->token($lead->fresh()),
        ]))->assertSessionHasErrors('lead_source_id');
    }

    public function test_sales_scope_only_exposes_own_records_even_in_same_branch(): void
    {
        [$branch, $project, $sales] = $this->salesContext();
        $other = $this->sales($branch, $project, 'Other Sales');
        $own = $this->lead($sales, $project, 'Own');
        $this->lead($other, $project, 'Other');

        $this->assertEquals([$own->id], SalesLead::visibleTo($sales)->pluck('id')->all());
        $response = $this->actingAs($sales)->get(route('sales-pocketbook.index'))->assertOk();
        $response->assertSee('Own')->assertDontSee('Other');
    }

    public function test_branch_manager_sees_accessible_branch_and_global_user_sees_all(): void
    {
        [$branch, $project, $sales] = $this->salesContext();
        $otherBranch = Branch::create(['name' => 'Pati', 'code' => 'PTI', 'is_active' => true]);
        $otherProject = $this->project($otherBranch, 'Pati Project');
        $otherSales = $this->sales($otherBranch, $otherProject, 'Pati Sales');
        $this->lead($sales, $project, 'Solo Lead');
        $this->lead($otherSales, $otherProject, 'Pati Lead');
        $manager = $this->user('manager', $branch);
        $pusat = $this->user('pusat');

        $this->assertSame(1, SalesLead::visibleTo($manager)->count());
        $this->assertSame(2, SalesLead::visibleTo($pusat)->count());
        $this->actingAs($pusat)->get(route('sales-pocketbook.index'))
            ->assertOk()->assertSee('Solo Lead')->assertSee('Pati Lead');
    }

    public function test_sales_can_create_for_self_on_assigned_active_project_and_activity_is_pii_safe(): void
    {
        [$branch, $project, $sales] = $this->salesContext();
        $this->mockLeadWriter();
        $payload = $this->payload($sales, $project, ['phone' => '0812 9999 1111', 'customer_name' => 'Sensitive Name']);

        $this->actingAs($sales)->post(route('sales-leads.store'), $payload)
            ->assertRedirect(route('sales-pocketbook.index'))
            ->assertSessionHas('success', 'Lead berhasil disimpan.');

        $lead = SalesLead::firstOrFail();
        $this->assertSame('6281299991111', $lead->normalized_phone);
        $log = ActivityLog::where('subject_type', SalesLead::class)->where('subject_id', $lead->id)->firstOrFail();
        $encoded = json_encode($log->properties);
        $this->assertStringNotContainsString('Sensitive Name', $encoded);
        $this->assertStringNotContainsString('0812', $encoded);
    }

    public function test_phone_is_nullable_source_snapshot_is_stored_and_add_another_preserves_context(): void
    {
        [$branch, $project, $sales] = $this->salesContext();
        $this->mockLeadWriter();
        $source = LeadSource::where('is_active', true)->firstOrFail();
        $payload = $this->payload($sales, $project, [
            'phone' => null,
            'lead_date' => '2026-07-21',
            'lead_source_id' => $source->id,
            'submit_action' => 'add_another',
        ]);

        $redirect = route('sales-pocketbook.index', ['input' => 1, 'lead_date' => '2026-07-21', 'project_id' => $project->id]);
        $this->actingAs($sales)->post(route('sales-leads.store'), $payload)->assertRedirect($redirect);

        $lead = SalesLead::firstOrFail();
        $this->assertNull($lead->phone);
        $this->assertNull($lead->normalized_phone);
        $this->assertSame($source->name, $lead->source_name_snapshot);
        $this->actingAs($sales)->get($redirect)->assertOk()
            ->assertSee('name="lead_date" value="2026-07-21"', false)
            ->assertSee('value="'.$project->id.'" data-branch="'.$branch->id.'" selected', false);
    }

    public function test_create_is_limited_to_sales_and_global_roles_and_quick_form_follows_policy(): void
    {
        [$branch, $project, $sales] = $this->salesContext();
        $manager = $this->user('manager', $branch);
        $admin = $this->user('admin', $branch);
        $pusat = $this->user('pusat');

        foreach ([$manager, $admin] as $monitor) {
            $this->actingAs($monitor)->get(route('sales-pocketbook.index'))->assertOk()->assertDontSee('+ Input Lead Hari Ini');
            $this->actingAs($monitor)->post(route('sales-leads.store'), $this->payload($sales, $project))->assertForbidden();
        }
        $this->actingAs($sales)->get(route('sales-pocketbook.index'))->assertOk()->assertSee('+ Input Lead Hari Ini');
        $this->actingAs($pusat)->get(route('sales-pocketbook.index'))->assertOk()->assertSee('+ Input Lead Hari Ini');
    }

    public function test_sales_without_project_sees_assignment_empty_state(): void
    {
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);
        $sales = $this->user('sales', $branch);

        $this->actingAs($sales)->get(route('sales-pocketbook.index'))
            ->assertOk()
            ->assertSee('Anda belum ditugaskan ke proyek. Hubungi admin pusat.')
            ->assertDontSee('+ Input Lead Hari Ini');
    }

    public function test_sales_cannot_select_another_owner_or_unassigned_or_inactive_project(): void
    {
        [$branch, $project, $sales] = $this->salesContext();
        $other = $this->sales($branch, $project, 'Other');
        $unassigned = $this->project($branch, 'Unassigned');

        $this->actingAs($sales)->post(route('sales-leads.store'), $this->payload($other, $project))
            ->assertSessionHasErrors('sales_user_id');
        $this->actingAs($sales)->post(route('sales-leads.store'), $this->payload($sales, $unassigned))
            ->assertSessionHasErrors('project_id');
        $project->update(['is_active' => false]);
        $this->actingAs($sales)->post(route('sales-leads.store'), $this->payload($sales, $project))
            ->assertSessionHasErrors('project_id');
    }

    public function test_inaccessible_branch_record_returns_403_before_update_or_conflict_details(): void
    {
        [$branch, $project, $sales] = $this->salesContext();
        $lead = $this->lead($sales, $project);
        $otherBranch = Branch::create(['name' => 'Pati', 'code' => 'PTI', 'is_active' => true]);
        $outsider = $this->user('admin', $otherBranch);

        $this->actingAs($outsider)->putJson(route('sales-leads.update', $lead), $this->payload($sales, $project, [
            'expected_updated_at' => '2000-01-01 00:00:00',
        ]))->assertForbidden();
    }

    public function test_authorized_update_changes_data_and_tracks_modifier_without_logging_pii(): void
    {
        [$branch, $project, $sales] = $this->salesContext();
        $lead = $this->lead($sales, $project, 'Before');

        $this->actingAs($sales)->putJson(route('sales-leads.update', $lead), $this->payload($sales, $project, [
            'customer_name' => 'After',
            'phone' => '0813-0000-0000',
            'expected_updated_at' => app(OptimisticLockService::class)->token($lead),
        ]))->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('message', 'Lead berhasil diperbarui.')
            ->assertJsonPath('lead.source_active', true);

        $lead->refresh();
        $this->assertSame('After', $lead->customer_name);
        $this->assertSame('6281300000000', $lead->normalized_phone);
        $this->assertSame($sales->id, $lead->updated_by);
        $log = ActivityLog::where('subject_type', SalesLead::class)->where('subject_id', $lead->id)->where('event', 'updated')->firstOrFail();
        $this->assertStringNotContainsString('After', json_encode($log->properties));
    }

    public function test_edit_route_is_scoped_by_policy_and_renders_date_picker_and_optimistic_token(): void
    {
        [$branch, $project, $sales] = $this->salesContext();
        $lead = $this->lead($sales, $project, 'Editable');
        $otherSales = $this->sales($branch, $project, 'Other Sales');
        $manager = $this->user('manager', $branch);
        $global = $this->user('pusat');
        $otherBranch = Branch::create(['name' => 'Pati', 'code' => 'PTI', 'is_active' => true]);
        $outsider = $this->user('manager', $otherBranch);

        $this->actingAs($sales)->get(route('sales-leads.edit', $lead))->assertOk()
            ->assertSee('Editable')->assertSee('date-wrapper', false)
            ->assertSee('name="expected_updated_at" value="'.app(OptimisticLockService::class)->token($lead).'"', false);
        $this->actingAs($manager)->get(route('sales-leads.edit', $lead))->assertOk();
        $this->actingAs($global)->get(route('sales-leads.edit', $lead))->assertOk();
        $this->actingAs($otherSales)->get(route('sales-leads.edit', $lead))->assertForbidden();
        $this->actingAs($outsider)->get(route('sales-leads.edit', $lead))->assertForbidden();
    }

    public function test_updating_source_refreshes_snapshot(): void
    {
        [$branch, $project, $sales] = $this->salesContext();
        $lead = $this->lead($sales, $project);
        $source = LeadSource::create(['name' => 'Partner Baru', 'is_active' => true]);

        $this->actingAs($sales)->put(route('sales-leads.update', $lead), $this->payload($sales, $project, [
            'lead_source_id' => $source->id,
            'expected_updated_at' => app(OptimisticLockService::class)->token($lead),
        ]))->assertRedirect(route('sales-pocketbook.index'));

        $this->assertSame('Partner Baru', $lead->fresh()->source_name_snapshot);
    }

    public function test_duplicate_warning_never_reveals_an_unauthorized_branch(): void
    {
        [$branch, $project, $sales] = $this->salesContext();
        $this->lead($sales, $project, 'Visible', '08123456789');
        $otherBranch = Branch::create(['name' => 'Secret Branch', 'code' => 'SEC', 'is_active' => true]);
        $otherProject = $this->project($otherBranch, 'Secret Project');
        $secretSales = $this->sales($otherBranch, $otherProject, 'Secret Sales');
        $this->lead($secretSales, $otherProject, 'Secret', '+62 812 345 6789');

        $response = $this->actingAs($sales)->getJson(route('sales-leads.duplicate-phone', ['phone' => '0812-3456-789']))
            ->assertOk()->assertJsonCount(1, 'matches')->assertJsonPath('matches.0.sales', $sales->name);
        $response->assertJsonMissing(['branch' => 'Secret Branch'])->assertJsonMissing(['sales' => 'Secret Sales']);
    }

    public function test_stage_date_and_order_are_validated_while_earlier_stage_can_be_added(): void
    {
        [$branch, $project, $sales] = $this->salesContext();
        $lead = $this->lead($sales, $project);
        $lead->update(['utj_at' => Carbon::parse('2026-07-10 10:00:00')]);

        $this->actingAs($sales)->patchJson(route('sales-leads.stage.update', $lead), $this->stagePayload($lead, 'met_at', '2026-07-09 10:00:00'))
            ->assertOk()
            ->assertJsonPath('message', 'Tahap lead berhasil diperbarui.')
            ->assertJsonPath('stages.met_at', Carbon::parse('2026-07-09 10:00:00')->toIso8601String());
        $lead->refresh();
        $response = $this->actingAs($sales)->patchJson(route('sales-leads.stage.update', $lead), $this->stagePayload($lead, 'surveyed_at', '2026-07-11 10:00:00'));
        $this->assertSame(422, $response->getStatusCode(), $response->getContent());
        $this->assertSame('Waktu progres harus berurutan sebelum tahap berikutnya.', $response->json('errors.timestamp.0'));
        $response = $this->actingAs($sales)->patchJson(route('sales-leads.stage.update', $lead), $this->stagePayload($lead, 'contacted_at', '2025-01-01 10:00:00'));
        $this->assertSame(422, $response->getStatusCode(), $response->getContent());
        $this->assertSame('Waktu progres tidak boleh sebelum tanggal lead.', $response->json('errors.timestamp.0'));
    }

    public function test_sales_cannot_reverse_but_manager_can_with_confirmation_and_clears_later_stages(): void
    {
        [$branch, $project, $sales] = $this->salesContext();
        $lead = $this->lead($sales, $project);
        $lead->update(['met_at' => now(), 'surveyed_at' => now()->addHour(), 'utj_at' => now()->addHours(2)]);
        $payload = ['stage' => 'surveyed_at', 'action' => 'reverse', 'reversal_confirmed' => 1, 'expected_updated_at' => app(OptimisticLockService::class)->token($lead)];

        $this->actingAs($sales)->patchJson(route('sales-leads.stage.update', $lead), $payload)->assertForbidden();
        $manager = $this->user('manager', $branch);
        $this->actingAs($manager)->patchJson(route('sales-leads.stage.update', $lead), array_diff_key($payload, ['reversal_confirmed' => true]))
            ->assertUnprocessable()->assertJsonStructure(['errors' => ['reversal_confirmed']]);
        $this->actingAs($manager)->patchJson(route('sales-leads.stage.update', $lead), $payload)
            ->assertOk()->assertJsonPath('message', 'Tahap lead berhasil dibatalkan.');
        $lead->refresh();
        $this->assertNotNull($lead->met_at);
        $this->assertNull($lead->surveyed_at);
        $this->assertNull($lead->utj_at);
        $this->assertDatabaseHas('activity_log', ['subject_type' => SalesLead::class, 'subject_id' => $lead->id, 'event' => 'stage_reversed']);
    }

    public function test_optimistic_conflict_does_not_overwrite_stage_or_lead(): void
    {
        [$branch, $project, $sales] = $this->salesContext();
        $lead = $this->lead($sales, $project, 'Current');
        $stale = Carbon::parse($lead->updated_at)->subSecond()->utc()->format('Y-m-d H:i:s');

        $this->actingAs($sales)->patchJson(route('sales-leads.stage.update', $lead), [
            'stage' => 'contacted_at', 'action' => 'set', 'timestamp' => now(), 'expected_updated_at' => $stale,
        ])->assertConflict()->assertJsonPath('code', 'record_modified')->assertJsonPath('record_type', 'sales_lead');
        $this->assertNull($lead->fresh()->contacted_at);

        $this->actingAs($sales)->putJson(route('sales-leads.update', $lead), $this->payload($sales, $project, [
            'customer_name' => 'Stale Name', 'expected_updated_at' => $stale,
        ]))->assertConflict();
        $this->assertSame('Current', $lead->fresh()->customer_name);
    }

    public function test_monitoring_filters_project_and_branch_and_menu_visibility_is_role_limited(): void
    {
        [$branch, $project, $sales] = $this->salesContext();
        $this->lead($sales, $project, 'Selected Lead');
        $manager = $this->user('manager', $branch);
        $viewer = $this->user('staff', $branch);

        $this->actingAs($manager)->get(route('sales-pocketbook.index', ['branch_id' => $branch->id, 'project_id' => $project->id]))
            ->assertOk()->assertSee('Selected Lead')->assertSee('Buku Saku Sales');
        $this->assertSame(0, SalesLead::visibleTo($viewer)->count());
        $this->actingAs($viewer)->get(route('dashboard'))->assertOk()->assertDontSee('Buku Saku Sales');
        $this->actingAs($viewer)->get(route('sales-pocketbook.index'))->assertForbidden();
    }

    public function test_stage_ui_updates_from_response_without_reload_and_restores_button_on_errors(): void
    {
        $view = file_get_contents(resource_path('views/crm/sales-pocketbook/index.blade.php'));

        $this->assertStringContainsString('data.stages[stageButton.dataset.stage]', $view);
        $this->assertStringContainsString('group.dataset.token = data.updated_at', $view);
        $this->assertStringContainsString('stageModalOpen', $view);
        $this->assertStringContainsString('finally { this.stageSaving = false }', $view);
        $this->assertStringContainsString('window.oasisToast', $view);
        $this->assertStringContainsString('response.status === 409', $view);
        $this->assertStringContainsString("new CustomEvent('oasis-conflict'", $view);
        $this->assertStringNotContainsString("session('success')", $view);
        $this->assertStringNotContainsString("session('warning')", $view);
        $this->assertStringNotContainsString("session('conflict')", $view);
        $this->assertStringNotContainsString('leadEditError', $view);
        $this->assertStringNotContainsString('stageError', $view);
        $this->assertStringContainsString('leadValidationError', $view);
        $this->assertStringContainsString('stageValidationError', $view);
        $this->assertStringContainsString('response.status === 422', $view);
        $this->assertStringContainsString('conflictDialogOpen()', $view);
        $this->assertSame(4, substr_count($view, 'if (!conflictDialogOpen())'));
        $this->assertStringNotContainsString('prompt(', $view);
        $this->assertStringNotContainsString('toISOString', $view);
        $this->assertStringNotContainsString('window.location.reload()', $view);
    }

    public function test_monitoring_filters_render_branch_project_sales_order_and_reject_inconsistent_scope(): void
    {
        [$branch, $project, $sales] = $this->salesContext();
        $otherProject = $this->project($branch, 'Other Project');
        $otherSales = $this->sales($branch, $otherProject, 'Other Sales');
        $manager = $this->user('manager', $branch);

        $response = $this->actingAs($manager)->get(route('sales-pocketbook.index'));
        $response->assertOk()->assertSeeInOrder(['name="branch_id"', 'name="project_id"', 'name="sales_user_id"'], false)
            ->assertSee('salesCascade', false)->assertSee('sales_ids', false);

        $this->actingAs($manager)->get(route('sales-pocketbook.index', [
            'branch_id' => $branch->id, 'project_id' => $project->id, 'sales_user_id' => $otherSales->id,
        ]))->assertSessionHasErrors('sales_user_id');
        $this->assertNotSame($sales->id, $otherSales->id);
    }

    public function test_period_modes_are_exclusive_and_render_compact_picker(): void
    {
        [, , $sales] = $this->salesContext();
        $this->actingAs($sales)->get(route('sales-pocketbook.index', ['week' => '2026-07-20']))
            ->assertSessionHasErrors('period_type');
        $this->actingAs($sales)->get(route('sales-pocketbook.index', [
            'period_type' => 'week', 'week' => '2026-07-20', 'date_from' => '2026-07-20',
        ]))->assertSessionHasErrors('date_from');
        $this->actingAs($sales)->get(route('sales-pocketbook.index', [
            'period_type' => 'custom', 'date_from' => '2026-07-20', 'date_to' => '2026-07-26', 'week' => '2026-07-20',
        ]))->assertSessionHasErrors('week');
        $this->actingAs($sales)->get(route('sales-pocketbook.index', ['tab' => 'agenda']))
            ->assertOk()
            ->assertSee('Pilih Periode')
            ->assertSee('name="period_type"', false)
            ->assertSee('aria-label="Tutup pilihan periode"', false)
            ->assertSee('@keydown.escape="if (open) { $event.stopPropagation(); open = false; $nextTick(() => $refs.periodTrigger?.focus()) }"', false)
            ->assertSee('x-show="open" x-cloak', false)
            ->assertDontSee('periodPicker(', false);
    }

    public function test_time_picker_contract_is_shared_by_sales_work_planner_and_database(): void
    {
        $salesView = file_get_contents(resource_path('views/crm/sales-pocketbook/index.blade.php'));
        $plannerView = file_get_contents(resource_path('views/crm/content-calendar/_form.blade.php'));
        $databaseView = file_get_contents(resource_path('views/crm/database/index.blade.php'));
        $picker = file_get_contents(resource_path('js/crm-timepicker.js'));

        $this->assertStringContainsString('x-crm.time-field', $salesView);
        $this->assertStringContainsString('x-crm.time-field', $plannerView);
        $this->assertStringContainsString('time-wrapper', $databaseView);
        foreach (['Sekarang', 'time-hours', 'time-minutes', 'MutationObserver', 'oasis:picker-open', 'Escape'] as $contract) {
            $this->assertStringContainsString($contract, $picker);
        }
        $this->assertStringContainsString("input.value || '00:00'", $picker);
        $this->assertStringContainsString('focus({ preventScroll: true })', $picker);
        $this->assertStringNotContainsString('input.value || currentTime()', $picker);
        $this->assertStringContainsString('if (nearest) markWheelSelection(wheel, nearest.dataset.value)', $picker);
        $this->assertStringNotContainsString('if (nearest) selectWheel(wheel, nearest.dataset.value)', $picker);
    }

    public function test_quick_agenda_uses_content_item_with_sales_owner_project_and_branch(): void
    {
        [$branch, $project, $sales] = $this->salesContext();

        $this->actingAs($sales)->post(route('sales-agendas.store'), $this->agendaPayload($sales, $project))
            ->assertRedirect(route('sales-pocketbook.index', ['tab' => 'agenda']))
            ->assertSessionHas('success', 'Agenda sales berhasil ditambahkan.');

        $agenda = ContentItem::sole();
        $this->assertSame('agenda', $agenda->item_type);
        $this->assertSame(ContentItem::SALES_AGENDA_TYPE, $agenda->agenda_type);
        $this->assertSame($sales->id, $agenda->owner_user_id);
        $this->assertSame($project->id, $agenda->sales_project_id);
        $this->assertSame($branch->id, $agenda->branch_id);
        $this->assertSame($project->project_name, $agenda->project_name);
        $this->assertSame('personal', $agenda->visibility);
        $this->assertTrue($agenda->assignees->contains($sales));
        $this->assertSame(90, $agenda->duration_minutes);
        $this->assertFalse(Schema::hasTable('sales_agendas'));
    }

    public function test_sales_agenda_requires_an_assigned_active_project_and_own_owner(): void
    {
        [$branch, $project, $sales] = $this->salesContext();
        $other = $this->sales($branch, $project, 'Other Sales');
        $unassigned = $this->project($branch, 'Unassigned');

        $this->actingAs($sales)->post(route('sales-agendas.store'), $this->agendaPayload($other, $project))
            ->assertSessionHasErrors('owner_user_id');
        $this->actingAs($sales)->post(route('sales-agendas.store'), $this->agendaPayload($sales, $unassigned))
            ->assertSessionHasErrors('project_id');
        $project->update(['is_active' => false]);
        $this->actingAs($sales)->post(route('sales-agendas.store'), $this->agendaPayload($sales, $project))
            ->assertSessionHasErrors('project_id');
        $this->assertDatabaseCount('content_items', 0);
    }

    public function test_sales_agenda_rejects_end_time_not_after_start_time(): void
    {
        [, $project, $sales] = $this->salesContext();

        $this->actingAs($sales)->post(route('sales-agendas.store'), $this->agendaPayload($sales, $project, [
            'start_time' => '10:00', 'end_time' => '09:59',
        ]))->assertSessionHasErrors('end_time');
        $this->assertDatabaseCount('content_items', 0);
    }

    public function test_sales_agenda_scope_blocks_other_sales_and_limits_manager_to_branch(): void
    {
        [$branch, $project, $sales] = $this->salesContext();
        $agenda = $this->agenda($sales, $project);
        $other = $this->sales($branch, $project, 'Other Sales');
        $manager = $this->user('manager', $branch);
        $otherBranch = Branch::create(['name' => 'Pati', 'code' => 'PTI', 'is_active' => true]);
        $outsider = $this->user('manager', $otherBranch);
        $payload = ['activity_result' => 'Berhasil', 'expected_updated_at' => app(OptimisticLockService::class)->token($agenda)];

        $this->actingAs($other)->patch(route('sales-agendas.update', $agenda), $payload)->assertForbidden();
        $this->actingAs($outsider)->patch(route('sales-agendas.update', $agenda), $payload)->assertForbidden();
        $this->actingAs($manager)->get(route('sales-pocketbook.index', ['tab' => 'agenda', 'period_type' => 'custom', 'date_from' => '2026-07-01', 'date_to' => '2026-07-31']))
            ->assertOk()->assertSee($agenda->title);
    }

    public function test_agenda_result_is_required_and_completes_with_activity_log(): void
    {
        [, $project, $sales] = $this->salesContext();
        $agenda = $this->agenda($sales, $project);
        $token = app(OptimisticLockService::class)->token($agenda);

        $this->actingAs($sales)->patch(route('sales-agendas.update', $agenda), [
            'activity_result' => '', 'expected_updated_at' => $token,
        ])->assertSessionHasErrors('activity_result');
        $this->actingAs($sales)->patch(route('sales-agendas.update', $agenda), [
            'activity_result' => 'Konsumen meminta follow-up besok.', 'expected_updated_at' => $token,
        ])->assertRedirect()->assertSessionHas('success', 'Hasil agenda berhasil disimpan.');

        $agenda->refresh();
        $this->assertSame('done', $agenda->status);
        $this->assertSame('Konsumen meminta follow-up besok.', $agenda->activity_result);
        $this->assertNotNull($agenda->completed_at);
        $this->assertDatabaseHas('activity_log', ['subject_type' => ContentItem::class, 'subject_id' => $agenda->id, 'event' => 'agenda_result_recorded']);
    }

    public function test_reschedule_preserves_original_and_creates_linked_planned_agenda(): void
    {
        [, $project, $sales] = $this->salesContext();
        $agenda = $this->agenda($sales, $project);

        $this->actingAs($sales)->post(route('sales-agendas.reschedule', $agenda), [
            'scheduled_date' => '2026-07-12',
            'start_time' => '13:00',
            'end_time' => '13:45',
            'expected_updated_at' => app(OptimisticLockService::class)->token($agenda),
        ])->assertRedirect()->assertSessionHas('success', 'Agenda berhasil dijadwalkan ulang.');

        $agenda->refresh();
        $replacement = ContentItem::where('rescheduled_from_id', $agenda->id)->sole();
        $this->assertSame('rescheduled', $agenda->status);
        $this->assertNotNull($agenda->completed_at);
        $this->assertSame('planned', $replacement->status);
        $this->assertSame('2026-07-12', $replacement->scheduled_date->format('Y-m-d'));
        $this->assertSame(45, $replacement->duration_minutes);
        $this->assertTrue($replacement->assignees->contains($sales));
        $this->assertDatabaseHas('activity_log', ['subject_type' => ContentItem::class, 'subject_id' => $agenda->id, 'event' => 'agenda_rescheduled']);
    }

    public function test_agenda_optimistic_conflict_does_not_store_result(): void
    {
        [, $project, $sales] = $this->salesContext();
        $agenda = $this->agenda($sales, $project);
        $stale = Carbon::parse($agenda->updated_at)->subSecond()->utc()->format('Y-m-d H:i:s');

        $this->actingAs($sales)->patchJson(route('sales-agendas.update', $agenda), [
            'activity_result' => 'Tidak boleh tersimpan', 'expected_updated_at' => $stale,
        ])->assertConflict()->assertJsonPath('code', 'record_modified');
        $this->assertNull($agenda->fresh()->activity_result);
    }

    public function test_reschedule_rejects_invalid_time_and_stale_lock_without_creating_replacement(): void
    {
        [, $project, $sales] = $this->salesContext();
        $agenda = $this->agenda($sales, $project);
        $token = app(OptimisticLockService::class)->token($agenda);

        $this->actingAs($sales)->post(route('sales-agendas.reschedule', $agenda), [
            'scheduled_date' => '2026-07-12',
            'start_time' => '13:00',
            'end_time' => '12:59',
            'expected_updated_at' => $token,
        ])->assertSessionHasErrors('end_time');

        $stale = Carbon::parse($agenda->updated_at)->subSecond()->utc()->format('Y-m-d H:i:s');
        $this->actingAs($sales)->postJson(route('sales-agendas.reschedule', $agenda), [
            'scheduled_date' => '2026-07-12',
            'start_time' => '13:00',
            'end_time' => '13:45',
            'expected_updated_at' => $stale,
        ])->assertConflict()->assertJsonPath('code', 'record_modified');

        $this->assertSame('planned', $agenda->fresh()->status);
        $this->assertFalse(ContentItem::where('rescheduled_from_id', $agenda->id)->exists());
    }

    public function test_migrated_page_uses_canonical_header_and_role_aware_personal_and_monitoring_contexts(): void
    {
        [$branch, $project, $sales] = $this->salesContext();
        $manager = $this->user('manager', $branch);

        $this->actingAs($sales)->get(route('sales-pocketbook.index'))->assertOk()
            ->assertSee('sales-pocketbook-page-header', false)
            ->assertSee('Workspace Pribadi')
            ->assertSee('Catat lead, jalankan agenda, dan lengkapi hasil aktivitas harian Anda.')
            ->assertSee('Mode')->assertSee('Pribadi')->assertSee('Lead Saya')
            ->assertSee('Input Lead')->assertSee('Export XLSX')
            ->assertDontSee('Filter monitoring')->assertDontSee('Filter lanjutan')
            ->assertDontSee('name="search"', false)->assertDontSee('type="search"', false);

        $this->actingAs($manager)->get(route('sales-pocketbook.index', [
            'branch_id' => $branch->id,
            'project_id' => $project->id,
            'sales_user_id' => $sales->id,
        ]))->assertOk()
            ->assertSee('Workspace Monitoring')
            ->assertSee('Pantau aktivitas, funnel, dan kedisiplinan input Sales dalam scope organisasi Anda.')
            ->assertSee('Mode')->assertSee('Monitoring')->assertSee('Monitoring Lead')
            ->assertSee('Filter monitoring')->assertSee('Filter lanjutan (3)')
            ->assertSee('Cabang: '.$branch->name)->assertSee('Proyek: '.$project->project_name)->assertSee('Sales: '.$sales->name)
            ->assertDontSee('id="quick-lead-input"', false);
    }

    public function test_tabs_are_accessible_urls_and_keep_only_compatible_scope_and_period_state(): void
    {
        [$branch, $project, $sales] = $this->salesContext();
        $manager = $this->user('manager', $branch);
        $query = [
            'tab' => 'agenda',
            'branch_id' => $branch->id,
            'project_id' => $project->id,
            'sales_user_id' => $sales->id,
            'period_type' => 'custom',
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-31',
            'lead_source_id' => LeadSource::where('is_active', true)->firstOrFail()->id,
            'stage' => 'contacted_at',
        ];

        $response = $this->actingAs($manager)->get(route('sales-pocketbook.index', $query))->assertOk()
            ->assertSee('aria-label="Tampilan Buku Saku Sales"', false)
            ->assertSee('aria-current="page"', false);

        $scope = ['branch_id' => $branch->id, 'project_id' => $project->id, 'sales_user_id' => $sales->id];
        $period = ['period_type' => 'custom', 'date_from' => '2026-07-01', 'date_to' => '2026-07-31'];
        $response->assertSee(route('sales-pocketbook.index', ['tab' => 'leads', ...$scope]))
            ->assertSee(route('sales-pocketbook.index', ['tab' => 'agenda', ...$scope, ...$period]))
            ->assertSee(route('sales-pocketbook.index', ['tab' => 'report', ...$scope, ...$period]));

        $content = $response->getContent();
        $this->assertStringNotContainsString('tab=report&amp;lead_source_id=', $content);
        $this->assertStringNotContainsString('tab=report&amp;stage=', $content);
    }

    public function test_each_lead_renders_one_record_and_one_edit_payload_with_canonical_states_and_comment_count(): void
    {
        [, $project, $sales] = $this->salesContext();
        $newLead = $this->lead($sales, $project, 'Lead Baru Tunggal');
        $progressLead = $this->lead($sales, $project, 'Lead Dihubungi Tunggal', '08123456780');
        $progressLead->update(['contacted_at' => '2026-07-02 09:00:00']);
        $progressLead->comments()->create(['user_id' => $sales->id, 'body' => 'Satu', 'body_plain' => 'Satu']);

        $response = $this->actingAs($sales)->get(route('sales-pocketbook.index'))->assertOk()
            ->assertSee('LEAD BARU')->assertSee('DIHUBUNGI')->assertSee('Komentar (1)');
        $content = $response->getContent();

        foreach ([$newLead, $progressLead] as $lead) {
            $this->assertSame(1, substr_count($content, 'data-lead-row="'.$lead->id.'"'));
        }
        $this->assertSame(2, substr_count($content, 'class="sales-lead-item"'));
        $this->assertSame(2, substr_count($content, '@click="openLeadEdit('));
    }

    public function test_agenda_and_report_render_canonical_operational_and_empty_states_with_comment_counts(): void
    {
        [, $project, $sales] = $this->salesContext();
        $planned = $this->agenda($sales, $project, ['title' => 'Agenda Terjadwal']);
        $done = $this->agenda($sales, $project, [
            'title' => 'Agenda Hasil Lengkap', 'status' => 'done', 'activity_result' => 'Selesai', 'completed_at' => '2026-07-10 10:00:00',
        ]);
        $missing = $this->agenda($sales, $project, [
            'title' => 'Agenda Hasil Kosong', 'status' => 'done', 'activity_result' => null, 'completed_at' => '2026-07-10 10:00:00',
        ]);
        $done->comments()->create(['user_id' => $sales->id, 'body' => 'Satu', 'body_plain' => 'Satu']);
        $done->comments()->create(['user_id' => $sales->id, 'body' => 'Dua', 'body_plain' => 'Dua']);

        $period = ['period_type' => 'custom', 'date_from' => '2026-07-01', 'date_to' => '2026-07-31'];
        $this->actingAs($sales)->get(route('sales-pocketbook.index', ['tab' => 'agenda', ...$period]))->assertOk()
            ->assertSee($planned->title)->assertSee($done->title)->assertSee($missing->title)
            ->assertSee('Terjadwal / Terlewat')->assertSee('Selesai')->assertSee('Selesai / Hasil belum lengkap')
            ->assertSee('Komentar (2)');

        $this->actingAs($sales)->get(route('sales-pocketbook.index', [
            'tab' => 'report', 'period_type' => 'custom', 'date_from' => '2026-08-01', 'date_to' => '2026-08-31',
        ]))->assertOk()
            ->assertSee('Ringkasan periode')->assertSee('Aktivitas saya')->assertSee('Agenda Selesai')
            ->assertSee('Belum ada aktivitas pada periode ini.');
    }

    public function test_lead_and_agenda_lists_paginate_independently_at_twenty_and_preserve_relevant_filters(): void
    {
        [$branch, $project, $sales] = $this->salesContext();
        $manager = $this->user('manager', $branch);
        $source = LeadSource::where('is_active', true)->firstOrFail();
        foreach (range(1, 25) as $index) {
            $this->lead($sales, $project, sprintf('Lead Page %02d', $index), '08123'.str_pad((string) $index, 6, '0', STR_PAD_LEFT));
            $this->agenda($sales, $project, ['title' => sprintf('Agenda Page %02d', $index)]);
        }
        $period = ['period_type' => 'custom', 'date_from' => '2026-07-01', 'date_to' => '2026-07-31'];

        $leadResponse = $this->actingAs($manager)->get(route('sales-pocketbook.index', [
            'tab' => 'leads', 'page' => 2, 'agenda_page' => 2, 'lead_source_id' => $source->id, ...$period,
        ]))->assertOk()
            ->assertViewHas('leads', fn ($leads) => $leads->perPage() === 20 && $leads->currentPage() === 2 && $leads->total() === 25)
            ->assertViewHas('agendas', fn ($agendas) => $agendas->perPage() === 20 && $agendas->currentPage() === 2 && $agendas->total() === 25);
        $leadContent = $leadResponse->getContent();
        $this->assertStringContainsString('lead_source_id='.$source->id, $leadContent);
        $this->assertStringNotContainsString('agenda_page=', $this->paginationSection($leadContent, 'sales-lead-monitor'));

        $agendaResponse = $this->actingAs($manager)->get(route('sales-pocketbook.index', [
            'tab' => 'agenda', 'page' => 2, 'agenda_page' => 2, 'project_id' => $project->id, ...$period,
        ]))->assertOk()
            ->assertViewHas('leads', fn ($leads) => $leads->perPage() === 20 && $leads->currentPage() === 2 && $leads->total() === 25)
            ->assertViewHas('agendas', fn ($agendas) => $agendas->perPage() === 20 && $agendas->currentPage() === 2 && $agendas->total() === 25);
        $agendaContent = $agendaResponse->getContent();
        $this->assertStringContainsString('project_id='.$project->id, $agendaContent);
        $this->assertDoesNotMatchRegularExpression('/(?:\?|&amp;)page=/', $this->paginationSection($agendaContent, 'sales-agenda-monitor'));
    }

    public function test_true_empty_and_filtered_empty_states_are_distinct(): void
    {
        [$branch, $project, $sales] = $this->salesContext();
        $manager = $this->user('manager', $branch);

        $this->actingAs($sales)->get(route('sales-pocketbook.index'))->assertOk()
            ->assertSee('Belum ada lead.')
            ->assertDontSee('Tidak ada lead yang cocok dengan filter.');
        $this->actingAs($manager)->get(route('sales-pocketbook.index', [
            'branch_id' => $branch->id, 'project_id' => $project->id, 'sales_user_id' => $sales->id,
        ]))->assertOk()
            ->assertSee('Tidak ada lead yang cocok dengan filter.')
            ->assertSee('Hapus semua filter');
        $this->actingAs($sales)->get(route('sales-pocketbook.index', ['tab' => 'agenda']))->assertOk()
            ->assertSee('Belum ada agenda minggu ini.');
        $this->actingAs($sales)->get(route('sales-pocketbook.index', [
            'tab' => 'agenda', 'period_type' => 'custom', 'date_from' => '2020-01-01', 'date_to' => '2020-01-31',
        ]))->assertOk()->assertSee('Tidak ada agenda yang cocok dengan filter.');
    }

    public function test_export_and_agenda_create_visibility_follow_role_permissions_and_supported_actions(): void
    {
        [$branch, $project, $sales] = $this->salesContext();
        $sales->forceFill(['supervisor_user_id' => null])->save();
        $coordinator = $this->user('sales_coordinator', $branch, 'Koordinator');
        $sales->forceFill(['supervisor_user_id' => $coordinator->id])->save();
        $supervisor = $this->user('supervisor', $branch, 'Supervisor');
        $branchManager = $this->user('branch_manager', $branch, 'Branch Manager');
        $pusat = $this->user('pusat', null, 'Pusat');

        $expectations = [
            [$sales, true, true],
            [$coordinator, false, false],
            [$supervisor, true, false],
            [$branchManager, true, false],
            [$pusat, true, true],
        ];
        foreach ($expectations as [$actor, $exportVisible, $agendaCreateVisible]) {
            $response = $this->actingAs($actor)->get(route('sales-pocketbook.index', ['tab' => 'agenda']))->assertOk();
            $exportVisible ? $response->assertSee('Export XLSX') : $response->assertDontSee('Export XLSX');
            $agendaCreateVisible ? $response->assertSee('id="quick-agenda-input"', false) : $response->assertDontSee('id="quick-agenda-input"', false);
        }

        $this->assertTrue($project->is_active);
    }

    public function test_primary_sales_index_ignores_another_sales_filter_and_rejects_tampered_branch_or_project(): void
    {
        [$branch, $project, $sales] = $this->salesContext();
        $other = $this->sales($branch, $project, 'Other Sales');
        $otherBranch = Branch::create(['name' => 'Pati', 'code' => 'PTI', 'is_active' => true]);
        $otherProject = $this->project($otherBranch, 'Pati Project');

        $this->actingAs($sales)->get(route('sales-pocketbook.index', ['sales_user_id' => $other->id]))->assertOk()
            ->assertViewHas('leads', fn ($leads) => $leads->getCollection()->every(fn ($lead) => $lead->sales_user_id === $sales->id));
        $this->actingAs($sales)->get(route('sales-pocketbook.index', ['branch_id' => $otherBranch->id]))->assertForbidden();
        $this->actingAs($sales)->get(route('sales-pocketbook.index', ['project_id' => $otherProject->id]))->assertForbidden();
    }

    public function test_team_project_branch_global_and_supplemental_roles_keep_their_existing_scope_boundaries(): void
    {
        [$branch, $project, $sales] = $this->salesContext();
        $peer = $this->sales($branch, $project, 'Peer Sales');
        $teamLead = $this->lead($sales, $project, 'Team Visible');
        $peerLead = $this->lead($peer, $project, 'Peer In Descendant Project', '08123456781');
        $coordinator = $this->user('sales_coordinator', $branch, 'Coordinator');
        $sales->forceFill(['supervisor_user_id' => $coordinator->id])->save();
        $supervisor = $this->user('supervisor', $branch, 'Supervisor');
        $coordinator->forceFill(['supervisor_user_id' => $supervisor->id])->save();

        foreach ([$coordinator, $supervisor] as $teamActor) {
            $response = $this->actingAs($teamActor)->get(route('sales-pocketbook.index'))->assertOk();
            $names = $response->viewData('leads')->pluck('customer_name');
            $this->assertContains($teamLead->customer_name, $names, $teamActor->role->slug.' should see descendants.');
            $this->assertContains($peerLead->customer_name, $names, $teamActor->role->slug.' scope currently includes records in a descendant project.');
        }
        foreach (['branch_manager', 'manager'] as $role) {
            $response = $this->actingAs($this->user($role, $branch))->get(route('sales-pocketbook.index'))->assertOk();
            $names = $response->viewData('leads')->pluck('customer_name');
            $this->assertContains($teamLead->customer_name, $names, $role.' should see branch leads.');
            $this->assertContains($peerLead->customer_name, $names, $role.' should see branch peers.');
        }
        $globalResponse = $this->actingAs($this->user('pusat'))->get(route('sales-pocketbook.index'))->assertOk();
        $globalNames = $globalResponse->viewData('leads')->pluck('customer_name');
        $this->assertContains($teamLead->customer_name, $globalNames);
        $this->assertContains($peerLead->customer_name, $globalNames);

        $staff = $this->user('staff', $branch);
        $staff->roles()->attach(Role::where('slug', 'pusat')->firstOrFail());
        $this->actingAs($staff)->get(route('sales-pocketbook.index'))->assertForbidden();
    }

    public function test_report_sort_links_use_direction_preserve_filters_and_clear_both_paginator_keys(): void
    {
        [$branch, $project, $sales] = $this->salesContext();
        $manager = $this->user('manager', $branch);
        $response = $this->actingAs($manager)->get(route('sales-pocketbook.index', [
            'tab' => 'report', 'branch_id' => $branch->id, 'project_id' => $project->id,
            'period_type' => 'week', 'week' => '2026-07-20', 'sort' => 'sales', 'direction' => 'asc',
            'page' => 4, 'agenda_page' => 3,
        ]))->assertOk()->assertSee('aria-sort="ascending"', false);

        $content = $response->getContent();
        preg_match('/href="([^"]*sort=sales[^"]*)"[^>]*aria-label="Urutkan berdasarkan Sales/', $content, $match);
        $this->assertNotEmpty($match);
        $sortUrl = html_entity_decode($match[1]);
        $this->assertStringContainsString('direction=desc', $sortUrl);
        $this->assertStringContainsString('branch_id='.$branch->id, $sortUrl);
        $this->assertStringContainsString('project_id='.$project->id, $sortUrl);
        $this->assertStringNotContainsString('page=', $sortUrl);
        $this->assertStringNotContainsString('agenda_page=', $sortUrl);
        $this->assertStringNotContainsString('dir=', $sortUrl);
    }

    public function test_standalone_edit_duplicate_exclusion_and_mobile_source_contracts_remain_canonical(): void
    {
        [, $project, $sales] = $this->salesContext();
        $lead = $this->lead($sales, $project, 'Edit Canonical');

        $edit = $this->actingAs($sales)->get(route('sales-leads.edit', $lead))->assertOk()->getContent();
        foreach (['sales-pocketbook-page-header', 'Edit Lead', 'Data Lead', 'Tanggal Lead', 'Nama Calon Konsumen',
            'No. WhatsApp / Telepon', 'Sumber Lead', 'Referensi Konsumen Tertaut', 'Simpan Perubahan', 'Batal'] as $contract) {
            $this->assertTrue(str_contains($edit, $contract), 'Missing edit contract: '.$contract);
        }
        $this->assertStringNotContainsString('salesDuplicatePhone(@js(', $edit);
        $this->assertStringContainsString('x-data="salesDuplicatePhone($el.dataset.duplicateUrl, Number($el.dataset.leadId))"', $edit);
        $this->assertStringContainsString('data-duplicate-url="'.route('sales-leads.duplicate-phone').'"', $edit);
        $this->actingAs($sales)->getJson(route('sales-leads.duplicate-phone', [
            'phone' => $lead->phone, 'except_id' => $lead->id,
        ]))->assertOk()->assertJsonCount(0, 'matches');

        $view = file_get_contents(resource_path('views/crm/sales-pocketbook/index.blade.php'))
            .file_get_contents(resource_path('views/crm/sales-pocketbook/_report.blade.php'));
        $reminder = file_get_contents(resource_path('views/crm/sales-pocketbook/_daily-reminder.blade.php'));
        $css = file_get_contents(resource_path('css/app.css'));
        foreach (['sales-pocketbook-tabs', 'sales-lead-item', 'sales-agenda-item', 'crm-table-scroll sales-report-table-scroll'] as $class) {
            $this->assertTrue(str_contains($view, $class), 'Missing view contract: '.$class);
        }
        $this->assertTrue(str_contains($view, "@include('crm.sales-pocketbook._daily-reminder')"), 'Missing reminder include.');
        $this->assertTrue(str_contains($reminder, 'salesDailyReminder('), 'Missing reminder Alpine contract.');
        foreach (['overflow-x: auto', '@media (max-width: 639px)', 'min-height: 44px', 'grid-template-columns: minmax(0, 1fr)'] as $contract) {
            $this->assertTrue(str_contains($css, $contract), 'Missing mobile CSS contract: '.$contract);
        }
    }

    public function test_lead_create_writes_legacy_field_map_with_stable_uuid_and_site_visit_redirect(): void
    {
        [$branch, $project, $sales] = $this->salesContext();
        $branch->update(['sheet_id' => 'branch-sheet']);
        $operationUuid = (string) Str::uuid();
        $options = ['promo' => [], 'source' => ['Online'], 'channel' => ['Instagram'], 'activity' => ['Agustus'], 'project' => [$project->project_name], 'sales' => [$sales->name], 'status' => ['Cek Lokasi']];
        $optionService = Mockery::mock(SalesLeadSheetOptionService::class);
        $optionService->shouldReceive('forBranch')->once()->andReturn($options);
        $optionService->shouldReceive('exactOption')->andReturnUsing(fn (array $values, ?string $value) => in_array($value, $values, true) ? $value : null);
        $this->app->instance(SalesLeadSheetOptionService::class, $optionService);
        $identities = Mockery::mock(SalesSheetIdentityService::class);
        $identities->shouldReceive('projectValue')->andReturn($project->project_name);
        $identities->shouldReceive('salesValue')->andReturn($sales->name);
        $this->app->instance(SalesSheetIdentityService::class, $identities);
        $captured = [];
        $writer = Mockery::mock(SalesLeadSpreadsheetWriter::class);
        $writer->shouldReceive('append')->once()->withArgs(function (SalesLead $lead, string $sheet, array $fields, string $uuid) use (&$captured, $operationUuid): bool {
            $captured = $fields;

            return $sheet === 'lead' && $uuid === $lead->external_sync_id && $uuid === $operationUuid;
        })->andReturnUsing(fn (SalesLead $lead, string $sheet, array $fields, string $uuid) => new SalesLeadSpreadsheetWriteResult('branch-sheet', $sheet, 4, $uuid));
        $this->app->instance(SalesLeadSpreadsheetWriter::class, $writer);

        $response = $this->actingAs($sales)->post(route('sales-leads.store'), $this->payload($sales, $project, [
            'source' => 'Online', 'platform' => 'Instagram', 'campaign_name' => 'Agustus', 'id_promo' => null,
            'current_status' => 'site_visit', 'notes' => 'Hubungi sore.', 'operation_uuid' => $operationUuid,
        ]));

        $lead = SalesLead::sole();
        $response->assertRedirect(route('sales-pocketbook.index', ['lifecycle_action' => 'site_visit', 'lead' => $lead->id]));
        $this->assertNotNull($lead->external_sync_id);
        $this->assertSame([
            'tanggal_lead', 'source', 'platform', 'campaign_name', 'nama_konsumen', 'no_hp', 'proyek',
            'sales_pic', 'status_lead', 'keterangan', 'id_promo',
        ], array_keys($captured));
        $this->assertSame('Cek Lokasi', $captured['status_lead']);
        $this->assertSame('Instagram', $captured['platform']);
    }

    public function test_remote_create_failure_is_visible_and_does_not_claim_or_persist_success(): void
    {
        [, $project, $sales] = $this->salesContext();
        $identities = Mockery::mock(SalesSheetIdentityService::class);
        $identities->shouldReceive('projectValue')->once()->andReturn($project->project_name);
        $identities->shouldReceive('salesValue')->once()->andReturn($sales->name);
        $this->app->instance(SalesSheetIdentityService::class, $identities);
        $writer = Mockery::mock(SalesLeadSpreadsheetWriter::class);
        $writer->shouldReceive('append')->once()->andThrow(SalesLeadSpreadsheetContractException::writeFailed());
        $this->app->instance(SalesLeadSpreadsheetWriter::class, $writer);

        $this->actingAs($sales)->from(route('sales-pocketbook.index'))->post(route('sales-leads.store'), $this->payload($sales, $project))
            ->assertRedirect(route('sales-pocketbook.index'))->assertSessionHasErrors('spreadsheet')->assertSessionMissing('success');
        $this->assertDatabaseCount('sales_leads', 0);
    }

    public function test_invalid_branch_option_returns_field_error_without_calling_writer(): void
    {
        [$branch, $project, $sales] = $this->salesContext();
        $branch->update(['sheet_id' => 'branch-options']);
        $options = ['promo' => ['No Promo'], 'source' => ['Online'], 'channel' => ['WhatsApp'], 'activity' => ['Follow Up'], 'project' => [$project->project_name], 'sales' => [$sales->name], 'status' => ['No Respon']];
        $optionService = Mockery::mock(SalesLeadSheetOptionService::class);
        $optionService->shouldReceive('forBranch')->once()->andReturn($options);
        $optionService->shouldReceive('exactOption')->andReturnUsing(fn (array $values, ?string $value) => in_array($value, $values, true) ? $value : null);
        $this->app->instance(SalesLeadSheetOptionService::class, $optionService);
        $identities = Mockery::mock(SalesSheetIdentityService::class);
        $identities->shouldReceive('projectValue')->once()->andReturn($project->project_name);
        $identities->shouldReceive('salesValue')->once()->andReturn($sales->name);
        $this->app->instance(SalesSheetIdentityService::class, $identities);
        $writer = Mockery::mock(SalesLeadSpreadsheetWriter::class);
        $writer->shouldNotReceive('append');
        $this->app->instance(SalesLeadSpreadsheetWriter::class, $writer);

        $this->actingAs($sales)->post(route('sales-leads.store'), $this->payload($sales, $project, [
            'source' => 'Online', 'platform' => 'Tidak Valid', 'campaign_name' => 'Follow Up',
        ]))->assertSessionHasErrors('platform');

        $this->assertDatabaseCount('sales_leads', 0);
    }

    public function test_branch_options_endpoint_is_rendered_and_authorized_by_branch_access(): void
    {
        [$branch, , $sales] = $this->salesContext();
        $other = Branch::create(['name' => 'Other Options', 'code' => 'OPT', 'sheet_id' => 'other-sheet', 'is_active' => true]);
        $options = ['promo' => [], 'source' => ['Online'], 'channel' => [], 'activity' => [], 'project' => [], 'sales' => [], 'status' => []];
        $service = Mockery::mock(SalesLeadSheetOptionService::class);
        $service->shouldReceive('forBranch')->once()->withArgs(fn (Branch $candidate) => $candidate->is($branch))->andReturn($options);
        $this->app->instance(SalesLeadSheetOptionService::class, $service);

        $this->actingAs($sales)->getJson(route('sales-leads.options', $branch))->assertOk()->assertJsonPath('options.source.0', 'Online');
        $this->actingAs($sales)->getJson(route('sales-leads.options', $other))->assertForbidden();
        $content = $this->actingAs($sales)->get(route('sales-pocketbook.index'))->assertOk()->getContent();
        $this->assertStringContainsString('BRANCH_ID', $content);
        $this->assertStringContainsString('name="operation_uuid"', $content);
    }

    public function test_lifecycle_ui_exposes_authorized_modal_contracts_nup_warning_and_read_only_system_status(): void
    {
        [, $project, $sales] = $this->salesContext();
        $project->update(['is_nup_eligible' => true]);
        $lead = $this->lead($sales, $project, 'Lifecycle UI');
        $lead->update(['current_status' => SalesLeadStatus::SlikCheck]);
        SalesLeadLifecycleSyncStatus::query()->create([
            'branch_id' => $lead->branch_id,
            'status' => 'success',
            'summary' => ['capabilities' => ['data_konsumen_nup' => true]],
        ]);

        $content = $this->actingAs($sales)->get(route('sales-pocketbook.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Cek SLIK', $content);
        $this->assertStringContainsString('Status sistem bersifat baca-saja.', $content);
        $this->assertStringContainsString('Proses Konsumen NUP', $content);
        $this->assertStringContainsString('Isi Nanti', $content);
        $this->assertStringContainsString("data.lead.current_status === 'site_visit'", $content);
        $this->assertStringNotContainsString('alert(', $content);
        $this->assertStringNotContainsString('confirm(', $content);
        $this->assertSame(SalesLeadStatus::SlikCheck, $lead->fresh()->current_status);
    }

    public function test_lifecycle_ui_changelog_is_deployed_once_and_rendered(): void
    {
        $title = 'Siklus Lead Buku Saku Terhubung';
        $this->assertSame(1, DB::table('changelogs')->whereNull('version')->where('title', $title)->count());

        [$branch] = $this->salesContext();
        $this->actingAs($this->user('manager', $branch))->get(route('changelogs.index'))->assertOk()->assertSee($title);
    }

    public function test_read_only_status_uses_labelled_span_without_dangling_label(): void
    {
        [, $project, $sales] = $this->salesContext();
        $lead = $this->lead($sales, $project, 'Read Only Status');
        $lead->update(['current_status' => SalesLeadStatus::SlikCheck]);

        $standalone = $this->actingAs($sales)->get(route('sales-leads.edit', $lead))->assertOk()->getContent();
        $inline = $this->actingAs($sales)->get(route('sales-pocketbook.index'))->assertOk()->getContent();

        $this->assertStringContainsString('aria-labelledby="edit-lead-status-label"', $standalone);
        $this->assertStringNotContainsString('label for="edit-lead-status"', $standalone);
        $this->assertStringContainsString('aria-labelledby="modal-lead-status-label"', $inline);
        $this->assertStringNotContainsString('<p class="border border-gray-400', $inline);
    }

    public function test_verified_direct_write_changelog_is_idempotent_and_rendered(): void
    {
        $migration = require database_path('migrations/2026_08_04_000018_add_verified_lead_writes_changelog.php');
        $migration->up();
        $migration->up();
        $title = 'Penulisan Lead Mengikuti Pilihan Spreadsheet Cabang';
        [$branch] = $this->salesContext();

        $this->assertSame(1, DB::table('changelogs')->whereNull('version')->where('title', $title)->count());
        $this->actingAs($this->user('manager', $branch))->get(route('changelogs.index'))->assertOk()->assertSee($title);
    }

    public function test_sales_pocketbook_two_changelog_is_idempotent_and_rendered(): void
    {
        $migrationPath = database_path('migrations/2026_07_30_000004_add_sales_pocketbook_2_accessibility_changelog.php');
        $migration = require $migrationPath;
        $migration->up();
        $migration->up();

        $title = 'Buku Saku Sales menjadi ruang kerja harian';
        $entry = DB::table('changelogs')->whereNull('version')->where('title', $title)->sole();
        $this->assertSame('changed', $entry->category);
        $this->assertSame(1, DB::table('changelogs')->whereNull('version')->where('title', $title)->count());
        $this->assertStringContainsString("DB::table('changelogs')->updateOrInsert", file_get_contents($migrationPath));

        [$branch] = $this->salesContext();
        $this->actingAs($this->user('manager', $branch))->get(route('changelogs.index'))->assertOk()
            ->assertSee($title)
            ->assertSee('Lead, agenda, laporan, pengingat, dan input harian kini lebih terarah');
    }

    private function salesContext(): array
    {
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);
        $project = $this->project($branch, 'Solo Project');
        $sales = $this->sales($branch, $project, 'Solo Sales');

        return [$branch, $project, $sales];
    }

    private function project(Branch $branch, string $name): LeadMaster
    {
        return LeadMaster::create(['branch_id' => $branch->id, 'project_name' => $name, 'is_active' => true]);
    }

    private function sales(Branch $branch, LeadMaster $project, string $name): User
    {
        $sales = $this->user('sales', $branch, $name);
        $sales->assignedProjects()->attach($project->id, ['is_primary' => true]);

        return $sales;
    }

    private function user(string $roleSlug, ?Branch $branch = null, ?string $name = null): User
    {
        $role = Role::firstOrCreate(['slug' => $roleSlug], ['name' => ucfirst($roleSlug), 'is_superadmin' => $roleSlug === 'superadmin']);

        return User::factory()->create(['name' => $name ?? ucfirst($roleSlug), 'role_id' => $role->id, 'branch_id' => $branch?->id, 'password_changed_at' => now()]);
    }

    private function lead(User $sales, LeadMaster $project, string $name = 'Lead', string $phone = '08123456789'): SalesLead
    {
        return SalesLead::create($this->payload($sales, $project, [
            'customer_name' => $name, 'phone' => $phone, 'normalized_phone' => app(PhoneNormalizationService::class)->normalize($phone),
            'created_by' => $sales->id, 'updated_by' => $sales->id,
        ]));
    }

    private function payload(User $sales, LeadMaster $project, array $overrides = []): array
    {
        return array_merge([
            'branch_id' => $project->branch_id,
            'project_id' => $project->id,
            'sales_user_id' => $sales->id,
            'lead_source_id' => LeadSource::where('is_active', true)->firstOrFail()->id,
            'lead_date' => '2026-07-01',
            'customer_name' => 'Prospect',
            'phone' => '08123456789',
            'source' => 'Online',
            'platform' => 'WhatsApp',
            'campaign_name' => 'Follow Up',
            'operation_uuid' => (string) Str::uuid(),
        ], $overrides);
    }

    private function stagePayload(SalesLead $lead, string $stage, string $timestamp): array
    {
        return ['stage' => $stage, 'action' => 'set', 'timestamp' => $timestamp, 'expected_updated_at' => app(OptimisticLockService::class)->token($lead)];
    }

    private function agenda(User $sales, LeadMaster $project, array $overrides = []): ContentItem
    {
        $agenda = ContentItem::create(array_merge([
            'branch_id' => $project->branch_id,
            'project_name' => $project->project_name,
            'item_type' => 'agenda',
            'visibility' => 'personal',
            'title' => 'Follow-up Konsumen',
            'agenda_type' => ContentItem::SALES_AGENDA_TYPE,
            'sales_activity_category' => 'Follow-up',
            'start_date' => '2026-07-10',
            'scheduled_date' => '2026-07-10',
            'deadline_date' => '2026-07-10',
            'start_time' => '09:00',
            'end_time' => '10:00',
            'duration_minutes' => 60,
            'status' => 'planned',
            'owner_user_id' => $sales->id,
            'sales_project_id' => $project->id,
            'created_by' => $sales->id,
        ], $overrides));
        $agenda->assignees()->attach($sales);

        return $agenda;
    }

    private function paginationSection(string $content, string $sectionClass): string
    {
        $start = strpos($content, '<section class="'.$sectionClass.'">');
        $this->assertNotFalse($start);
        $end = strpos($content, '</section>', $start);
        $this->assertNotFalse($end);

        return substr($content, $start, $end - $start);
    }

    private function agendaPayload(User $sales, LeadMaster $project, array $overrides = []): array
    {
        return array_merge([
            'owner_user_id' => $sales->id,
            'project_id' => $project->id,
            'scheduled_date' => '2026-07-10',
            'start_time' => '09:00',
            'end_time' => '10:30',
            'sales_activity_category' => 'Follow-up',
            'title' => 'Follow-up Konsumen',
            'location' => 'Kantor pemasaran',
            'notes' => 'Bawa brosur.',
        ], $overrides);
    }

    private function mockLeadWriter(): void
    {
        $identities = Mockery::mock(SalesSheetIdentityService::class);
        $identities->shouldReceive('projectValue')->andReturnUsing(fn (LeadMaster $project) => $project->project_name);
        $identities->shouldReceive('salesValue')->andReturnUsing(fn (Branch $branch, User $user) => $user->name);
        $this->app->instance(SalesSheetIdentityService::class, $identities);
        $writer = Mockery::mock(SalesLeadSpreadsheetWriter::class);
        $writer->shouldReceive('append')->once()->andReturnUsing(
            fn (SalesLead $lead, string $sheet, array $fields, string $uuid) => new SalesLeadSpreadsheetWriteResult('sheet-'.$lead->branch_id, $sheet, 3, $uuid),
        );
        $this->app->instance(SalesLeadSpreadsheetWriter::class, $writer);
    }
}
