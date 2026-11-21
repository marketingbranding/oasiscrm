<?php

namespace Tests\Feature;

use App\Models\ConsumerApplication;
use App\Models\ConsumerIssue;
use App\Models\ConsumerNup;
use App\Models\Kavling;
use App\Models\Role;
use App\Models\SalesLead;
use App\Models\User;
use App\Services\ConsumerApplicationLifecycleService;
use App\Services\ConsumerEntryService;
use App\Services\ConsumerKavlingLifecycleService;
use App\Services\ConsumerNupService;
use App\Services\ConsumerOperationalService;
use App\Services\ConsumerProcessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConsumerV2ProcessTest extends TestCase
{
    use RefreshDatabase;

    public function test_historical_entry_can_start_at_akad_without_fake_history(): void
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

    public function test_psjb_can_be_recorded_before_slik_under_default_v2_policy(): void
    {
        $application = ConsumerApplication::factory()->create();
        $actor = $this->superadmin($application);

        $psjb = app(ConsumerApplicationLifecycleService::class)->recordPsjb($application, ['tanggal_psjb' => '2026-10-02', 'cara_pembayaran' => 'KPR'], $actor);

        $this->assertSame($application->id, $psjb->consumer_application_id);
        $this->assertSame('psjb', $application->fresh()->current_process);
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

    public function test_warranty_after_bast_can_complete_transaction_and_appears_in_selesai(): void
    {
        $application = ConsumerApplication::factory()->create();
        $actor = $this->superadmin($application);
        $operational = app(ConsumerOperationalService::class);
        $lifecycle = app(ConsumerApplicationLifecycleService::class);
        $operational->recordAkad($application, ['tanggal_akad' => '2026-10-01'], $actor, app(ConsumerKavlingLifecycleService::class));
        $operational->recordReady100($application->fresh(), ['ready_100_at' => '2026-10-02'], $actor);
        $lifecycle->recordBast($application->fresh(), ['tanggal_bast' => '2026-10-03'], $actor);
        app(ConsumerProcessService::class)->recordWarranty($application->fresh(), ['status_komplain' => 'Tidak Ada Komplain', 'status_garansi' => 'Tidak Ada Komplain'], $actor);

        $this->assertDatabaseHas('consumer_applications', ['id' => $application->id, 'transaction_status' => 'SELESAI', 'current_process' => 'selesai']);
        $this->assertSame(1, app(ConsumerProcessService::class)->completedQuery($actor)->whereKey($application->id)->count());
    }

    private function superadmin(ConsumerApplication $application): User
    {
        return User::factory()->create(['role_id' => Role::query()->where('slug', 'superadmin')->value('id'), 'branch_id' => $application->branch_id, 'password_changed_at' => now()]);
    }
}
