<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\ConsumerApplication;
use App\Models\ConsumerBastRecord;
use App\Models\Kavling;
use App\Models\Role;
use App\Models\User;
use App\Services\AppSettingResolver;
use App\Services\ConsumerApplicationLifecycleService;
use App\Services\ConsumerEntryService;
use App\Services\ConsumerKavlingLifecycleService;
use App\Services\ConsumerOperationalService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class ConsumerCanonicalDomainContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        AppSetting::query()->updateOrCreate(['key' => 'transaction_events_v3'], ['value' => true, 'group' => 'feature_flags']);
        app(AppSettingResolver::class)->forget('transaction_events_v3');
    }

    /** BR-30: only canonical statuses are exposed for new transaction writes. */
    public function test_br30_only_canonical_transaction_statuses_are_exposed_and_legacy_values_map_for_reads(): void
    {
        $this->assertSame(['LANJUT', 'MUNDUR', 'DITUTUP'], ConsumerApplication::TRANSACTION_STATUSES);
        $this->assertSame('DITUTUP', ConsumerApplication::factory()->create(['transaction_status' => 'REPLACED'])->canonicalTransactionStatus());
        $this->assertSame('MUNDUR', ConsumerApplication::factory()->create(['transaction_status' => 'Reject'])->canonicalTransactionStatus());
        $this->assertSame('LANJUT', ConsumerApplication::factory()->create(['transaction_status' => 'Pindah Kavling'])->canonicalTransactionStatus());
        $this->assertSame('MUNDUR', ConsumerApplication::factory()->create(['transaction_status' => 'REJECT SLIK'])->canonicalTransactionStatus());
        $this->assertSame('MUNDUR', ConsumerApplication::factory()->create(['transaction_status' => 'REJECT BANK'])->canonicalTransactionStatus());
        $this->assertSame('DITUTUP', ConsumerApplication::factory()->create(['transaction_status' => 'GANTI NAMA'])->canonicalTransactionStatus());
        $this->assertSame('LANJUT', ConsumerApplication::factory()->create(['transaction_status' => 'SELESAI'])->canonicalTransactionStatus());
        $unknown = ConsumerApplication::factory()->create(['transaction_status' => 'STATUS ACAK']);
        $this->assertSame('LANJUT', $unknown->canonicalTransactionStatus());
        $this->assertTrue($unknown->canonicalTransactionStatusNeedsReview());
    }

    /** BR-30: manual DITUTUP is rejected for new entry writes. */
    public function test_br30_new_entry_rejects_ditutup(): void
    {
        $application = ConsumerApplication::factory()->create();
        $actor = $this->superadmin($application);

        $this->expectException(\DomainException::class);
        app(ConsumerEntryService::class)->create([
            'name' => 'New status guard',
            'phone' => '08123456789',
            'branch_id' => $application->branch_id,
            'project_id' => $application->project_id,
            'transaction_status' => 'DITUTUP',
        ], $actor);
    }

    /** BR-30: historical entries preserve supplied legacy transaction status. */
    public function test_br30_historical_entry_preserves_legacy_transaction_status(): void
    {
        $application = ConsumerApplication::factory()->create();
        $actor = $this->superadmin($application);

        $historical = app(ConsumerEntryService::class)->create([
            'name' => 'Historical status',
            'phone' => '08123456780',
            'branch_id' => $application->branch_id,
            'project_id' => $application->project_id,
            'entry_mode' => 'historical',
            'transaction_status' => 'REPLACED',
        ], $actor, true);

        $this->assertSame('REPLACED', $historical->fresh()->transaction_status);
    }

    /** BR-31: default withdrawal reasons are configured and available to validation. */
    public function test_br31_default_mundur_reasons_are_configured(): void
    {
        $this->assertSame([
            'Mundur atas permintaan konsumen',
            'Reject SLIK',
            'Reject Bank',
            'Lainnya',
        ], app(AppSettingResolver::class)->list('consumer_progress.mundur_reasons'));
    }

    /** BR-30 and BR-32: Mundur closes no process stage and records only typed event history. */
    public function test_br30_mundur_sets_canonical_status_only_through_typed_event_without_process_change(): void
    {
        $application = ConsumerApplication::factory()->create([
            'current_stage' => 'pemberkasan',
            'current_process' => 'pemberkasan',
        ]);
        $actor = $this->superadmin($application);

        $result = app(ConsumerApplicationLifecycleService::class)->mundur($application, 'Reject Bank', $actor);

        $this->assertSame('MUNDUR', $result->transaction_status);
        $this->assertSame('pemberkasan', $result->current_stage);
        $this->assertSame('pemberkasan', $result->current_process);
        $event = $result->transactionEvents()->sole();
        $this->assertSame('MUNDUR', $event->event_type);
        $this->assertSame('Reject Bank', $event->reason);
        $this->assertSame(0, $result->processStageEvents()->where('event_type', 'MUNDUR')->count());
    }

    /** BR-31: configured withdrawal reasons drive Mundur validation and rollback. */
    public function test_br31_mundur_uses_custom_reason_and_invalid_reason_rolls_back_held_kavling(): void
    {
        $application = ConsumerApplication::factory()->create();
        $actor = $this->superadmin($application);
        $service = app(ConsumerApplicationLifecycleService::class);
        AppSetting::query()->create(['key' => 'consumer_progress.mundur_reasons', 'value' => ['Custom reason'], 'group' => 'consumer_progress']);
        app(AppSettingResolver::class)->forget('consumer_progress.mundur_reasons');

        $service->mundur($application, 'Custom reason', $actor);
        $this->assertSame('Custom reason', $application->fresh()->withdraw_reason);

        $held = ConsumerApplication::factory()->create(['branch_id' => $application->branch_id, 'project_id' => $application->project_id]);
        $heldKavling = Kavling::create(['project_id' => $held->project_id, 'kavling_code' => 'ROUND3-01', 'name' => 'ROUND3-01']);
        app(ConsumerKavlingLifecycleService::class)->assign($held, $heldKavling);
        $before = $held->fresh();

        $this->expectException(ValidationException::class);
        $service->mundur($held, 'Invalid reason', $actor);

        $this->assertSame('LANJUT', $held->fresh()->transaction_status);
        $this->assertSame($before->kavling_id, $held->fresh()->kavling_id);
        $this->assertSame('active', $held->fresh()->kavling?->consumerAssignments()->latest('id')->first()?->assignment_status);
    }

    public function test_customer_can_have_multiple_applications_with_distinct_immutable_transaction_identities(): void
    {
        $first = ConsumerApplication::factory()->create();
        $second = ConsumerApplication::factory()->create([
            'customer_id' => $first->customer_id,
            'branch_id' => $first->branch_id,
            'project_id' => $first->project_id,
        ]);

        $this->assertCount(2, $first->customer->applications);
        $this->assertNotSame($first->id_transaksi, $second->id_transaksi);
        $this->assertStringStartsWith('TRX-', $first->id_transaksi);

        $this->expectException(LogicException::class);
        $first->id_transaksi = 'TRX-MUTATED';
        $first->save();
    }

    public function test_transaction_identity_is_unique_at_database_level(): void
    {
        $application = ConsumerApplication::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('consumer_applications')->insert([
            'branch_id' => $application->branch_id,
            'project_id' => $application->project_id,
            'application_status' => 'draft',
            'id_transaksi' => $application->id_transaksi,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_pindah_kavling_does_not_change_transaction_identity(): void
    {
        $application = ConsumerApplication::factory()->create();
        $oldKavling = Kavling::create(['project_id' => $application->project_id, 'kavling_code' => 'A-01', 'name' => 'A-01']);
        $newKavling = Kavling::create(['project_id' => $application->project_id, 'kavling_code' => 'A-02', 'name' => 'A-02']);
        $identity = $application->id_transaksi;
        $lifecycle = app(ConsumerKavlingLifecycleService::class);

        $lifecycle->assign($application, $oldKavling);
        $lifecycle->pindahKavling($application, $newKavling);

        $this->assertSame($identity, $application->fresh()->id_transaksi);
        $this->assertSame($newKavling->id, $application->fresh()->kavling_id);
    }

    public function test_native_bank_process_reuses_an_attempt_and_ganti_bank_starts_another(): void
    {
        $application = ConsumerApplication::factory()->create();
        $actor = $this->superadmin($application);
        $service = app(ConsumerOperationalService::class);

        $first = $service->recordPemberkasan($application, ['tanggal_terima_bank' => '2026-10-01', 'bank_name' => 'BTN'], $actor);
        $progressed = $service->recordProsesBank($application->fresh(), ['no_sp3k' => 'SP3K-1', 'status' => 'approved'], $actor);
        $second = $service->gantiBank($application->fresh(), ['tanggal_terima_bank' => '2026-10-02', 'bank_name' => 'BSN'], $actor);

        $this->assertSame($first->id, $progressed->id);
        $this->assertSame(1, $first->attempt_no);
        $this->assertSame(2, $second->attempt_no);
        $this->assertSame(2, $application->fresh()->bankProcesses()->count());
        $this->assertSame('SP3K-1', $first->fresh()->no_sp3k);
        $this->assertSame('BSN', $second->fresh()->bank_name);
    }

    public function test_slik_retries_are_append_only_and_keep_decision_actor_and_source(): void
    {
        $application = ConsumerApplication::factory()->create();
        $actor = $this->superadmin($application);
        $service = app(ConsumerOperationalService::class);

        $service->recordBiChecking($application, [
            'tanggal_slik' => '2026-10-01',
            'hasil_slik' => 'Lolos',
            'keputusan' => 'Lanjut',
            'keterangan' => 'Pemeriksaan pertama',
        ], $actor);
        $service->recordBiChecking($application, [
            'tanggal_slik' => '2026-10-02',
            'hasil_slik' => 'Ulang',
            'keputusan' => 'Review',
        ], $actor);

        $events = $application->fresh()->stageEvents()->where('stage', 'bi_checking')->orderBy('id')->get();
        $this->assertCount(2, $events);
        $this->assertSame('Lanjut', $events[0]->decision);
        $this->assertSame($actor->id, $events[0]->actor_id);
        $this->assertSame('manual', $events[0]->source);
        $this->assertSame('Ulang', $events[1]->status);
    }

    public function test_ready100_is_a_history_fact_without_creating_bast(): void
    {
        $application = ConsumerApplication::factory()->create();
        $actor = $this->superadmin($application);

        $event = app(ConsumerOperationalService::class)->recordReady100($application, [
            'ready_100_at' => '2026-10-03',
            'source' => 'manual',
            'notes' => 'Bangunan siap serah.',
        ], $actor);

        $this->assertSame('ready_100', $event->stage);
        $this->assertTrue($application->fresh()->hasReady100());
        $this->assertSame('2026-10-03', $event->event_date->toDateString());
        $this->assertSame(0, ConsumerBastRecord::query()->where('consumer_application_id', $application->id)->count());
        $this->assertDatabaseHas('consumer_stage_events', ['id' => $event->id, 'source' => 'manual']);
    }

    private function superadmin(ConsumerApplication $application): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', 'superadmin')->value('id'),
            'branch_id' => $application->branch_id,
            'password_changed_at' => now(),
        ]);
    }
}
