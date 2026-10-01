<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\ConsumerApplication;
use App\Models\ConsumerBastRecord;
use App\Models\ConsumerKavlingAssignment;
use App\Models\Customer;
use App\Models\Kavling;
use App\Models\Role;
use App\Models\User;
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

    public function test_ganti_konsumen_preserves_old_application_and_moves_kavling_effects(): void
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
    private function applicationAndActor(string $role = 'staff'): array
    {
        $application = ConsumerApplication::factory()->create();
        $actor = User::factory()->create([
            'role_id' => Role::query()->where('slug', $role)->value('id'),
            'branch_id' => $application->branch_id,
        ]);

        return [$application, $actor];
    }
}
