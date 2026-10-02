<?php

namespace Tests\Feature;

use App\Models\ConsumerApplication;
use App\Models\ConsumerNup;
use App\Models\Role;
use App\Models\User;
use App\Services\ConsumerApplicationLifecycleService;
use App\Services\ConsumerEntryService;
use App\Services\ConsumerKavlingLifecycleService;
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

    public function test_nup_conversion_is_canonical_and_idempotent(): void
    {
        $application = ConsumerApplication::factory()->create();
        $actor = $this->superadmin($application);

        $this->actingAs($actor)->postJson(route('consumer-nups.store'), [
            'nup_number' => 'NUP-9001', 'registered_at' => '2026-10-01', 'name' => 'NUP Satu', 'phone' => '08123456780',
            'branch_id' => $application->branch_id, 'project_id' => $application->project_id,
        ])->assertCreated();
        $nup = ConsumerNup::query()->where('nup_number', 'NUP-9001')->firstOrFail();

        $first = $this->actingAs($actor)->postJson(route('consumer-nups.convert', $nup), [])->assertOk();
        $second = $this->actingAs($actor)->postJson(route('consumer-nups.convert', $nup), [])->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, ConsumerApplication::query()->where('source_nup_id', $nup->id)->count());
        $this->assertDatabaseHas('consumer_nups', ['id' => $nup->id, 'status' => 'converted']);
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
