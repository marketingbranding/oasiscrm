<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\AppSetting;
use App\Models\ConsumerApplication;
use App\Models\ConsumerBastRecord;
use App\Models\ConsumerKavlingAssignment;
use App\Models\Customer;
use App\Models\Kavling;
use App\Models\Role;
use App\Models\User;
use App\Services\AppSettingResolver;
use App\Services\ConsumerApplicationLifecycleService;
use App\Services\ConsumerKavlingLifecycleService;
use App\Services\ConsumerOperationalService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConsumerLifecycleOrchestrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        AppSetting::query()->updateOrCreate(['key' => 'transaction_events_v3'], ['value' => true, 'group' => 'feature_flags']);
        app(AppSettingResolver::class)->forget('transaction_events_v3');
    }

    public function test_phase_2_changelog_is_present_and_visible_to_superadmin(): void
    {
        $actor = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'superadmin')->value('id'),
            'password_changed_at' => now(),
        ]);

        $this->assertSame(1, \DB::table('changelogs')->whereNull('version')->where('title', 'Orkestrasi lifecycle konsumen dan guard BAST')->count());

        $this->actingAs($actor)
            ->get(route('changelogs.index'))
            ->assertOk()
            ->assertSeeText('Orkestrasi lifecycle konsumen dan guard BAST');
    }

    public function test_bast_requires_akad_and_ready100(): void
    {
        [$application, $actor] = $this->applicationAndActor();
        $service = app(ConsumerOperationalService::class);

        $this->assertFalse($service->bastReadiness($application)['ready']);

        $service->recordReady100($application, ['ready_100_at' => '2026-10-05'], $actor);
        $this->assertFalse($service->bastReadiness($application->fresh())['ready']);

        $service->recordAkad($application->fresh(), ['tanggal_akad' => '2026-10-04'], $actor, app(ConsumerKavlingLifecycleService::class));
        $readiness = $service->bastReadiness($application->fresh());

        $this->assertTrue($readiness['ready']);
        $this->assertSame('2026-10-05', $readiness['sla_anchor']);
    }

    public function test_bast_sla_anchor_uses_the_later_date_and_terminal_transactions_stop_active_aging(): void
    {
        [$application, $actor] = $this->applicationAndActor();
        $service = app(ConsumerOperationalService::class);
        $service->recordReady100($application, ['ready_100_at' => '2026-10-08'], $actor);
        $service->recordAkad($application->fresh(), ['tanggal_akad' => '2026-10-10'], $actor, app(ConsumerKavlingLifecycleService::class));

        $this->assertSame('2026-10-10', $service->bastReadiness($application->fresh())['sla_anchor']);

        $application->update(['consumer_status' => 'Mundur']);
        $readiness = $service->bastReadiness($application->fresh());

        $this->assertTrue($readiness['ready']);
        $this->assertFalse($readiness['sla_active']);
        $this->assertNull($readiness['sla_anchor']);
    }

    public function test_bast_write_is_blocked_without_readiness(): void
    {
        [$application, $actor] = $this->applicationAndActor();
        $service = app(ConsumerOperationalService::class);

        try {
            $service->recordBast($application, ['tanggal_bast' => '2026-10-11'], $actor, app(ConsumerKavlingLifecycleService::class));
            $this->fail('BAST tanpa Akad dan Ready100 seharusnya ditolak.');
        } catch (DomainException $exception) {
            $this->assertSame('BAST hanya dapat dibuat setelah Akad dan Ready100 tersedia.', $exception->getMessage());
        }

        $this->assertSame(0, ConsumerBastRecord::query()->where('consumer_application_id', $application->id)->count());
    }

    public function test_pemberkasan_correction_reuses_attempt_and_ganti_bank_preserves_history(): void
    {
        [$application, $actor] = $this->applicationAndActor();
        $service = app(ConsumerOperationalService::class);

        $first = $service->recordPemberkasan($application, ['tanggal_terima_bank' => '2026-10-01', 'bank_name' => 'BTN'], $actor);
        $correction = $service->recordPemberkasan($application->fresh(), ['tanggal_terima_bank' => '2026-10-02', 'bank_name' => 'BTN', 'id_berkas' => 'BERKAS-1'], $actor);
        $second = $service->gantiBank($application->fresh(), ['tanggal_terima_bank' => '2026-10-03', 'bank_name' => 'BSN'], $actor);
        $current = $service->recordProsesBank($application->fresh(), ['status' => 'approved', 'no_sp3k' => 'SP3K-2'], $actor);

        $this->assertSame($first->id, $correction->id);
        $this->assertSame(1, $correction->attempt_no);
        $this->assertSame(2, $second->attempt_no);
        $this->assertSame($second->id, $current->id);
        $this->assertSame(2, $application->fresh()->bankProcesses()->count());
        $this->assertSame('BTN', $first->fresh()->bank_name);
        $this->assertSame('BERKAS-1', $first->fresh()->id_berkas);
        $this->assertSame('SP3K-2', $second->fresh()->no_sp3k);
    }

    public function test_ganti_bank_with_same_attempt_key_is_idempotent(): void
    {
        [$application, $actor] = $this->applicationAndActor();
        $service = app(ConsumerOperationalService::class);
        $data = ['attempt_key' => 'REQUEST-BANK-2', 'bank_name' => 'BSN'];

        $first = $service->gantiBank($application, $data, $actor);
        $retry = $service->gantiBank($application->fresh(), $data, $actor);

        $this->assertSame($first->id, $retry->id);
        $this->assertSame(1, $application->fresh()->bankProcesses()->count());
        $this->assertSame(1, $application->fresh()->stageEvents()->where('stage', 'pemberkasan')->count());
    }

    public function test_proses_bank_does_not_create_an_attempt_when_the_current_attempt_is_closed(): void
    {
        [$application, $actor] = $this->applicationAndActor();
        $service = app(ConsumerOperationalService::class);
        $service->recordPemberkasan($application, ['bank_name' => 'BTN', 'status' => 'rejected'], $actor);

        $this->expectException(DomainException::class);
        $service->recordProsesBank($application->fresh(), ['status' => 'approved'], $actor);
    }

    public function test_transaction_events_v3_off_keeps_mundur_legacy_status_process_kavling_and_audit_behavior(): void
    {
        [$application, $actor] = $this->applicationAndActor('superadmin');
        $application->update(['current_stage' => 'pemberkasan', 'current_process' => 'pemberkasan']);
        $kavling = Kavling::create(['project_id' => $application->project_id, 'kavling_code' => 'OFF-01', 'name' => 'OFF-01']);
        app(ConsumerKavlingLifecycleService::class)->assign($application, $kavling);
        $this->disableTransactionEventsV3();

        $result = app(ConsumerApplicationLifecycleService::class)->mundur($application->fresh(), 'Mundur atas permintaan konsumen', $actor);

        $this->assertSame('Mundur', $result->consumer_status);
        $this->assertSame('MUNDUR', $result->transaction_status);
        $this->assertNull($result->kavling_id);
        $this->assertSame('pemberkasan', $result->current_stage);
        $this->assertSame('pemberkasan', $result->current_process);
        $this->assertSame(0, $result->transactionEvents()->count());
        $this->assertSame(1, ActivityLog::query()->where('event', 'consumer_mundur')->where('subject_id', $result->id)->count());
    }

    public function test_transaction_events_v3_off_keeps_pindah_kavling_legacy_status_process_kavling_and_audit_behavior(): void
    {
        [$application, $actor] = $this->applicationAndActor('superadmin');
        $application->update(['current_stage' => 'pemberkasan', 'current_process' => 'pemberkasan']);
        $oldKavling = Kavling::create(['project_id' => $application->project_id, 'kavling_code' => 'OFF-02', 'name' => 'OFF-02']);
        $targetKavling = Kavling::create(['project_id' => $application->project_id, 'kavling_code' => 'OFF-03', 'name' => 'OFF-03']);
        app(ConsumerKavlingLifecycleService::class)->assign($application, $oldKavling);
        $this->disableTransactionEventsV3();

        $result = app(ConsumerApplicationLifecycleService::class)->pindahKavling($application->fresh(), $targetKavling, $actor);

        $this->assertSame('Pindah Kavling', $result->consumer_status);
        $this->assertSame('LANJUT', $result->transaction_status);
        $this->assertSame($targetKavling->id, $result->kavling_id);
        $this->assertSame('pemberkasan', $result->current_stage);
        $this->assertSame('pemberkasan', $result->current_process);
        $this->assertSame(0, $result->transactionEvents()->count());
        $this->assertSame(1, ActivityLog::query()->where('event', 'consumer_kavling_moved')->where('subject_id', $result->id)->count());
    }

    /** BR-33: Ganti Nama closes old transaction and records replacement history. */
    public function test_transaction_events_v3_off_keeps_ganti_nama_legacy_status_process_kavling_and_audit_behavior(): void
    {
        [$application, $actor] = $this->applicationAndActor('superadmin');
        $application->update(['current_stage' => 'pemberkasan', 'current_process' => 'pemberkasan']);
        $oldKavling = Kavling::create(['project_id' => $application->project_id, 'kavling_code' => 'OFF-04', 'name' => 'OFF-04']);
        app(ConsumerKavlingLifecycleService::class)->assign($application, $oldKavling);
        $replacementCustomer = Customer::factory()->create(['name' => 'Konsumen Pengganti Flag Off']);
        $this->disableTransactionEventsV3();

        $replacement = app(ConsumerApplicationLifecycleService::class)->gantiKonsumen($application->fresh(), $replacementCustomer, $actor);
        $old = $application->fresh();

        $this->assertSame('REPLACED', $old->application_status);
        $this->assertSame('DITUTUP', $old->transaction_status);
        $this->assertNull($old->kavling_id);
        $this->assertSame('pemberkasan', $old->current_stage);
        $this->assertSame('pemberkasan', $old->current_process);
        $this->assertSame($oldKavling->id, $replacement->kavling_id);
        $this->assertSame(0, $old->transactionEvents()->count());
        $this->assertSame(1, ActivityLog::query()->where('event', 'consumer_ganti_konsumen')->where('subject_id', $old->id)->count());
    }

    /** BR-33: Ganti Nama closes old transaction and records replacement history. */
    public function test_br33_ganti_konsumen_creates_ganti_nama_closes_old_transaction_and_links_replacement(): void
    {
        [$application, $actor] = $this->applicationAndActor('superadmin');
        $oldCustomer = $application->customer;
        $replacementCustomer = Customer::factory()->create(['name' => 'Konsumen Pengganti']);
        $kavling = Kavling::create(['project_id' => $application->project_id, 'kavling_code' => 'A-20', 'name' => 'A-20']);
        app(ConsumerKavlingLifecycleService::class)->assign($application, $kavling);
        $service = app(ConsumerApplicationLifecycleService::class);

        $replacement = $service->gantiKonsumen($application, $replacementCustomer, $actor);
        $old = $application->fresh();

        $this->assertNotSame($old->id_transaksi, $replacement->id_transaksi);
        $this->assertSame('REPLACED', $old->application_status);
        $this->assertSame($replacement->id, $old->replacement_application_id);
        $this->assertSame($oldCustomer->id, $old->customer_id);
        $this->assertSame($replacementCustomer->id, $replacement->customer_id);
        $this->assertSame($kavling->id, $replacement->kavling_id);
        $this->assertSame('released', ConsumerKavlingAssignment::query()->where('consumer_application_id', $old->id)->sole()->assignment_status);
        $this->assertSame('active', ConsumerKavlingAssignment::query()->where('consumer_application_id', $replacement->id)->sole()->assignment_status);
        $this->assertDatabaseHas('activity_log', ['event' => 'consumer_ganti_konsumen', 'subject_id' => $old->id, 'causer_id' => $actor->id]);
        $this->assertSame('ganti_konsumen', ActivityLog::query()->where('event', 'consumer_ganti_konsumen')->sole()->properties['action']);
        $event = $old->stageEvents()->where('event_type', 'GANTI_NAMA')->sole();
        $this->assertSame('transaction_event', $event->stage);
        $this->assertSame($replacement->id, $event->metadata['replacement_application_id']);
        $this->assertSame('DITUTUP', $old->transaction_status);
        $this->assertSame($application->id_transaksi, $old->id_transaksi);
        $this->assertNotSame($old->id_transaksi, $replacement->id_transaksi);
    }

    /** BR-34: Pindah Kavling through lifecycle writes typed event and audit. */
    public function test_br34_pindah_kavling_through_lifecycle_creates_typed_event_and_audit(): void
    {
        [$application, $actor] = $this->applicationAndActor('superadmin');
        $lifecycle = app(ConsumerKavlingLifecycleService::class);
        $oldKavling = Kavling::create(['project_id' => $application->project_id, 'kavling_code' => 'A-21', 'name' => 'A-21']);
        $targetKavling = Kavling::create(['project_id' => $application->project_id, 'kavling_code' => 'A-22', 'name' => 'A-22']);
        $lifecycle->assign($application, $oldKavling);

        app(ConsumerApplicationLifecycleService::class)->pindahKavling($application->fresh(), $targetKavling, $actor);

        $current = $application->fresh();
        $event = $current->stageEvents()->where('event_type', 'PINDAH_KAVLING')->sole();
        $this->assertSame('transaction_event', $event->stage);
        $this->assertSame($targetKavling->id, $event->metadata['kavling_id']);
        $this->assertSame($targetKavling->id, $current->kavling_id);
        $this->assertDatabaseHas('activity_log', ['event' => 'consumer_kavling_moved', 'subject_id' => $current->id, 'causer_id' => $actor->id]);
    }

    /** BR-34: Pindah Kavling preserves assignment on occupied target failure and writes no event. */
    public function test_br34_pindah_kavling_occupied_target_keeps_old_kavling_and_writes_no_event(): void
    {
        [$application, $actor] = $this->applicationAndActor('superadmin');
        $lifecycle = app(ConsumerKavlingLifecycleService::class);
        $oldKavling = Kavling::create(['project_id' => $application->project_id, 'kavling_code' => 'A-21', 'name' => 'A-21']);
        $targetKavling = Kavling::create(['project_id' => $application->project_id, 'kavling_code' => 'A-22', 'name' => 'A-22']);
        $lifecycle->assign($application, $oldKavling);
        $occupiedApplication = ConsumerApplication::factory()->create(['project_id' => $application->project_id]);
        $lifecycle->assign($occupiedApplication, $targetKavling);

        try {
            app(ConsumerApplicationLifecycleService::class)->pindahKavling($application->fresh(), $targetKavling, $actor);
            $this->fail('Kavling tujuan terisi seharusnya ditolak.');
        } catch (DomainException $exception) {
            $this->assertSame('Kavling tujuan sudah memiliki assignment aktif.', $exception->getMessage());
        }

        $current = $application->fresh();
        $this->assertSame($oldKavling->id, $current->kavling_id);
        $this->assertSame('active', ConsumerKavlingAssignment::query()
            ->where('consumer_application_id', $application->id)
            ->where('kavling_id', $oldKavling->id)
            ->sole()
            ->assignment_status);
        $this->assertSame(0, $current->stageEvents()->where('event_type', 'PINDAH_KAVLING')->count());
    }

    public function test_ganti_konsumen_retry_returns_existing_replacement_without_creating_another_application(): void
    {
        [$application, $actor] = $this->applicationAndActor('superadmin');
        $replacementCustomer = Customer::factory()->create();
        $service = app(ConsumerApplicationLifecycleService::class);

        $first = $service->gantiKonsumen($application, $replacementCustomer, $actor);
        $retry = $service->gantiKonsumen($application->fresh(), $replacementCustomer, $actor);

        $this->assertSame($first->id, $retry->id);
        $this->assertSame(2, ConsumerApplication::query()->where('branch_id', $application->branch_id)->count());
        $this->assertSame(1, ActivityLog::query()->where('event', 'consumer_ganti_konsumen')->count());
    }

    public function test_lifecycle_action_is_denied_outside_branch_scope(): void
    {
        [$application] = $this->applicationAndActor('superadmin');
        $actor = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'manager')->value('id'),
            'branch_id' => null,
        ]);
        $replacementCustomer = Customer::factory()->create();

        $this->expectException(AuthorizationException::class);
        app(ConsumerApplicationLifecycleService::class)->gantiKonsumen($application, $replacementCustomer, $actor);
    }

    /** @return array{0: ConsumerApplication, 1: User} */
    private function disableTransactionEventsV3(): void
    {
        AppSetting::query()->updateOrCreate(['key' => 'transaction_events_v3'], ['value' => false, 'group' => 'feature_flags']);
        app(AppSettingResolver::class)->forget('transaction_events_v3');
    }

    private function applicationAndActor(string $role = 'superadmin'): array
    {
        $application = ConsumerApplication::factory()->create();
        $actor = User::factory()->create([
            'role_id' => Role::query()->where('slug', $role)->value('id'),
            'branch_id' => $application->branch_id,
        ]);

        return [$application, $actor];
    }
}
