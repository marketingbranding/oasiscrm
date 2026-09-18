<?php

namespace Tests\Feature;

use App\Models\ConsumerAkadRecord;
use App\Models\ConsumerApplication;
use App\Models\ConsumerBastRecord;
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

    public function test_imported_bi_can_resume_with_psjb_without_duplicate_history(): void
    {
        [$application, , $actor] = $this->imported('bi_checking');
        $service = app(ConsumerOperationalService::class);
        $service->recordPsjb($application, ['tanggal_psjb' => '2026-09-01', 'cara_pembayaran' => 'KPR'], $actor);
        $this->assertSame(1, $application->stageEvents()->where('stage', 'bi_checking')->count());
        $this->assertSame(1, $application->stageEvents()->where('stage', 'PSJB')->where('source', 'manual')->count());
    }

    public function test_imported_psjb_can_resume_with_pemberkasan(): void
    {
        [$application, , $actor] = $this->imported('PSJB');
        $service = app(ConsumerOperationalService::class);
        $service->recordPemberkasan($application, ['tanggal_terima_bank' => '2026-09-02', 'bank_name' => 'BTN'], $actor);
        $this->assertSame(1, $application->stageEvents()->where('stage', 'PSJB')->count());
        $this->assertSame('pemberkasan', $application->fresh()->current_stage);
    }

    public function test_imported_pemberkasan_can_resume_with_proses_bank(): void
    {
        [$application, , $actor] = $this->imported('pemberkasan');
        $service = app(ConsumerOperationalService::class);
        $service->recordProsesBank($application, ['no_sp3k' => 'SP3K-NEXT', 'response_type' => 'approved'], $actor);
        $this->assertSame(1, $application->stageEvents()->where('stage', 'pemberkasan')->count());
        $this->assertSame('proses_bank', $application->fresh()->current_stage);
    }

    public function test_imported_multiple_bank_attempts_can_resume_with_ppjb(): void
    {
        [$application, , $actor] = $this->imported('proses_bank');
        $application->bankProcesses()->createMany([
            ['source' => 'marison_v2', 'source_id' => 'ATT-1', 'id_berkas' => 'B-1', 'no_sp3k' => 'SP-1'],
            ['source' => 'marison_v2', 'source_id' => 'ATT-2', 'id_berkas' => 'B-2', 'no_sp3k' => null],
        ]);
        app(ConsumerOperationalService::class)->recordPpjb($application, ['tanggal_ttd_ppjb' => '2026-09-03'], $actor);
        $this->assertSame(2, $application->fresh()->bankProcesses()->count());
        $this->assertSame('ppjb_dev', $application->fresh()->current_stage);
    }

    public function test_imported_proses_bank_can_resume_with_ppjb(): void
    {
        [$application, , $actor] = $this->imported('proses_bank');
        $service = app(ConsumerOperationalService::class);
        $service->recordPpjb($application, ['tanggal_ttd_ppjb' => '2026-09-03'], $actor);
        $this->assertSame(1, $application->stageEvents()->where('stage', 'proses_bank')->count());
        $this->assertSame('ppjb_dev', $application->fresh()->current_stage);
    }

    public function test_imported_ppjb_with_canonical_sp3k_can_resume_with_akad(): void
    {
        [$application, $kavling, $actor] = $this->imported('ppjb_dev');
        app(ConsumerKavlingLifecycleService::class)->assign($application, $kavling);
        $service = app(ConsumerOperationalService::class);
        $service->recordAkad($application, ['tanggal_akad' => '2026-09-04'], $actor, app(ConsumerKavlingLifecycleService::class));
        $this->assertSame(1, $application->stageEvents()->where('stage', 'ppjb_dev')->count());
        $this->assertSame('akad', $application->fresh()->current_stage);
    }

    public function test_imported_akad_can_resume_with_bast_and_bast_application_stays_readable(): void
    {
        [$application, $kavling, $actor] = $this->imported('akad');
        app(ConsumerKavlingLifecycleService::class)->assign($application, $kavling);
        $service = app(ConsumerOperationalService::class);
        $service->recordBast($application, ['tanggal_bast' => '2026-09-05'], $actor, app(ConsumerKavlingLifecycleService::class));
        $this->assertSame(1, $application->stageEvents()->where('stage', 'akad')->count());
        $this->assertSame('bast', $application->fresh()->current_stage);
        $this->assertSame('sold', $application->fresh()->kavling?->consumerAssignments()->latest('id')->first()?->assignment_status);
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

    public function test_bank_ppjb_akad_and_bast_append_events_and_keep_sold_kavling(): void
    {
        [$application, $kavling, $actor] = $this->records();
        $lifecycle = app(ConsumerKavlingLifecycleService::class);
        $lifecycle->assign($application, $kavling);
        $service = app(ConsumerOperationalService::class);
        $service->recordPemberkasan($application, ['tanggal_terima_bank' => '2026-08-01', 'bank_name' => 'Bank A', 'request_plafond' => '0'], $actor);
        $service->recordProsesBank($application->fresh(), ['no_sp3k' => 'SP3K-1', 'response_type' => 'approved', 'approved_plafond' => '0'], $actor);
        $service->recordPpjb($application->fresh(), ['tanggal_ttd_ppjb' => '2026-08-03'], $actor);
        $service->recordAkad($application->fresh(), ['tanggal_akad' => '2026-08-04'], $actor, $lifecycle);
        $service->recordBast($application->fresh(), ['tanggal_bast' => '2026-08-05'], $actor, $lifecycle);

        $fresh = $application->fresh();
        $this->assertSame('bast', $fresh->current_stage);
        $this->assertSame(5, $fresh->stageEvents()->count());
        $this->assertSame('sold', $fresh->kavling?->consumerAssignments()->latest('id')->first()?->assignment_status);
        $this->assertSame(1, ConsumerAkadRecord::where('consumer_application_id', $fresh->id)->count());
        $this->assertSame(1, ConsumerBastRecord::where('consumer_application_id', $fresh->id)->count());
    }

    private function imported(string $stage): array
    {
        [$application, $kavling, $actor] = $this->records();
        $events = ['bi_checking', 'PSJB', 'pemberkasan', 'proses_bank', 'ppjb_dev', 'akad'];
        foreach (array_slice($events, 0, array_search($stage, $events, true) + 1) as $index => $current) {
            $application->stageEvents()->create(['stage' => $current, 'source' => 'marison_v2', 'source_id' => 'IMPORTED-'.$current, 'occurred_at' => now()->subDays(6 - $index), 'status' => 'Selesai']);
        }
        $application->update(['current_stage' => $stage]);

        return [$application->fresh(), $kavling, $actor];
    }

    private function records(): array
    {
        $application = ConsumerApplication::factory()->create();
        $kavling = Kavling::create(['project_id' => $application->project_id, 'kavling_code' => 'A-11', 'name' => 'A-11']);
        $actor = User::factory()->create(['branch_id' => $application->branch_id]);

        return [$application, $kavling, $actor];
    }
}
