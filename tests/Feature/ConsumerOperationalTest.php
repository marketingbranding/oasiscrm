<?php

namespace Tests\Feature;

use App\Models\ConsumerApplication;
use App\Models\ConsumerKavlingAssignment;
use App\Models\ConsumerPsjb;
use App\Models\Kavling;
use App\Models\User;
use App\Services\ConsumerKavlingLifecycleService;
use App\Services\ConsumerOperationalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConsumerOperationalTest extends TestCase
{
    use RefreshDatabase;

    public function test_bi_checking_and_psjb_are_manual_append_only_records_with_canonical_ids(): void
    {
        [$application, $kavling, $actor] = $this->records();
        $service = app(ConsumerOperationalService::class);
        $service->recordBiChecking($application, ['tanggal_slik' => '2026-08-01', 'hasil_slik' => 'OK', 'keterangan' => 'Lancar'], $actor);
        $service->recordBiChecking($application, ['tanggal_slik' => '2026-08-01', 'hasil_slik' => 'KOL 1', 'keterangan' => 'Ulang'], $actor);

        $this->assertSame(2, $application->stageEvents()->where('stage', 'bi_checking')->count());
        $this->assertStringStartsWith('260801-OK-', $application->stageEvents()->where('stage', 'bi_checking')->first()->source_id);
        $psjb = $service->recordPsjb($application, ['tanggal_psjb' => '2026-08-02', 'cara_pembayaran' => 'Tunai', 'harga_unit' => '0', 'utj' => '0'], $actor);

        $this->assertInstanceOf(ConsumerPsjb::class, $psjb);
        $this->assertSame($application->stageEvents()->where('stage', 'bi_checking')->latest('id')->first()->source_id, $psjb->id_kons);
        $this->assertSame('0.00', $psjb->harga_unit);
        $this->assertSame('PSJB', $application->fresh()->current_stage);
        $this->assertDatabaseHas('consumer_stage_events', ['stage' => 'PSJB', 'source_id' => $psjb->id_psjb]);
    }

    public function test_completeness_and_process_last_are_computed_from_local_data(): void
    {
        [$application, , $actor] = $this->records();
        $service = app(ConsumerOperationalService::class);
        $summary = $service->completeness($application->fresh(['customer']));
        $this->assertSame('Data Belum Lengkap', $summary['status']);
        $service->recordBiChecking($application, ['tanggal_slik' => '2026-08-01', 'hasil_slik' => 'NO BIC'], $actor);
        $this->assertSame('BI Checking', $service->processLast($application->fresh(['stageEvents'])));
    }

    public function test_kavling_assignment_is_reserved_before_operational_stage_input(): void
    {
        [$application, $kavling] = $this->records();
        app(ConsumerKavlingLifecycleService::class)->assign($application, $kavling);
        $this->assertSame('active', ConsumerKavlingAssignment::sole()->assignment_status);
        $this->assertSame($kavling->id, $application->fresh()->kavling_id);
    }

    private function records(): array
    {
        $application = ConsumerApplication::factory()->create();
        $kavling = Kavling::create(['project_id' => $application->project_id, 'kavling_code' => 'A-11', 'name' => 'A-11']);
        $actor = User::factory()->create(['branch_id' => $application->branch_id]);

        return [$application, $kavling, $actor];
    }
}
