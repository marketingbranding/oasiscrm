<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\ConsumerApplication;
use App\Models\ConsumerIssue;
use App\Models\ConsumerKavlingAssignment;
use App\Models\ConsumerNup;
use App\Models\Kavling;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\SalesLead;
use App\Models\User;
use App\Services\AppSettingResolver;
use App\Services\ConsumerApplicationLifecycleService;
use App\Services\ConsumerEntryService;
use App\Services\ConsumerKavlingLifecycleService;
use App\Services\ConsumerNupService;
use App\Services\ConsumerOperationalService;
use App\Services\ConsumerProcessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConsumerV2ProcessTest extends TestCase
{
    use RefreshDatabase;

    /** BR-36: historical data starts at current process position without fabricated history. */
    public function test_br36_historical_entry_starts_at_current_process_without_fake_history(): void
    {
        $application = ConsumerApplication::factory()->create();
        $actor = $this->superadmin($application);

        $response = $this->actingAs($actor)->postJson(route('consumer-database.workspace.store'), [
            'name' => 'Konsumen Lama', 'nik' => '3201010101010001', 'phone' => '08123456789',
            'branch_id' => $application->branch_id, 'project_id' => $application->project_id,
            'entry_mode' => 'historical', 'current_process' => 'akad', 'payment_method' => 'kpr',
        ]);

        $response->assertCreated();
        $created = ConsumerApplication::query()->findOrFail($response->json('data.id'));
        $this->assertSame('akad', $created->current_process);
        $this->assertSame('historical_entry', $created->current_process_source);
        $this->assertSame(0, $created->stageEvents()->count());
    }

    public function test_nups_index_handles_converted_nup_without_application_reference(): void
    {
        $application = ConsumerApplication::factory()->create();
        $actor = $this->superadmin($application);
        ConsumerNup::factory()->create([
            'branch_id' => $application->branch_id,
            'project_id' => $application->project_id,
            'status' => 'converted',
            'converted_application_id' => null,
        ]);

        $this->actingAs($actor)
            ->get(route('consumer-nups.index'))
            ->assertOk()
            ->assertSee('Transaksi tidak tersedia');
    }

    public function test_nups_index_uses_success_badge_and_transaction_link_on_mobile_for_converted_nup(): void
    {
        config(['app.workspace_v2_enabled' => false]);
        $application = ConsumerApplication::factory()->create();
        $actor = $this->superadmin($application);
        $nup = ConsumerNup::factory()->create([
            'branch_id' => $application->branch_id,
            'project_id' => $application->project_id,
            'status' => 'converted',
            'converted_application_id' => $application->id,
        ]);

        $this->actingAs($actor)
            ->get(route('consumer-nups.index'))
            ->assertOk()
            ->assertSee('crm-button--secondary')
            ->assertSee('Buka transaksi')
            ->assertSee(route('consumer-database.workspace', ['search' => $application->id_transaksi]));
    }

    public function test_nups_index_uses_workspace_transactions_page_when_workspace_flag_is_enabled(): void
    {
        config(['app.workspace_v2_enabled' => true]);
        $application = ConsumerApplication::factory()->create();
        $actor = $this->superadmin($application);
        ConsumerNup::factory()->create([
            'branch_id' => $application->branch_id,
            'project_id' => $application->project_id,
            'status' => 'converted',
            'converted_application_id' => $application->id,
        ]);

        $this->actingAs($actor)
            ->get(route('consumer-nups.index'))
            ->assertOk()
            ->assertSee(route('workspace-v2.transactions', ['search' => $application->id_transaksi]))
            ->assertDontSee(route('consumer-database.workspace.show', $application));
    }

    public function test_br20_nup_conversion_requires_kavling_and_reuses_it_idempotently(): void
    {
        $application = ConsumerApplication::factory()->create();
        $actor = $this->superadmin($application);
        $kavling = Kavling::create(['project_id' => $application->project_id, 'kavling_code' => 'N-01', 'name' => 'N-01']);

        $this->actingAs($actor)->postJson(route('consumer-nups.store'), [
            'nup_number' => 'NUP-9001', 'registered_at' => '2026-10-01', 'name' => 'NUP Satu', 'phone' => '08123456780',
            'branch_id' => $application->branch_id, 'project_id' => $application->project_id,
        ])->assertCreated();
        $nup = ConsumerNup::query()->where('nup_number', 'NUP-9001')->firstOrFail();

        $this->actingAs($actor)->postJson(route('consumer-nups.convert', $nup), [])->assertUnprocessable();
        $first = $this->actingAs($actor)->postJson(route('consumer-nups.convert', $nup), ['kavling_id' => $kavling->id])->assertOk();
        $second = $this->actingAs($actor)->postJson(route('consumer-nups.convert', $nup), ['kavling_id' => $kavling->id])->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, ConsumerApplication::query()->where('source_nup_id', $nup->id)->count());
        $this->assertDatabaseHas('consumer_nups', ['id' => $nup->id, 'status' => 'converted']);
    }

    public function test_br20_nup_from_lead_requires_utj_and_has_no_kavling(): void
    {
        $application = ConsumerApplication::factory()->create();
        $actor = $this->superadmin($application);
        $lead = SalesLead::query()->create([
            'branch_id' => $application->branch_id,
            'project_id' => $application->project_id,
            'sales_user_id' => $actor->id,
            'customer_name' => 'Lead NUP',
            'phone' => '08123456780',
            'lead_date' => today(),
            'lead_stage' => 'survey',
        ]);
        $service = app(ConsumerNupService::class);

        $this->expectException(\DomainException::class);
        $service->createFromLead($lead, ['nup_number' => 'NUP-BR20'], $actor);
    }

    public function test_br20_utj_lead_creates_from_lead_nup_without_kavling(): void
    {
        $application = ConsumerApplication::factory()->create();
        $actor = $this->superadmin($application);
        $lead = SalesLead::query()->create([
            'branch_id' => $application->branch_id,
            'project_id' => $application->project_id,
            'sales_user_id' => $actor->id,
            'customer_name' => 'Lead UTJ',
            'phone' => '08123456781',
            'lead_date' => today(),
            'lead_stage' => 'utj',
            'utj_at' => now(),
        ]);

        $nup = app(ConsumerNupService::class)->createFromLead($lead, ['nup_number' => 'NUP-BR20-UTJ'], $actor);

        $this->assertSame('from_lead', $nup->entry_type);
        $this->assertSame((string) $lead->id, $nup->source_id);
        $this->assertNull($nup->converted_application_id);
        $this->assertNull($nup->source_application_id);
    }

    public function test_br22_nup_conversion_rejects_occupied_kavling_without_mutation(): void
    {
        $application = ConsumerApplication::factory()->create();
        $actor = $this->superadmin($application);
        $kavling = Kavling::create(['project_id' => $application->project_id, 'kavling_code' => 'N-02', 'name' => 'N-02']);
        $occupied = ConsumerApplication::factory()->create(['branch_id' => $application->branch_id, 'project_id' => $application->project_id]);
        app(ConsumerKavlingLifecycleService::class)->assign($occupied, $kavling);
        $nup = ConsumerNup::factory()->create(['branch_id' => $application->branch_id, 'project_id' => $application->project_id]);

        $this->expectException(\DomainException::class);
        app(ConsumerNupService::class)->convert($nup, ['kavling_id' => $kavling->id], $actor);

        $this->assertDatabaseHas('consumer_nups', ['id' => $nup->id, 'status' => 'waiting', 'converted_application_id' => null]);
        $this->assertDatabaseCount('consumer_applications', 2);
    }

    /**
     * BR-25: two conversion attempts for one kavling leave one LANJUT holder.
     * SQLite test DB cannot run two independent transactions concurrently; sequential collision exercises same invariant deterministically.
     */
    public function test_br25_second_nup_conversion_to_same_kavling_is_rejected_without_mutation(): void
    {
        $application = ConsumerApplication::factory()->create();
        $actor = $this->superadmin($application);
        $kavling = Kavling::create(['project_id' => $application->project_id, 'kavling_code' => 'N-03', 'name' => 'N-03']);
        $first = ConsumerNup::factory()->create(['branch_id' => $application->branch_id, 'project_id' => $application->project_id, 'nup_number' => 'NUP-BR25-1']);
        $second = ConsumerNup::factory()->create(['branch_id' => $application->branch_id, 'project_id' => $application->project_id, 'nup_number' => 'NUP-BR25-2']);
        $service = app(ConsumerNupService::class);

        $holder = $service->convert($first, ['kavling_id' => $kavling->id], $actor);

        try {
            $service->convert($second, ['kavling_id' => $kavling->id], $actor);
            $this->fail('Kavling tidak boleh memiliki dua transaksi LANJUT.');
        } catch (\DomainException $exception) {
            $this->assertSame('Kavling sudah memiliki assignment aktif.', $exception->getMessage());
        }

        $this->assertSame('LANJUT', $holder->fresh()->transaction_status);
        $this->assertDatabaseHas('consumer_nups', ['id' => $first->id, 'status' => 'converted', 'converted_application_id' => $holder->id]);
        $this->assertDatabaseHas('consumer_nups', ['id' => $second->id, 'status' => 'waiting', 'converted_application_id' => null]);
        $this->assertSame(1, ConsumerKavlingAssignment::query()->where('kavling_id', $kavling->id)->whereNull('released_at')->count());
    }

    public function test_br22_nup_conversion_rejects_selected_project_outside_actor_scope(): void
    {
        $application = ConsumerApplication::factory()->create();
        $foreignProject = LeadMaster::create([
            'branch_id' => $application->branch_id,
            'project_name' => 'Proyek Di Luar Scope',
            'is_active' => true,
        ]);
        $actor = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'staff')->value('id'),
            'branch_id' => $application->branch_id,
            'password_changed_at' => now(),
        ]);
        $actor->assignedProjects()->attach($application->project_id, ['is_active' => true, 'is_primary' => true]);
        $nup = ConsumerNup::factory()->create(['branch_id' => $application->branch_id, 'project_id' => $application->project_id]);
        $kavling = Kavling::create(['project_id' => $foreignProject->id, 'kavling_code' => 'N-03', 'name' => 'N-03']);

        $this->expectException(AuthorizationException::class);
        app(ConsumerNupService::class)->convert($nup, ['project_id' => $foreignProject->id, 'kavling_id' => $kavling->id], $actor);

        $this->assertDatabaseHas('consumer_nups', ['id' => $nup->id, 'status' => 'waiting', 'converted_application_id' => null]);
        $this->assertDatabaseCount('consumer_applications', 1);
    }

    public function test_br21_return_to_nup_rejects_non_lanjut_transaction_without_mutation(): void
    {
        foreach (['MUNDUR', 'DITUTUP', 'REPLACED', 'STATUS ACAK'] as $status) {
            $application = ConsumerApplication::factory()->create(['transaction_status' => $status]);
            $actor = $this->superadmin($application);
            $kavling = Kavling::create([
                'project_id' => $application->project_id,
                'kavling_code' => 'R-'.$status,
                'name' => 'R-'.$status,
            ]);
            app(ConsumerKavlingLifecycleService::class)->assign($application, $kavling);
            $issue = ConsumerIssue::factory()->create(['consumer_application_id' => $application->id]);

            try {
                app(ConsumerNupService::class)->returnFromTransaction($application, $issue, ['nup_number' => 'NUP-'.$status], $actor);
                $this->fail('Transaksi non-LANJUT atau perlu tinjau seharusnya ditolak.');
            } catch (\DomainException $exception) {
                $this->assertSame('Transaksi harus berstatus LANJUT untuk dikembalikan ke NUP.', $exception->getMessage());
            }

            $this->assertDatabaseHas('consumer_applications', ['id' => $application->id, 'transaction_status' => $status, 'kavling_id' => $kavling->id]);
            $this->assertDatabaseHas('consumer_kavling_assignments', ['consumer_application_id' => $application->id, 'assignment_status' => 'active']);
            $this->assertDatabaseMissing('consumer_nups', ['source_application_id' => $application->id]);
        }
    }

    public function test_br21_return_to_nup_requires_reason_and_confirmation(): void
    {
        $application = ConsumerApplication::factory()->create();
        $actor = $this->superadmin($application);
        $issue = ConsumerIssue::factory()->create(['consumer_application_id' => $application->id]);

        $this->actingAs($actor)->postJson(route('consumer-nups.return', $application), [
            'issue_id' => $issue->id,
            'nup_number' => 'NUP-REQUIRED',
        ])->assertUnprocessable();
    }

    public function test_br21_return_to_nup_json_includes_success_feedback(): void
    {
        $application = ConsumerApplication::factory()->create();
        $actor = $this->superadmin($application);
        $issue = ConsumerIssue::factory()->create(['consumer_application_id' => $application->id]);

        $this->actingAs($actor)->postJson(route('consumer-nups.return', $application), [
            'issue_id' => $issue->id,
            'nup_number' => 'NUP-FEEDBACK',
            'reason' => 'Koreksi UAT',
            'confirmation' => '1',
        ])->assertCreated()->assertJsonPath('message', 'Transaksi berhasil dikembalikan ke NUP.');
    }

    public function test_br21_return_to_nup_route_accepts_method_override_payload(): void
    {
        $application = ConsumerApplication::factory()->create();
        $actor = $this->superadmin($application);
        $issue = ConsumerIssue::factory()->create(['consumer_application_id' => $application->id]);

        $this->actingAs($actor)->post(route('consumer-issues.resolve', $issue), ['_method' => 'PATCH', 'resolution' => 'Selesai'])->assertRedirect();
        $this->assertDatabaseHas('consumer_issues', ['id' => $issue->id, 'status' => 'resolved', 'resolution' => 'Selesai']);
    }

    public function test_br21_return_to_nup_releases_kavling_closes_transaction_and_links_issue(): void
    {
        $application = ConsumerApplication::factory()->create();
        $actor = $this->superadmin($application);
        $kavling = Kavling::create(['project_id' => $application->project_id, 'kavling_code' => 'R-01', 'name' => 'R-01']);
        app(ConsumerKavlingLifecycleService::class)->assign($application, $kavling);
        $issue = ConsumerIssue::factory()->create(['consumer_application_id' => $application->id]);

        $nup = app(ConsumerNupService::class)->returnFromTransaction($application, $issue, ['nup_number' => 'NUP-RETURN'], $actor);

        $this->assertSame('returned', $nup->entry_type);
        $this->assertSame($application->id, $nup->source_application_id);
        $this->assertSame($issue->id, $nup->issue_id);
        $this->assertDatabaseHas('consumer_applications', ['id' => $application->id, 'transaction_status' => 'DITUTUP', 'closed_by_event' => 'KEMBALI_KE_NUP']);
        $this->assertDatabaseHas('consumer_kavling_assignments', ['consumer_application_id' => $application->id, 'release_reason' => 'kembali_ke_nup']);
    }

    public function test_br20_existing_nup_without_provenance_reads_as_legacy(): void
    {
        $nup = ConsumerNup::factory()->create(['entry_type' => null]);

        $this->assertSame('legacy', $nup->fresh()->provenanceType());
    }

    /** BR-35: PSJB may be recorded before SLIK. */
    public function test_br35_psjb_can_be_recorded_before_slik(): void
    {
        $application = ConsumerApplication::factory()->create();
        $actor = $this->superadmin($application);

        $psjb = app(ConsumerApplicationLifecycleService::class)->recordPsjb($application, ['tanggal_psjb' => '2026-10-02', 'cara_pembayaran' => 'KPR'], $actor);

        $this->assertSame($application->id, $psjb->consumer_application_id);
        $this->assertSame('psjb', $application->fresh()->current_process);
        $this->assertSame(0, $application->fresh()->stageEvents()->where('stage', 'bi_checking')->count());
    }

    /** BR-35: SLIK may be recorded before PSJB. */
    public function test_br35_slik_can_be_recorded_before_psjb(): void
    {
        $application = ConsumerApplication::factory()->create();
        $actor = $this->superadmin($application);
        $lifecycle = app(ConsumerApplicationLifecycleService::class);

        $slik = $lifecycle->recordBiChecking($application, [
            'tanggal_slik' => '2026-10-01',
            'hasil_slik' => 'Lolos',
        ], $actor);

        $this->assertSame('bi_checking', $slik->stage);
        $this->assertSame('slik', $application->fresh()->current_process);
        $this->assertSame(0, $application->fresh()->stageEvents()->where('stage', 'PSJB')->count());
    }

    public function test_cash_marks_bank_and_sp3k_not_applicable_and_rejects_bank_write(): void
    {
        $application = ConsumerApplication::factory()->create();
        $actor = $this->superadmin($application);
        $created = app(ConsumerEntryService::class)->create([
            'name' => 'Cash Buyer', 'phone' => '08123456781', 'branch_id' => $application->branch_id,
            'project_id' => $application->project_id, 'payment_method' => 'cash',
        ], $actor);

        $this->assertDatabaseHas('consumer_process_applicabilities', ['consumer_application_id' => $created->id, 'process_key' => 'proses_bank', 'applicability' => 'not_applicable']);
        $this->assertDatabaseHas('consumer_process_applicabilities', ['consumer_application_id' => $created->id, 'process_key' => 'bi_checking', 'applicability' => 'not_applicable']);
        $this->assertDatabaseHas('consumer_process_applicabilities', ['consumer_application_id' => $created->id, 'process_key' => 'pemberkasan', 'applicability' => 'not_applicable']);
        $this->assertDatabaseHas('consumer_process_applicabilities', ['consumer_application_id' => $created->id, 'process_key' => 'sp3k', 'applicability' => 'not_applicable']);
        $this->expectException(\DomainException::class);
        app(ConsumerOperationalService::class)->recordProsesBank($created, ['bank_name' => 'BTN'], $actor);
    }

    public function test_warranty_after_bast_keeps_legacy_selesai_status_when_transaction_events_flag_is_off(): void
    {
        AppSetting::query()->updateOrCreate(['key' => 'transaction_events_v3'], ['value' => false, 'group' => 'feature_flags']);
        app(AppSettingResolver::class)->forget('transaction_events_v3');
        $application = ConsumerApplication::factory()->create();
        $actor = $this->superadmin($application);
        $operational = app(ConsumerOperationalService::class);
        $lifecycle = app(ConsumerApplicationLifecycleService::class);
        $operational->recordAkad($application, ['tanggal_akad' => '2026-10-01'], $actor, app(ConsumerKavlingLifecycleService::class));
        $operational->recordReady100($application->fresh(), ['ready_100_at' => '2026-10-02'], $actor);
        $lifecycle->recordBast($application->fresh(), ['tanggal_bast' => '2026-10-03'], $actor);
        app(ConsumerProcessService::class)->recordWarranty($application->fresh(), ['status_komplain' => 'Tidak Ada Komplain', 'status_garansi' => 'Tidak Ada Komplain'], $actor);

        $this->assertSame('SELESAI', $application->fresh()->transaction_status);
        $this->assertSame(1, app(ConsumerProcessService::class)->completedQuery($actor)->whereKey($application->id)->count());
    }

    public function test_warranty_after_bast_can_complete_transaction_and_appears_in_selesai(): void
    {
        AppSetting::query()->updateOrCreate(['key' => 'transaction_events_v3'], ['value' => true, 'group' => 'feature_flags']);
        app(AppSettingResolver::class)->forget('transaction_events_v3');
        $application = ConsumerApplication::factory()->create();
        $actor = $this->superadmin($application);
        $operational = app(ConsumerOperationalService::class);
        $lifecycle = app(ConsumerApplicationLifecycleService::class);
        $operational->recordAkad($application, ['tanggal_akad' => '2026-10-01'], $actor, app(ConsumerKavlingLifecycleService::class));
        $operational->recordReady100($application->fresh(), ['ready_100_at' => '2026-10-02'], $actor);
        $lifecycle->recordBast($application->fresh(), ['tanggal_bast' => '2026-10-03'], $actor);
        app(ConsumerProcessService::class)->recordWarranty($application->fresh(), ['status_komplain' => 'Tidak Ada Komplain', 'status_garansi' => 'Tidak Ada Komplain'], $actor);

        $this->assertDatabaseHas('consumer_applications', ['id' => $application->id, 'transaction_status' => 'LANJUT', 'current_process' => 'selesai']);
        $this->assertSame(1, app(ConsumerProcessService::class)->completedQuery($actor)->whereKey($application->id)->count());
    }

    private function superadmin(ConsumerApplication $application): User
    {
        return User::factory()->create(['role_id' => Role::query()->where('slug', 'superadmin')->value('id'), 'branch_id' => $application->branch_id, 'password_changed_at' => now()]);
    }
}
