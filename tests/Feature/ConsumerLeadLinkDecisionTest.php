<?php

namespace Tests\Feature;

use App\Http\Requests\Crm\StoreConsumerLeadLinkDecisionRequest;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\BranchMigrationState;
use App\Models\ConsumerApplication;
use App\Models\ConsumerLeadLinkDecision;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\SalesLead;
use App\Models\User;
use App\Services\ConsumerLeadLinkDecisionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
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

    public function test_br_migration_guard_rejects_blocked_branch_without_mutation(): void
    {
        [$consumer, $actor] = $this->decisionContext();
        BranchMigrationState::query()->where('branch_id', $consumer->branch_id)->update(['state' => BranchMigrationState::STATE_SHEET]);

        $this->expectException(AuthorizationException::class);
        app(ConsumerLeadLinkDecisionService::class)->decide($consumer, 'without_lead', null, ['reason' => 'Tidak ditemukan'], $actor);
        $this->assertDatabaseCount('consumer_lead_link_decisions', 0);
    }

    public function test_br_request_validates_status_requirements_and_forbidden_fields(): void
    {
        [$consumer, $actor] = $this->decisionContext();
        $request = StoreConsumerLeadLinkDecisionRequest::create('/consumer', 'POST', [
            'status' => 'without_lead',
            'sales_lead_id' => 1,
            'legacy_anchor' => 'ID Konsumen Awal',
            'decision_source' => 'system',
        ]);
        $request->setUserResolver(fn (): User => $actor);
        $route = new Route('POST', '/consumer', []);
        $route->bind($request);
        $route->setParameter('consumerApplication', $consumer);
        $route->setContainer($this->app);
        $request->setRouteResolver(fn () => $route);

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
        $request = StoreConsumerLeadLinkDecisionRequest::create('/consumer', 'POST', [
            'status' => 'legacy',
            'reason' => 'Data lama',
            'legacy_anchor' => 'ID Konsumen Awal',
        ]);
        $request->setUserResolver(fn (): User => $actor);
        $route = new Route('POST', '/consumer', []);
        $route->bind($request);
        $route->setParameter('consumerApplication', $consumer);
        $route->setContainer($this->app);
        $request->setRouteResolver(fn () => $route);

        $validator = Validator::make($request->all(), $request->rules());

        $this->assertFalse($validator->fails());
        $this->assertTrue($request->authorize());
    }

    public function test_br_request_rejects_blocked_migration_branch(): void
    {
        [$consumer, $actor] = $this->decisionContext();
        BranchMigrationState::query()->where('branch_id', $consumer->branch_id)->update(['state' => BranchMigrationState::STATE_SHEET]);
        $request = StoreConsumerLeadLinkDecisionRequest::create('/consumer', 'POST', ['status' => 'without_lead', 'reason' => 'Tidak ditemukan']);
        $request->setUserResolver(fn (): User => $actor);
        $route = new Route('POST', '/consumer', []);
        $route->bind($request);
        $route->setParameter('consumerApplication', $consumer);
        $route->setContainer($this->app);
        $request->setRouteResolver(fn () => $route);

        $this->assertFalse($request->authorize());
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

    public function test_br_task4_workspace_decision_endpoint_returns_json_validation_errors_without_mutation(): void
    {
        [$consumer, $actor] = $this->decisionContext();

        $this->actingAs($actor)
            ->postJson(route('consumer-database.workspace.lead-link-decision.store', $consumer), ['status' => 'without_lead'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reason']);

        $this->assertDatabaseCount('consumer_lead_link_decisions', 0);
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
