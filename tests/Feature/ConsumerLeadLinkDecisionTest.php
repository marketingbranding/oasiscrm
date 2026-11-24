<?php

namespace Tests\Feature;

use App\Http\Requests\Crm\StoreConsumerLeadLinkDecisionRequest;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\BranchMigrationState;
use App\Models\ConsumerApplication;
use App\Models\ConsumerLeadLinkDecision;
use App\Models\ConsumerNup;
use App\Models\KonsumenProgressSheetRow;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\SalesLead;
use App\Models\User;
use App\Services\ConsumerLeadLinkDecisionService;
use App\Services\ConsumerLeadLinkSubjectResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ConsumerLeadLinkDecisionTest extends TestCase
{
    use RefreshDatabase;

    public function test_br_linked_decision_accepts_lead_in_same_permitted_branch_and_project(): void
    {
        [$consumer, $actor] = $this->decisionContext();
        $lead = $this->lead($consumer, $actor);

        $decision = app(ConsumerLeadLinkDecisionService::class)->decide($consumer, 'linked', $lead, [], $actor);

        $this->assertSame(ConsumerLeadLinkDecision::STATUS_LINKED, $decision->status);
        $this->assertDatabaseHas('consumer_applications', ['id' => $consumer->id, 'sales_lead_id' => $lead->id]);
    }

    public function test_br22_nup_subject_accepts_linked_lead_inside_same_scope(): void
    {
        [$consumer, $actor] = $this->decisionContext();
        $nup = ConsumerNup::create([
            'customer_id' => $consumer->customer_id,
            'branch_id' => $consumer->branch_id,
            'project_id' => $consumer->project_id,
            'nup_number' => 'NUP-TEST',
            'registered_at' => today(),
            'status' => 'active',
            'entry_type' => 'from_lead',
        ]);
        $subject = app(ConsumerLeadLinkSubjectResolver::class)->fromNup($nup->load('customer'));
        $lead = $this->lead($consumer, $actor);

        $decision = app(ConsumerLeadLinkDecisionService::class)->decideForSubject($subject, 'linked', $lead, [], $actor);

        $this->assertSame($nup->id, $decision->consumer_nup_id);
        $this->assertSame('nup:'.$nup->id, $decision->subject_key);
    }

    public function test_br24_one_lead_cannot_link_to_nup_after_application_link(): void
    {
        [$consumer, $actor] = $this->decisionContext();
        $lead = $this->lead($consumer, $actor);
        app(ConsumerLeadLinkDecisionService::class)->decide($consumer, 'linked', $lead, [], $actor);
        $nup = ConsumerNup::create([
            'customer_id' => $consumer->customer_id,
            'branch_id' => $consumer->branch_id,
            'project_id' => $consumer->project_id,
            'nup_number' => 'NUP-COLLISION',
            'registered_at' => today(),
            'status' => 'active',
            'entry_type' => 'from_lead',
        ]);
        $subject = app(ConsumerLeadLinkSubjectResolver::class)->fromNup($nup->load('customer'));

        $this->expectException(ValidationException::class);
        app(ConsumerLeadLinkDecisionService::class)->decideForSubject($subject, 'linked', $lead, [], $actor);
        $this->assertDatabaseCount('consumer_lead_link_decisions', 1);
    }

    public function test_br24_failed_subject_decision_rolls_back_previous_decision_change(): void
    {
        [$consumer, $actor] = $this->decisionContext();
        $first = app(ConsumerLeadLinkDecisionService::class)->decide($consumer, 'without_lead', null, ['reason' => 'Pertama'], $actor);

        try {
            app(ConsumerLeadLinkDecisionService::class)->decide($consumer, 'linked', null, [], $actor);
        } catch (ValidationException) {
        }

        $this->assertDatabaseHas('consumer_lead_link_decisions', ['id' => $first->id, 'is_active' => true]);
        $this->assertDatabaseCount('consumer_lead_link_decisions', 1);
    }

    public function test_br53_direct_subject_decision_denies_foreign_nup_scope(): void
    {
        [$consumer, $actor] = $this->decisionContext();
        $foreign = ConsumerNup::factory()->create();
        $subject = app(ConsumerLeadLinkSubjectResolver::class)->fromNup($foreign->load('customer'));

        $this->expectException(AuthorizationException::class);
        app(ConsumerLeadLinkDecisionService::class)->decideForSubject($subject, 'without_lead', null, ['reason' => 'Di luar scope'], $actor);
    }

    public function test_br53_direct_preview_bulk_and_conflict_endpoints_deny_foreign_scope(): void
    {
        [$branch, $project, $actor] = $this->bulkContext();
        $foreign = Branch::create(['code' => 'ENDPOINT-FOREIGN', 'name' => 'Asing Endpoint', 'is_active' => true]);

        $this->actingAs($actor)->getJson(route('consumer-database.workspace.bulk-legacy.preview', ['branch_id' => $foreign->id, 'before' => '2026-01-01']))->assertForbidden();
        $this->actingAs($actor)->postJson(route('consumer-database.workspace.bulk-legacy.store'), ['branch_id' => $foreign->id, 'before' => '2026-01-01', 'reason' => 'uji', 'token' => 'x'])->assertForbidden();
        $this->actingAs($actor)->getJson(route('consumer-database.workspace.lead-link-conflicts', ['branch_id' => $foreign->id]))->assertForbidden();
    }

    public function test_br_decision_endpoint_rejects_foreign_sheet_scope(): void
    {
        [$branch, , $actor] = $this->bulkContext();
        $foreign = Branch::create(['code' => 'SHEET-FOREIGN', 'name' => 'Asing Sheet', 'is_active' => true]);
        $row = KonsumenProgressSheetRow::create(['branch_id' => $foreign->id, 'sheet_id' => 'foreign', 'sheet_name' => 'data_konsumen', 'row_hash' => 'foreign-row', 'row_data' => ['[SYS] ID Konsumen' => 'FOREIGN-1']]);

        $this->actingAs($actor)->postJson(route('consumer-database.workspace.link-subject.lead-link-decision.store', ['subjectType' => 'sheet', 'subjectId' => $row->id]), ['status' => 'without_lead', 'reason' => 'uji'])->assertForbidden();
    }

    public function test_br_bulk_legacy_rejects_changed_filter_after_valid_preview(): void
    {
        [$branch, $project, $actor] = $this->bulkContext();
        KonsumenProgressSheetRow::create(['branch_id' => $branch->id, 'sheet_id' => 'sheet', 'sheet_name' => 'data_konsumen', 'row_hash' => 'filter-change', 'row_data' => ['[SYS] ID Konsumen' => 'FILTER-1', 'Tanggal' => '2025-01-01', 'Proyek' => $project->project_name]]);
        $preview = app(ConsumerLeadLinkDecisionService::class)->previewBulkLegacy($actor, ['branch_id' => $branch->id, 'project_id' => $project->id, 'before' => '2026-01-01']);

        $this->expectException(ValidationException::class);
        app(ConsumerLeadLinkDecisionService::class)->markBulkLegacy($actor, ['branch_id' => $branch->id, 'project_id' => $project->id, 'before' => '2025-01-01', 'token' => $preview['token']], 'Uji filter berubah');
    }

    public function test_br80_conflict_review_applies_selected_decision_and_audits_reason(): void
    {
        [$consumer, $actor] = $this->decisionContext();
        $actor->assignedProjects()->syncWithoutDetaching([$consumer->project_id => ['is_active' => true, 'is_primary' => true]]);
        $actor->update(['role_id' => Role::query()->where('slug', 'superadmin')->value('id')]);
        $source = ConsumerLeadLinkDecision::create(['consumer_application_id' => $consumer->id, 'branch_id' => $consumer->branch_id, 'subject_type' => 'application', 'subject_key' => 'application:'.$consumer->id, 'status' => 'legacy', 'reason' => 'Sumber', 'decision_source' => 'manual', 'is_active' => true, 'decided_by' => $actor->id, 'decided_at' => now()]);
        $target = ConsumerLeadLinkDecision::create(['consumer_application_id' => $consumer->id, 'branch_id' => $consumer->branch_id, 'subject_type' => 'application', 'subject_key' => 'application:'.$consumer->id, 'status' => 'without_lead', 'reason' => 'Tujuan', 'decision_source' => 'manual', 'is_active' => false, 'decided_by' => $actor->id, 'decided_at' => now()]);
        $conflict = DB::table('consumer_lead_link_conflicts')->insertGetId(['source_decision_id' => $source->id, 'target_decision_id' => $target->id, 'consumer_application_id' => $consumer->id, 'conflict_type' => 'status', 'details' => json_encode([]), 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($actor)->post(route('consumer-database.workspace.lead-link-conflict.resolve', $conflict), ['decision' => 'target', 'reason' => 'Verifikasi tujuan'])->assertRedirect();

        $this->assertDatabaseHas('consumer_lead_link_decisions', ['id' => $source->id, 'is_active' => false]);
        $this->assertDatabaseHas('consumer_lead_link_decisions', ['id' => $target->id, 'is_active' => true]);
        $this->assertDatabaseHas('consumer_lead_link_conflicts', ['id' => $conflict, 'status' => 'resolved']);
        $this->assertDatabaseHas('activity_log', ['event' => 'consumer_lead_link_conflict_reviewed']);
    }

    public function test_br_decision_endpoint_returns_forbidden_for_foreign_application(): void
    {
        [, $actor] = $this->decisionContext();
        $foreign = ConsumerApplication::factory()->create();

        $this->actingAs($actor)->postJson(route('consumer-database.workspace.lead-link-decision.store', $foreign), ['status' => 'without_lead', 'reason' => 'uji'])->assertForbidden();
    }

    public function test_br_without_lead_decision_requires_manage_permission_and_consumer_scope(): void
    {
        [$consumer, $actor] = $this->decisionContext();
        $actor->update(['role_id' => Role::query()->where('slug', 'sales')->value('id')]);

        $this->expectException(AuthorizationException::class);
        app(ConsumerLeadLinkDecisionService::class)->decide($consumer, 'without_lead', null, ['reason' => 'Tidak ditemukan'], $actor->fresh());
    }

    public function test_br_scoped_manage_permission_allows_decision_inside_consumer_scope(): void
    {
        [$consumer, $actor] = $this->decisionContext();
        $actor->update(['role_id' => Role::query()->where('slug', 'sas')->value('id')]);

        $decision = app(ConsumerLeadLinkDecisionService::class)->decide($consumer, 'without_lead', null, ['reason' => 'Tidak ditemukan'], $actor->fresh());

        $this->assertSame(ConsumerLeadLinkDecision::STATUS_WITHOUT_LEAD, $decision->status);
    }

    public function test_br_linked_decision_rejects_consumer_outside_branch_or_project(): void
    {
        [$consumer, $actor] = $this->decisionContext();
        $foreignBranch = Branch::create(['code' => 'FOREIGN', 'name' => 'Cabang Asing', 'is_active' => true]);
        $foreignProject = LeadMaster::create(['branch_id' => $foreignBranch->id, 'project_name' => 'Proyek Asing', 'is_active' => true]);
        $foreignConsumer = ConsumerApplication::factory()->create(['branch_id' => $foreignBranch->id, 'project_id' => $foreignProject->id]);

        $this->expectException(AuthorizationException::class);
        app(ConsumerLeadLinkDecisionService::class)->decide($foreignConsumer, 'without_lead', null, ['reason' => 'Tidak ditemukan'], $actor);
    }

    public function test_br_linked_decision_rejects_lead_from_other_branch_or_project(): void
    {
        [$consumer, $actor] = $this->decisionContext();
        $foreign = ConsumerApplication::factory()->create();
        $lead = $this->lead($foreign, $actor);

        $this->expectException(AuthorizationException::class);
        app(ConsumerLeadLinkDecisionService::class)->decide($consumer, 'linked', $lead, [], $actor);
    }

    public function test_br_linked_decision_rejects_lead_already_actively_linked_elsewhere_without_mutation(): void
    {
        [$consumer, $actor] = $this->decisionContext();
        $other = ConsumerApplication::factory()->create(['branch_id' => $consumer->branch_id, 'project_id' => $consumer->project_id]);
        $lead = $this->lead($consumer, $actor);
        app(ConsumerLeadLinkDecisionService::class)->decide($other, 'linked', $lead, [], $actor);

        $beforeConsumer = $consumer->fresh(['salesLead']);
        $beforeDecisionCount = ConsumerLeadLinkDecision::query()->count();
        $beforeAuditCount = ActivityLog::query()->count();

        $this->expectException(ValidationException::class);
        app(ConsumerLeadLinkDecisionService::class)->decide($consumer, 'linked', $lead, [], $actor);

        $this->assertSame($beforeConsumer->sales_lead_id, $consumer->fresh()->sales_lead_id);
        $this->assertSame($beforeDecisionCount, ConsumerLeadLinkDecision::query()->count());
        $this->assertSame($beforeAuditCount, ActivityLog::query()->count());
    }

    public function test_br_without_lead_requires_explicit_reason_and_clears_existing_sales_lead_id(): void
    {
        [$consumer, $actor] = $this->decisionContext();
        $lead = $this->lead($consumer, $actor);
        $consumer->update(['sales_lead_id' => $lead->id]);

        $decision = app(ConsumerLeadLinkDecisionService::class)->decide($consumer, 'without_lead', null, ['reason' => 'Lead tidak ditemukan'], $actor);

        $this->assertSame(ConsumerLeadLinkDecision::STATUS_WITHOUT_LEAD, $decision->status);
        $this->assertNull($consumer->fresh()->sales_lead_id);
    }

    public function test_br_legacy_requires_sys_consumer_id_or_initial_consumer_id_anchor(): void
    {
        [$consumer, $actor] = $this->decisionContext();

        $this->expectException(ValidationException::class);
        app(ConsumerLeadLinkDecisionService::class)->decide($consumer, 'legacy', null, ['reason' => 'Data lama'], $actor);
    }

    public function test_br_link_decision_rejects_lead_for_without_lead_and_legacy(): void
    {
        [$consumer, $actor] = $this->decisionContext();
        $lead = $this->lead($consumer, $actor);

        $this->expectException(ValidationException::class);
        app(ConsumerLeadLinkDecisionService::class)->decide($consumer, 'without_lead', $lead, ['reason' => 'Tidak ditemukan'], $actor);
    }

    public function test_br_reconfirmation_is_idempotent_and_keeps_one_active_decision(): void
    {
        [$consumer, $actor] = $this->decisionContext();
        $first = app(ConsumerLeadLinkDecisionService::class)->decide($consumer, 'legacy', null, ['reason' => 'Data lama', 'legacy_anchor' => 'ID Konsumen Awal'], $actor);
        $second = app(ConsumerLeadLinkDecisionService::class)->decide($consumer, 'legacy', null, ['reason' => 'Data lama', 'legacy_anchor' => 'ID Konsumen Awal'], $actor);

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('consumer_lead_link_decisions', 1);
        $this->assertDatabaseCount('activity_log', 1);
    }

    public function test_br_idempotent_reconfirmation_repairs_stale_sales_lead_id(): void
    {
        [$consumer, $actor] = $this->decisionContext();
        $lead = $this->lead($consumer, $actor);
        $decision = app(ConsumerLeadLinkDecisionService::class)->decide($consumer, 'linked', $lead, [], $actor);
        $consumer->update(['sales_lead_id' => null]);

        $reconfirmed = app(ConsumerLeadLinkDecisionService::class)->decide($consumer, 'linked', $lead, [], $actor);

        $this->assertSame($decision->id, $reconfirmed->id);
        $this->assertDatabaseHas('consumer_applications', ['id' => $consumer->id, 'sales_lead_id' => $lead->id]);
        $this->assertDatabaseCount('activity_log', 1);
    }

    public function test_br_correction_closes_previous_decision_without_deleting_history_and_audits_change(): void
    {
        [$consumer, $actor] = $this->decisionContext();
        $first = app(ConsumerLeadLinkDecisionService::class)->decide($consumer, 'legacy', null, ['reason' => 'Data lama', 'legacy_anchor' => '[SYS] ID Konsumen'], $actor);
        $second = app(ConsumerLeadLinkDecisionService::class)->decide($consumer, 'without_lead', null, ['reason' => 'Tidak ditemukan'], $actor);

        $this->assertDatabaseHas('consumer_lead_link_decisions', ['id' => $first->id, 'is_active' => false]);
        $this->assertDatabaseHas('consumer_lead_link_decisions', ['id' => $second->id, 'is_active' => true]);
        $this->assertDatabaseCount('consumer_lead_link_decisions', 2);
        $this->assertDatabaseCount('activity_log', 2);
        $this->assertSame($first->id, data_get(ActivityLog::query()->latest('id')->firstOrFail()->properties, 'previous_decision_id'));
    }

    public function test_br_linked_decision_updates_only_sales_lead_id_and_preserves_process_fields(): void
    {
        [$consumer, $actor] = $this->decisionContext();
        $lead = $this->lead($consumer, $actor);
        $before = $consumer->fresh()->only([
            'id_transaksi', 'application_status', 'consumer_status', 'transaction_status',
            'current_stage', 'current_process', 'current_process_source', 'kavling_id',
            'payment_method', 'sales_lead_id',
        ]);

        app(ConsumerLeadLinkDecisionService::class)->decide($consumer, 'linked', $lead, [], $actor);

        $after = $consumer->fresh()->only(array_keys($before));
        $this->assertSame($lead->id, $after['sales_lead_id']);
        $this->assertSame($before['id_transaksi'], $after['id_transaksi']);
        foreach (array_diff(array_keys($before), ['sales_lead_id']) as $field) {
            $this->assertSame($before[$field], $after[$field], $field);
        }
    }

    public function test_br_decision_activity_log_contains_safe_metadata_only(): void
    {
        [$consumer, $actor] = $this->decisionContext();
        app(ConsumerLeadLinkDecisionService::class)->decide($consumer, 'without_lead', null, ['reason' => 'Tidak ditemukan'], $actor);
        $properties = ActivityLog::query()->latest('id')->firstOrFail()->properties;

        $this->assertSame($consumer->id, $properties['consumer_application_id']);
        $this->assertTrue($properties['reason_present']);
        $this->assertArrayNotHasKey('reason', $properties);
        $this->assertArrayNotHasKey('phone', $properties);
        $this->assertArrayNotHasKey('nik', $properties);
        $this->assertArrayNotHasKey('address', $properties);
    }

    public function test_br_decision_never_writes_sensitive_reason_to_activity_log(): void
    {
        [$consumer, $actor] = $this->decisionContext();

        app(ConsumerLeadLinkDecisionService::class)->decide($consumer, 'without_lead', null, ['reason' => 'NIK 1234567890123456 phone 081234567890'], $actor);

        $properties = ActivityLog::query()->latest('id')->firstOrFail()->properties;
        $this->assertArrayNotHasKey('reason', $properties);
    }

    public function test_br_submitted_suggestion_lead_must_match_consumer_scope(): void
    {
        [$consumer, $actor] = $this->decisionContext();
        $foreign = ConsumerApplication::factory()->create();
        $foreignLead = $this->lead($foreign, $actor);

        $this->expectException(AuthorizationException::class);
        app(ConsumerLeadLinkDecisionService::class)->decide(
            $consumer,
            'without_lead',
            null,
            ['reason' => 'Tidak ditemukan', 'suggestions' => [['lead_id' => $foreignLead->id]]],
            $actor,
        );
        $this->assertDatabaseCount('consumer_lead_link_decisions', 0);
    }

    public function test_br_suggestion_snapshot_rejects_unbounded_rationale(): void
    {
        [$consumer, $actor] = $this->decisionContext();
        $rationale = array_fill_keys(array_map(fn (int $index): string => "k{$index}", range(1, 13)), 'ok');

        $this->expectException(ValidationException::class);
        app(ConsumerLeadLinkDecisionService::class)->decide(
            $consumer,
            'without_lead',
            null,
            ['reason' => 'Tidak ditemukan', 'suggestions' => [['rationale' => $rationale]]],
            $actor,
        );
    }

    public function test_br_reason_accepts_ampersand_and_at_sign(): void
    {
        [$consumer, $actor] = $this->decisionContext();

        $decision = app(ConsumerLeadLinkDecisionService::class)->decide(
            $consumer,
            'without_lead',
            null,
            ['reason' => 'Diperiksa oleh A&B @ kantor'],
            $actor,
        );

        $this->assertSame('Diperiksa oleh A&B @ kantor', $decision->reason);
    }

    public function test_br_legacy_anchor_is_rejected_for_nonlegacy_decision(): void
    {
        [$consumer, $actor] = $this->decisionContext();

        $this->expectException(ValidationException::class);
        app(ConsumerLeadLinkDecisionService::class)->decide(
            $consumer,
            'without_lead',
            null,
            ['reason' => 'Tidak ditemukan', 'legacy_anchor' => 'ID Konsumen Awal'],
            $actor,
        );
    }

    public function test_br_supplemental_role_does_not_escalate_decision_access(): void
    {
        [$consumer, $actor] = $this->decisionContext();
        $actor->update(['role_id' => Role::query()->where('slug', 'sales')->value('id')]);
        $actor->roles()->attach(Role::query()->where('slug', 'superadmin')->value('id'));

        $this->expectException(AuthorizationException::class);
        app(ConsumerLeadLinkDecisionService::class)->decide($consumer, 'without_lead', null, ['reason' => 'Tidak ditemukan'], $actor->fresh());
    }

    public function test_br_decision_does_not_create_legacy_link_or_resolve_spreadsheet_writer(): void
    {
        [$consumer, $actor] = $this->decisionContext();
        app(ConsumerLeadLinkDecisionService::class)->decide($consumer, 'without_lead', null, ['reason' => 'Tidak ditemukan'], $actor);

        $this->assertDatabaseCount('sales_lead_consumer_links', 0);
        $this->assertDatabaseCount('consumer_lead_link_decisions', 1);
    }

    public function test_br_link_decision_allows_sheet_branch_without_oasis_input_migration(): void
    {
        [$consumer, $actor] = $this->decisionContext();
        BranchMigrationState::query()->where('branch_id', $consumer->branch_id)->update(['state' => BranchMigrationState::STATE_SHEET]);

        app(ConsumerLeadLinkDecisionService::class)->decide($consumer, 'without_lead', null, ['reason' => 'Tidak ditemukan'], $actor);

        $this->assertDatabaseCount('consumer_lead_link_decisions', 1);
    }

    public function test_br_request_validates_status_requirements_and_forbidden_fields(): void
    {
        [$consumer, $actor] = $this->decisionContext();
        $request = $this->decisionRequest($consumer, $actor, [
            'status' => 'without_lead',
            'sales_lead_id' => 1,
            'legacy_anchor' => 'ID Konsumen Awal',
            'decision_source' => 'system',
        ]);

        $validator = Validator::make($request->all(), $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('sales_lead_id', $validator->errors()->toArray());
        $this->assertArrayHasKey('reason', $validator->errors()->toArray());
        $this->assertArrayHasKey('legacy_anchor', $validator->errors()->toArray());
        $this->assertArrayHasKey('decision_source', $validator->errors()->toArray());
    }

    public function test_br_request_accepts_legacy_anchor_only_for_legacy_status(): void
    {
        [$consumer, $actor] = $this->decisionContext();
        $request = $this->decisionRequest($consumer, $actor, [
            'status' => 'legacy',
            'reason' => 'Data lama',
            'legacy_anchor' => 'ID Konsumen Awal',
        ]);

        $validator = Validator::make($request->all(), $request->rules());

        $this->assertFalse($validator->fails());
        $this->assertTrue($request->authorize());
    }

    public function test_br_request_allows_sheet_branch_for_link_decision(): void
    {
        [$consumer, $actor] = $this->decisionContext();
        BranchMigrationState::query()->where('branch_id', $consumer->branch_id)->update(['state' => BranchMigrationState::STATE_SHEET]);
        $request = $this->decisionRequest($consumer, $actor, ['status' => 'without_lead', 'reason' => 'Tidak ditemukan']);

        $this->assertTrue($request->authorize());
    }

    public function test_br_request_rejects_unbounded_suggestion_rationale(): void
    {
        [$consumer, $actor] = $this->decisionContext();
        $key = str_repeat('k', 65);
        $request = $this->decisionRequest($consumer, $actor, [
            'status' => 'without_lead',
            'reason' => 'Tidak ditemukan',
            'suggestions' => [['rationale' => [$key => 'ok']]],
        ]);
        $validator = Validator::make($request->all(), $request->rules());
        $request->withValidator($validator);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('suggestions.0.rationale', $validator->errors()->toArray());
    }

    private function decisionRequest(ConsumerApplication $consumer, User $actor, array $payload): StoreConsumerLeadLinkDecisionRequest
    {
        $request = StoreConsumerLeadLinkDecisionRequest::create('/consumer', 'POST', $payload);
        $request->setUserResolver(fn (): User => $actor);
        $route = new Route('POST', '/consumer', []);
        $route->bind($request);
        $route->setParameter('consumerApplication', $consumer);
        $route->setContainer($this->app);
        $request->setRouteResolver(fn () => $route);

        return $request;
    }

    public function test_br_bulk_legacy_marks_only_subjects_before_date(): void
    {
        [$branch, $project, $actor] = $this->bulkContext();
        KonsumenProgressSheetRow::create(['branch_id' => $branch->id, 'sheet_id' => 'sheet', 'sheet_name' => 'data_konsumen', 'row_hash' => 'before', 'row_data' => ['[SYS] ID Konsumen' => 'OLD-1', 'Nama Konsumen' => 'Lama', 'Tanggal' => '2025-01-01', 'Proyek' => $project->project_name]]);
        KonsumenProgressSheetRow::create(['branch_id' => $branch->id, 'sheet_id' => 'sheet', 'sheet_name' => 'data_konsumen', 'row_hash' => 'after', 'row_data' => ['[SYS] ID Konsumen' => 'NEW-1', 'Nama Konsumen' => 'Baru', 'Tanggal' => '2027-01-01', 'Proyek' => $project->project_name]]);
        $preview = app(ConsumerLeadLinkDecisionService::class)->previewBulkLegacy($actor, ['branch_id' => $branch->id, 'project_id' => $project->id, 'before' => '2026-01-01']);
        app(ConsumerLeadLinkDecisionService::class)->markBulkLegacy($actor, ['branch_id' => $branch->id, 'project_id' => $project->id, 'before' => '2026-01-01', 'token' => $preview['token']], 'Verifikasi data lama');

        $this->assertDatabaseHas('consumer_lead_link_decisions', ['legacy_consumer_key' => 'old-1', 'status' => 'legacy']);
        $this->assertDatabaseMissing('consumer_lead_link_decisions', ['legacy_consumer_key' => 'new-1']);
    }

    public function test_br_bulk_legacy_requires_branch_and_before(): void
    {
        [, $project, $actor] = $this->bulkContext();
        $service = app(ConsumerLeadLinkDecisionService::class);
        $this->expectException(ValidationException::class);
        $service->previewBulkLegacy($actor, ['project_id' => $project->id]);
    }

    public function test_br_bulk_legacy_rejects_branch_outside_scope_without_changes(): void
    {
        [, , $actor] = $this->bulkContext();
        $foreign = Branch::create(['code' => 'BULK-FOREIGN', 'name' => 'Asing', 'is_active' => true]);
        $this->expectException(AuthorizationException::class);
        app(ConsumerLeadLinkDecisionService::class)->previewBulkLegacy($actor, ['branch_id' => $foreign->id, 'before' => '2026-01-01']);
        $this->assertDatabaseCount('consumer_lead_link_decisions', 0);
    }

    public function test_br_bulk_legacy_rejects_missing_before_with_validation_error(): void
    {
        [$branch, , $actor] = $this->bulkContext();

        $this->expectException(ValidationException::class);
        app(ConsumerLeadLinkDecisionService::class)->previewBulkLegacy($actor, ['branch_id' => $branch->id]);
    }

    public function test_br_bulk_legacy_rejects_expired_token_and_changed_data_without_mutation(): void
    {
        [$branch, $project, $actor] = $this->bulkContext();
        KonsumenProgressSheetRow::create(['branch_id' => $branch->id, 'sheet_id' => 'sheet', 'sheet_name' => 'data_konsumen', 'row_hash' => 'changed', 'row_data' => ['[SYS] ID Konsumen' => 'CHANGED-1', 'Tanggal' => '2025-01-01', 'Proyek' => $project->project_name]]);
        $filters = ['branch_id' => $branch->id, 'project_id' => $project->id, 'before' => '2026-01-01'];
        $preview = app(ConsumerLeadLinkDecisionService::class)->previewBulkLegacy($actor, $filters);
        Cache::put('consumer-lead-link:bulk:'.$preview['token'], ['user_id' => $actor->id, 'filters' => $filters, 'keys' => ['other:key'], 'expires_at' => now()->subMinute()->timestamp], now()->addMinute());

        $this->expectException(ValidationException::class);
        app(ConsumerLeadLinkDecisionService::class)->markBulkLegacy($actor, [...$filters, 'token' => $preview['token']], 'Verifikasi');
        $this->assertDatabaseCount('consumer_lead_link_decisions', 0);
    }

    public function test_br_bulk_legacy_rejects_token_for_other_user_without_mutation(): void
    {
        [$branch, , $actor] = $this->bulkContext();
        $other = User::factory()->create(['role_id' => $actor->role_id, 'branch_id' => $branch->id]);
        $filters = ['branch_id' => $branch->id, 'before' => '2026-01-01'];
        $preview = app(ConsumerLeadLinkDecisionService::class)->previewBulkLegacy($actor, $filters);

        $this->expectException(ValidationException::class);
        app(ConsumerLeadLinkDecisionService::class)->markBulkLegacy($other, [...$filters, 'token' => $preview['token']], 'Verifikasi');
        $this->assertDatabaseCount('consumer_lead_link_decisions', 0);
    }

    public function test_br_bulk_legacy_rerun_same_token_is_idempotent(): void
    {
        [$branch, $project, $actor] = $this->bulkContext();
        KonsumenProgressSheetRow::create(['branch_id' => $branch->id, 'sheet_id' => 'sheet', 'sheet_name' => 'data_konsumen', 'row_hash' => 'once', 'row_data' => ['[SYS] ID Konsumen' => 'ONCE-1', 'Tanggal' => '2025-01-01', 'Proyek' => $project->project_name]]);
        $filters = ['branch_id' => $branch->id, 'project_id' => $project->id, 'before' => '2026-01-01'];
        $preview = app(ConsumerLeadLinkDecisionService::class)->previewBulkLegacy($actor, $filters);
        app(ConsumerLeadLinkDecisionService::class)->markBulkLegacy($actor, [...$filters, 'token' => $preview['token']], 'Verifikasi');
        app(ConsumerLeadLinkDecisionService::class)->markBulkLegacy($actor, [...$filters, 'token' => $preview['token']], 'Verifikasi');

        $this->assertDatabaseCount('consumer_lead_link_decisions', 1);
    }

    private function bulkContext(): array
    {
        $branch = Branch::create(['code' => 'BULK', 'name' => 'Bulk', 'is_active' => true]);
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Bulk Project', 'is_active' => true]);
        $actor = User::factory()->create(['role_id' => Role::query()->where('slug', 'branch_manager')->value('id'), 'branch_id' => $branch->id, 'email_verified_at' => now(), 'password_changed_at' => now(), 'must_change_password' => false]);
        $actor->branches()->sync([$branch->id => ['is_current' => true, 'can_view' => true, 'can_edit' => true, 'can_sync' => true, 'can_manage_members' => true]]);

        return [$branch, $project, $actor->fresh()];
    }

    private function decisionContext(): array
    {
        $consumer = ConsumerApplication::factory()->create();
        $actor = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'sas')->value('id'),
            'branch_id' => $consumer->branch_id,
            'email_verified_at' => now(),
            'password_changed_at' => now(),
            'must_change_password' => false,
        ]);
        $actor->branches()->syncWithoutDetaching([$consumer->branch_id => ['is_current' => true, 'can_view' => true, 'can_edit' => true, 'can_sync' => true, 'can_manage_members' => false]]);
        $actor->assignedProjects()->syncWithoutDetaching([$consumer->project_id => ['is_active' => true, 'is_primary' => true]]);

        return [$consumer, $actor->fresh()];
    }

    private function lead(ConsumerApplication $consumer, User $actor): SalesLead
    {
        return SalesLead::create([
            'branch_id' => $consumer->branch_id,
            'project_id' => $consumer->project_id,
            'sales_user_id' => $actor->id,
            'lead_date' => today(),
            'customer_name' => 'Lead Test',
        ]);
    }

    public function test_br_task4_workspace_detail_exposes_safe_lead_link_payload_and_actions(): void
    {
        [$consumer, $actor] = $this->decisionContext();
        $actor->update(['role_id' => Role::query()->where('slug', 'admin')->value('id')]);
        $consumer->customer()->update(['name' => 'Lead Test', 'phone' => '089000000000']);
        $consumer->refresh();
        $lead = $this->lead($consumer, $actor);

        $this->actingAs($actor->fresh())
            ->getJson(route('consumer-database.workspace.show', $consumer))
            ->assertOk()
            ->assertJsonPath('data.lead_link.consumer.id', $consumer->id)
            ->assertJsonPath('data.lead_link.consumer.id_transaksi', $consumer->id_transaksi)
            ->assertJsonPath('data.lead_link.suggestions.0.lead_id', $lead->id)
            ->assertJsonPath('data.lead_link.current', null)
            ->assertJsonPath('data.lead_link.allowed_actions.can_decide', true)
            ->assertJsonMissingPath('data.lead_link.consumer.nik')
            ->assertJsonMissingPath('data.lead_link.consumer.address');
    }

    public function test_br_task4_workspace_detail_exposes_current_decision_and_newest_first_history(): void
    {
        [$consumer, $actor] = $this->decisionContext();
        $actor->update(['role_id' => Role::query()->where('slug', 'admin')->value('id')]);
        $actor = $actor->fresh();
        app(ConsumerLeadLinkDecisionService::class)->decide($consumer, 'legacy', null, ['reason' => 'Data lama', 'legacy_anchor' => '[SYS] ID Konsumen'], $actor);
        app(ConsumerLeadLinkDecisionService::class)->decide($consumer, 'without_lead', null, ['reason' => 'Tidak ditemukan'], $actor);

        $this->actingAs($actor)
            ->getJson(route('consumer-database.workspace.show', $consumer))
            ->assertOk()
            ->assertJsonPath('data.lead_link.current.status', 'without_lead')
            ->assertJsonPath('data.lead_link.history.0.status', 'without_lead')
            ->assertJsonPath('data.lead_link.history.1.status', 'legacy')
            ->assertJsonPath('data.lead_link.history.1.legacy_anchor', '[SYS] ID Konsumen');
    }

    public function test_br_task4_workspace_decision_endpoint_accepts_json_and_is_idempotent(): void
    {
        [$consumer, $actor] = $this->decisionContext();

        $payload = ['status' => 'without_lead', 'reason' => 'Lead tidak ditemukan'];
        $this->actingAs($actor)
            ->postJson(route('consumer-database.workspace.lead-link-decision.store', $consumer), $payload)
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.status', 'without_lead');
        $this->actingAs($actor)
            ->postJson(route('consumer-database.workspace.lead-link-decision.store', $consumer), $payload)
            ->assertOk();

        $this->assertDatabaseCount('consumer_lead_link_decisions', 1);
        $this->assertDatabaseCount('activity_log', 1);
    }

    public function test_br_task4_ui_echoed_suggestion_payload_is_accepted_and_snapshot_stays_minimal(): void
    {
        [$consumer, $actor] = $this->decisionContext();
        $actor->update(['role_id' => Role::query()->where('slug', 'admin')->value('id')]);
        $actor = $actor->fresh();
        $consumer->customer()->update(['name' => 'Lead Test', 'phone' => '089000000000']);
        $consumer->refresh();
        $lead = $this->lead($consumer, $actor);

        $suggestions = $this->actingAs($actor)
            ->getJson(route('consumer-database.workspace.show', $consumer))
            ->assertOk()
            ->json('data.lead_link.suggestions');

        $this->assertNotEmpty($suggestions);
        $this->assertArrayHasKey('lead_name', $suggestions[0]);
        $this->assertArrayHasKey('sales_name', $suggestions[0]);

        $this->actingAs($actor)
            ->postJson(route('consumer-database.workspace.lead-link-decision.store', $consumer), [
                'status' => 'without_lead',
                'reason' => 'Lead belum ditemukan',
                'suggestions' => $suggestions,
            ])
            ->assertOk();

        $snapshot = ConsumerLeadLinkDecision::query()->sole()->suggestions;

        $this->assertSame($lead->id, $snapshot[0]['lead_id']);
        $this->assertArrayNotHasKey('lead_name', $snapshot[0]);
        $this->assertArrayNotHasKey('sales_name', $snapshot[0]);
    }

    public function test_br_task4_workspace_decision_endpoint_returns_json_validation_errors_without_mutation(): void
    {
        [$consumer, $actor] = $this->decisionContext();

        $this->actingAs($actor)
            ->postJson(route('consumer-database.workspace.lead-link-decision.store', $consumer), ['status' => 'without_lead'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reason']);

        $this->assertDatabaseCount('consumer_lead_link_decisions', 0);
    }

    public function test_br_task4_workspace_decision_endpoint_redirects_normal_form_validation_failure_with_reason_error(): void
    {
        [$consumer, $actor] = $this->decisionContext();

        $this->actingAs($actor)
            ->from(route('consumer-database.workspace'))
            ->post(route('consumer-database.workspace.lead-link-decision.store', $consumer), ['status' => 'without_lead'])
            ->assertRedirect(route('consumer-database.workspace'))
            ->assertSessionHasErrors(['reason']);

        $this->assertDatabaseCount('consumer_lead_link_decisions', 0);
    }

    public function test_br_task4_workspace_detail_endpoint_rejects_out_of_scope_consumer_direct_get(): void
    {
        [$consumer, $actor] = $this->decisionContext();
        $foreignBranch = Branch::create(['code' => 'FOREIGN-GET', 'name' => 'Cabang Asing GET', 'is_active' => true]);
        $foreignProject = LeadMaster::create(['branch_id' => $foreignBranch->id, 'project_name' => 'Proyek Asing GET', 'is_active' => true]);
        $foreignConsumer = ConsumerApplication::factory()->create([
            'branch_id' => $foreignBranch->id,
            'project_id' => $foreignProject->id,
        ]);

        $this->actingAs($actor)
            ->getJson(route('consumer-database.workspace.show', $foreignConsumer))
            ->assertForbidden();
    }

    public function test_br_task4_workspace_decision_endpoint_rejects_foreign_consumer_and_view_only_actor(): void
    {
        [$consumer, $actor] = $this->decisionContext();
        $actor->update(['role_id' => Role::query()->where('slug', 'admin')->value('id')]);
        $actor = $actor->fresh();
        [, $foreignActor] = $this->decisionContext();
        $foreignActor->update(['role_id' => Role::query()->where('slug', 'sales')->value('id')]);

        $this->actingAs($foreignActor)
            ->postJson(route('consumer-database.workspace.lead-link-decision.store', $consumer), ['status' => 'without_lead', 'reason' => 'Tidak ditemukan'])
            ->assertForbidden();

        $this->actingAs($actor)
            ->get(route('consumer-database.workspace'))
            ->assertOk()
            ->assertSee('Penautan Lead')
            ->assertSee('Tanpa Lead')
            ->assertSee('Data Lama')
            ->assertSee('Alasan')
            ->assertSee('Riwayat koreksi');
    }

    public function test_br_task4_workspace_decision_endpoint_redirects_for_normal_form_request(): void
    {
        [$consumer, $actor] = $this->decisionContext();

        $this->actingAs($actor)
            ->from(route('consumer-database.workspace'))
            ->post(route('consumer-database.workspace.lead-link-decision.store', $consumer), ['status' => 'without_lead', 'reason' => 'Tidak ditemukan'])
            ->assertRedirect(route('consumer-database.workspace'))
            ->assertSessionHas('success', 'Keputusan penautan Lead berhasil disimpan.');
    }

    public function test_br_link_decision_schema_has_audited_fields_and_active_unique_index(): void
    {
        $this->assertTrue(Schema::hasTable('consumer_lead_link_decisions'));
        $this->assertTrue(Schema::hasColumns('consumer_lead_link_decisions', [
            'consumer_application_id',
            'status',
            'sales_lead_id',
            'decision_source',
            'suggestions',
            'reason',
            'decided_by',
            'decided_at',
            'is_active',
        ]));
        $this->assertTrue(Schema::hasIndex('consumer_lead_link_decisions', 'consumer_lead_link_decisions_active_unique'));
    }

    public function test_br_link_decision_casts_and_relations_are_defined(): void
    {
        $application = ConsumerApplication::factory()->create();
        $actor = User::factory()->create();
        $lead = SalesLead::create([
            'branch_id' => $application->branch_id,
            'project_id' => $application->project_id,
            'sales_user_id' => $actor->id,
            'lead_date' => today(),
            'customer_name' => 'Lead Test',
        ]);
        $decision = ConsumerLeadLinkDecision::create([
            'consumer_application_id' => $application->id,
            'status' => ConsumerLeadLinkDecision::STATUS_LINKED,
            'sales_lead_id' => $lead->id,
            'decision_source' => 'manual',
            'suggestions' => ['lead_id' => $lead->id, 'score' => 0.92],
            'reason' => 'Konfirmasi pemilik data',
            'decided_by' => $actor->id,
            'decided_at' => now(),
            'is_active' => true,
        ]);

        $this->assertSame(['lead_id' => $lead->id, 'score' => 0.92], $decision->suggestions);
        $this->assertTrue($decision->decided_at->isToday());
        $this->assertTrue($decision->consumerApplication->is($application));
        $this->assertTrue($decision->salesLead->is($lead));
        $this->assertTrue($decision->decidedBy->is($actor));
        $this->assertTrue($application->linkDecisions->first()->is($decision));
        $this->assertTrue($lead->linkDecisions->first()->is($decision));
    }

    public function test_br_foreign_key_rejects_unknown_consumer_reference_independently(): void
    {
        $this->expectException(QueryException::class);

        ConsumerLeadLinkDecision::create([
            'consumer_application_id' => 999999,
            'status' => ConsumerLeadLinkDecision::STATUS_WITHOUT_LEAD,
            'decision_source' => 'manual',
            'reason' => 'Belum ada Lead',
            'decided_at' => now(),
        ]);
    }

    public function test_br_foreign_key_rejects_unknown_sales_lead_reference_independently(): void
    {
        $application = ConsumerApplication::factory()->create();

        $this->expectException(QueryException::class);

        ConsumerLeadLinkDecision::create([
            'consumer_application_id' => $application->id,
            'status' => ConsumerLeadLinkDecision::STATUS_LINKED,
            'sales_lead_id' => 999999,
            'decision_source' => 'manual',
            'reason' => 'Konfirmasi pemilik data',
            'decided_at' => now(),
        ]);
    }

    public function test_br_foreign_key_rejects_unknown_decided_by_reference_independently(): void
    {
        $application = ConsumerApplication::factory()->create();

        $this->expectException(QueryException::class);

        ConsumerLeadLinkDecision::create([
            'consumer_application_id' => $application->id,
            'status' => ConsumerLeadLinkDecision::STATUS_WITHOUT_LEAD,
            'decision_source' => 'manual',
            'reason' => 'Belum ada Lead',
            'decided_at' => now(),
            'decided_by' => 999999,
        ]);
    }

    public function test_br_sales_lead_delete_nulls_decision_reference(): void
    {
        $application = ConsumerApplication::factory()->create();
        $actor = User::factory()->create();
        $lead = SalesLead::create([
            'branch_id' => $application->branch_id,
            'project_id' => $application->project_id,
            'sales_user_id' => $actor->id,
            'lead_date' => today(),
            'customer_name' => 'Lead Delete Test',
        ]);
        $decision = ConsumerLeadLinkDecision::create([
            'consumer_application_id' => $application->id,
            'status' => ConsumerLeadLinkDecision::STATUS_LINKED,
            'sales_lead_id' => $lead->id,
            'decision_source' => 'manual',
            'decided_at' => now(),
        ]);

        $lead->forceDelete();

        $this->assertDatabaseHas('consumer_lead_link_decisions', [
            'id' => $decision->id,
            'sales_lead_id' => null,
        ]);
    }

    public function test_br_decided_by_delete_nulls_decision_reference(): void
    {
        $application = ConsumerApplication::factory()->create();
        $actor = User::factory()->create();
        $decision = ConsumerLeadLinkDecision::create([
            'consumer_application_id' => $application->id,
            'status' => ConsumerLeadLinkDecision::STATUS_WITHOUT_LEAD,
            'decision_source' => 'manual',
            'decided_at' => now(),
            'decided_by' => $actor->id,
        ]);

        $actor->delete();

        $this->assertDatabaseHas('consumer_lead_link_decisions', [
            'id' => $decision->id,
            'decided_by' => null,
        ]);
    }

    public function test_br_consumer_delete_is_restricted_by_decision_reference(): void
    {
        $application = ConsumerApplication::factory()->create();
        ConsumerLeadLinkDecision::create([
            'consumer_application_id' => $application->id,
            'status' => ConsumerLeadLinkDecision::STATUS_WITHOUT_LEAD,
            'decision_source' => 'manual',
            'decided_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        DB::table('consumer_applications')->where('id', $application->id)->delete();
    }

    public function test_br_mysql_generated_active_key_rejects_duplicate_active_decision_and_recovers_partial_migration(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL-only generated-column coverage.');
        }

        $this->assertTrue(Schema::hasColumn('consumer_lead_link_decisions', 'active_consumer_application_key'));
        DB::statement('DROP INDEX consumer_lead_link_decisions_active_unique ON consumer_lead_link_decisions');

        $migration = require database_path('migrations/2026_11_24_100000_create_consumer_lead_link_decisions_table.php');
        $migration->up();

        $this->assertTrue(Schema::hasColumn('consumer_lead_link_decisions', 'active_consumer_application_key'));
        $this->assertTrue(Schema::hasIndex('consumer_lead_link_decisions', 'consumer_lead_link_decisions_active_unique'));

        $application = ConsumerApplication::factory()->create();
        $attributes = [
            'consumer_application_id' => $application->id,
            'status' => ConsumerLeadLinkDecision::STATUS_WITHOUT_LEAD,
            'decision_source' => 'manual',
            'reason' => 'Belum ada Lead',
            'decided_at' => now(),
            'is_active' => true,
        ];
        ConsumerLeadLinkDecision::create($attributes);

        $this->expectException(QueryException::class);
        ConsumerLeadLinkDecision::create($attributes);
    }

    public function test_br_database_allows_only_one_active_decision_per_consumer(): void
    {
        $application = ConsumerApplication::factory()->create();
        $attributes = [
            'consumer_application_id' => $application->id,
            'status' => ConsumerLeadLinkDecision::STATUS_WITHOUT_LEAD,
            'decision_source' => 'manual',
            'reason' => 'Belum ada Lead',
            'decided_at' => now(),
            'is_active' => true,
        ];
        ConsumerLeadLinkDecision::create($attributes);

        $this->expectException(QueryException::class);
        ConsumerLeadLinkDecision::create($attributes);
    }

    public function test_br_inactive_decisions_preserve_audit_history_and_do_not_conflict(): void
    {
        $application = ConsumerApplication::factory()->create();
        $attributes = [
            'consumer_application_id' => $application->id,
            'status' => ConsumerLeadLinkDecision::STATUS_LEGACY,
            'decision_source' => 'migration_review',
            'reason' => 'Data lama',
            'decided_at' => now(),
            'is_active' => false,
        ];

        ConsumerLeadLinkDecision::create($attributes);
        ConsumerLeadLinkDecision::create($attributes);

        $this->assertDatabaseCount('consumer_lead_link_decisions', 2);
    }

    public function test_br_link_decision_migration_and_changelog_are_idempotent(): void
    {
        $migration = require database_path('migrations/2026_11_24_100000_create_consumer_lead_link_decisions_table.php');
        $changelogMigration = require database_path('migrations/2026_11_24_100001_add_wp29_consumer_lead_link_changelog.php');

        $migration->up();
        $migration->up();
        $changelogMigration->up();
        $changelogMigration->up();

        $this->assertTrue(Schema::hasTable('consumer_lead_link_decisions'));
        $this->assertSame(1, DB::table('changelogs')->whereNull('version')->where('title', 'Penautan Lead dan Konsumen ter-audit')->count());

        $changelogMigration->down();
        $this->assertSame(0, DB::table('changelogs')->whereNull('version')->where('title', 'Penautan Lead dan Konsumen ter-audit')->count());
    }
}
