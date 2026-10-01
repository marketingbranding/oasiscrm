<?php

namespace Tests\Feature;

use App\Models\ConsumerApplication;
use App\Models\ConsumerBastRecord;
use App\Models\Kavling;
use App\Models\User;
use App\Services\ConsumerKavlingLifecycleService;
use App\Services\ConsumerOperationalService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class ConsumerCanonicalDomainContractTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_native_bank_process_reuses_an_attempt_and_new_pemberkasan_starts_another(): void
    {
        $application = ConsumerApplication::factory()->create();
        $actor = User::factory()->create(['branch_id' => $application->branch_id]);
        $service = app(ConsumerOperationalService::class);

        $first = $service->recordPemberkasan($application, ['tanggal_terima_bank' => '2026-10-01', 'bank_name' => 'BTN'], $actor);
        $progressed = $service->recordProsesBank($application->fresh(), ['no_sp3k' => 'SP3K-1', 'status' => 'approved'], $actor);
        $second = $service->recordPemberkasan($application->fresh(), ['tanggal_terima_bank' => '2026-10-02', 'bank_name' => 'BSN'], $actor);

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
        $actor = User::factory()->create(['branch_id' => $application->branch_id]);
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
        $actor = User::factory()->create(['branch_id' => $application->branch_id]);

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
}
