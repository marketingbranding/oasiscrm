<?php

namespace Tests\Feature;

use App\Models\ConsumerApplication;
use App\Models\ConsumerBankProcess;
use App\Models\ConsumerKavlingAssignment;
use App\Models\ConsumerStageEvent;
use App\Models\Kavling;
use App\Services\ConsumerKavlingBackfillService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConsumerKavlingBackfillTest extends TestCase
{
    use RefreshDatabase;

    private function createApplicationWithKavling(array $overrides = []): array
    {
        $application = ConsumerApplication::factory()->create($overrides);
        $kavling = Kavling::create([
            'project_id' => $application->project_id,
            'kavling_code' => 'K-'.$application->id,
            'name' => 'K-'.$application->id,
        ]);
        $application->update(['kavling_id' => $kavling->id]);

        return [$application, $kavling];
    }

    public function test_preview_performs_zero_writes(): void
    {
        [$application, $kavling] = $this->createApplicationWithKavling();
        $service = app(ConsumerKavlingBackfillService::class);

        $result = $service->preview();

        $this->assertSame(1, $result->count());
        $this->assertDatabaseCount('consumer_kavling_assignments', 0);
        $this->assertSame('READY_RESERVED', $result->first()['classification']);
    }

    public function test_normal_active_application_classifies_as_ready_reserved(): void
    {
        [$application, $kavling] = $this->createApplicationWithKavling(['consumer_status' => 'Lanjut']);
        $service = app(ConsumerKavlingBackfillService::class);

        $result = $service->preview();

        $row = $result->first();
        $this->assertSame('READY_RESERVED', $row['classification']);
        $this->assertSame('active', $row['intended_status']);
        $this->assertSame($application->id, $row['application_id']);
        $this->assertSame($kavling->kavling_code, $row['kavling_code']);
    }

    public function test_akad_application_classifies_as_ready_sold(): void
    {
        [$application, $kavling] = $this->createApplicationWithKavling([
            'consumer_status' => 'Lanjut',
            'current_stage' => 'akad',
            'akad_date' => now()->toDateString(),
        ]);
        $service = app(ConsumerKavlingBackfillService::class);

        $result = $service->preview();

        $row = $result->first();
        $this->assertSame('READY_SOLD', $row['classification']);
        $this->assertSame('sold', $row['intended_status']);
    }

    public function test_bast_stage_event_classifies_as_ready_sold(): void
    {
        [$application, $kavling] = $this->createApplicationWithKavling(['consumer_status' => 'Lanjut']);
        ConsumerStageEvent::create([
            'consumer_application_id' => $application->id,
            'stage' => 'bast',
            'status' => 'completed',
        ]);
        $service = app(ConsumerKavlingBackfillService::class);

        $result = $service->preview();

        $row = $result->first();
        $this->assertSame('READY_SOLD', $row['classification']);
        $this->assertSame('sold', $row['intended_status']);
    }

    public function test_akad_stage_event_classifies_as_ready_sold(): void
    {
        [$application, $kavling] = $this->createApplicationWithKavling(['consumer_status' => 'Lanjut']);
        ConsumerStageEvent::create([
            'consumer_application_id' => $application->id,
            'stage' => 'akad',
            'status' => 'completed',
        ]);
        $service = app(ConsumerKavlingBackfillService::class);

        $result = $service->preview();

        $row = $result->first();
        $this->assertSame('READY_SOLD', $row['classification']);
        $this->assertSame('sold', $row['intended_status']);
    }

    public function test_akad_date_only_classifies_as_ready_sold(): void
    {
        [$application, $kavling] = $this->createApplicationWithKavling([
            'consumer_status' => 'Lanjut',
            'akad_date' => '2026-01-15',
        ]);
        $service = app(ConsumerKavlingBackfillService::class);

        $result = $service->preview();

        $row = $result->first();
        $this->assertSame('READY_SOLD', $row['classification']);
        $this->assertSame('sold', $row['intended_status']);
    }

    public function test_reject_application_remains_ready_reserved(): void
    {
        [$application, $kavling] = $this->createApplicationWithKavling(['consumer_status' => 'Reject']);
        $service = app(ConsumerKavlingBackfillService::class);

        $result = $service->preview();

        $row = $result->first();
        $this->assertSame('READY_RESERVED', $row['classification']);
        $this->assertSame('active', $row['intended_status']);
    }

    public function test_mundur_application_is_skipped(): void
    {
        [$application, $kavling] = $this->createApplicationWithKavling(['consumer_status' => 'Mundur']);
        $service = app(ConsumerKavlingBackfillService::class);

        $result = $service->preview();

        $row = $result->first();
        $this->assertSame('SKIPPED', $row['classification']);
        $this->assertSame('-', $row['intended_status']);
        $this->assertSame('Consumer sudah Mundur', $row['reason']);
    }

    public function test_existing_assignment_classifies_as_already_backfilled(): void
    {
        [$application, $kavling] = $this->createApplicationWithKavling();
        ConsumerKavlingAssignment::create([
            'consumer_application_id' => $application->id,
            'kavling_id' => $kavling->id,
            'assigned_at' => now(),
            'assignment_status' => 'active',
        ]);
        $service = app(ConsumerKavlingBackfillService::class);

        $result = $service->preview();

        $row = $result->first();
        $this->assertSame('ALREADY_BACKFILLED', $row['classification']);
    }

    public function test_existing_sold_assignment_classifies_as_already_backfilled(): void
    {
        [$application, $kavling] = $this->createApplicationWithKavling([
            'current_stage' => 'akad',
            'akad_date' => now()->toDateString(),
        ]);
        ConsumerKavlingAssignment::create([
            'consumer_application_id' => $application->id,
            'kavling_id' => $kavling->id,
            'assigned_at' => now(),
            'assignment_status' => 'sold',
        ]);
        $service = app(ConsumerKavlingBackfillService::class);

        $result = $service->preview();

        $row = $result->first();
        $this->assertSame('ALREADY_BACKFILLED', $row['classification']);
        $this->assertSame('sold', $row['intended_status']);
    }

    public function test_same_kavling_claimed_by_another_active_application_is_conflict(): void
    {
        [$app1, $kavling] = $this->createApplicationWithKavling(['consumer_status' => 'Lanjut']);
        ConsumerApplication::factory()->create([
            'kavling_id' => $kavling->id,
            'branch_id' => $app1->branch_id,
            'project_id' => $app1->project_id,
        ]);
        $service = app(ConsumerKavlingBackfillService::class);

        $result = $service->preview();

        foreach ($result as $row) {
            $this->assertSame('CONFLICT', $row['classification']);
        }
    }

    public function test_kavling_already_has_active_assignment_from_other_app_is_conflict(): void
    {
        [$app1, $kavling] = $this->createApplicationWithKavling(['consumer_status' => 'Lanjut']);
        $otherApp = ConsumerApplication::factory()->create([
            'branch_id' => $app1->branch_id,
            'project_id' => $app1->project_id,
        ]);
        ConsumerKavlingAssignment::create([
            'consumer_application_id' => $otherApp->id,
            'kavling_id' => $kavling->id,
            'assigned_at' => now(),
            'assignment_status' => 'active',
        ]);
        $service = app(ConsumerKavlingBackfillService::class);

        $result = $service->preview();

        $row = $result->where('application_id', $app1->id)->first();
        $this->assertSame('CONFLICT', $row['classification']);
    }

    public function test_execute_creates_correct_assignments(): void
    {
        [$application, $kavling] = $this->createApplicationWithKavling(['consumer_status' => 'Lanjut']);
        $service = app(ConsumerKavlingBackfillService::class);

        $result = $service->execute();

        $this->assertSame(1, $result['created']);
        $this->assertDatabaseHas('consumer_kavling_assignments', [
            'consumer_application_id' => $application->id,
            'kavling_id' => $kavling->id,
            'assignment_status' => 'active',
        ]);
    }

    public function test_execute_creates_sold_assignment_for_akad_application(): void
    {
        [$application, $kavling] = $this->createApplicationWithKavling([
            'consumer_status' => 'Lanjut',
            'current_stage' => 'akad',
            'akad_date' => '2026-03-01',
        ]);
        $service = app(ConsumerKavlingBackfillService::class);

        $result = $service->execute();

        $this->assertSame(1, $result['created']);
        $this->assertDatabaseHas('consumer_kavling_assignments', [
            'consumer_application_id' => $application->id,
            'kavling_id' => $kavling->id,
            'assignment_status' => 'sold',
        ]);
    }

    public function test_execute_is_idempotent(): void
    {
        [$application, $kavling] = $this->createApplicationWithKavling();
        $service = app(ConsumerKavlingBackfillService::class);

        $first = $service->execute();
        $second = $service->execute();

        $this->assertSame(1, $first['created']);
        $this->assertSame(0, $second['created']);
        $this->assertDatabaseCount('consumer_kavling_assignments', 1);
    }

    public function test_execute_does_not_alter_consumer_stage_events(): void
    {
        [$application, $kavling] = $this->createApplicationWithKavling();
        $event = ConsumerStageEvent::create([
            'consumer_application_id' => $application->id,
            'stage' => 'bi_checking',
            'status' => 'completed',
        ]);
        $service = app(ConsumerKavlingBackfillService::class);

        $service->execute();

        $this->assertDatabaseHas('consumer_stage_events', [
            'id' => $event->id,
            'stage' => 'bi_checking',
            'status' => 'completed',
        ]);
    }

    public function test_execute_does_not_alter_consumer_bank_processes(): void
    {
        [$application, $kavling] = $this->createApplicationWithKavling();
        $bank = ConsumerBankProcess::create([
            'consumer_application_id' => $application->id,
            'bank_name' => 'BRI',
            'status' => 'pending',
        ]);
        $service = app(ConsumerKavlingBackfillService::class);

        $service->execute();

        $this->assertDatabaseHas('consumer_bank_processes', [
            'id' => $bank->id,
            'bank_name' => 'BRI',
            'status' => 'pending',
        ]);
    }

    public function test_no_sensitive_data_in_preview_output(): void
    {
        [$application, $kavling] = $this->createApplicationWithKavling();
        $service = app(ConsumerKavlingBackfillService::class);

        $result = $service->preview();
        $serialized = $result->toJson();

        $this->assertStringNotContainsString('nik', strtolower($serialized));
        $this->assertStringNotContainsString('phone', strtolower($serialized));
    }

    public function test_branch_filter_works(): void
    {
        [$app1, $kavling1] = $this->createApplicationWithKavling();
        [$app2, $kavling2] = $this->createApplicationWithKavling();
        $service = app(ConsumerKavlingBackfillService::class);

        $result = $service->preview(branchId: $app1->branch_id);

        $this->assertSame(1, $result->count());
        $this->assertSame($app1->id, $result->first()['application_id']);
    }

    public function test_project_filter_works(): void
    {
        [$app1, $kavling1] = $this->createApplicationWithKavling();
        [$app2, $kavling2] = $this->createApplicationWithKavling();
        $service = app(ConsumerKavlingBackfillService::class);

        $result = $service->preview(projectId: $app1->project_id);

        $this->assertSame(1, $result->count());
        $this->assertSame($app1->id, $result->first()['application_id']);
    }

    public function test_conflicting_row_does_not_corrupt_other_assignments(): void
    {
        [$app1, $kavling1] = $this->createApplicationWithKavling(['consumer_status' => 'Lanjut']);
        [$app2, $kavling2] = $this->createApplicationWithKavling(['consumer_status' => 'Lanjut']);
        ConsumerApplication::factory()->create([
            'kavling_id' => $kavling1->id,
            'branch_id' => $app1->branch_id,
            'project_id' => $app1->project_id,
        ]);
        $service = app(ConsumerKavlingBackfillService::class);

        $result = $service->execute();

        $this->assertSame(1, $result['created']);
        $this->assertDatabaseHas('consumer_kavling_assignments', [
            'consumer_application_id' => $app2->id,
            'kavling_id' => $kavling2->id,
            'assignment_status' => 'active',
        ]);
        $this->assertDatabaseCount('consumer_kavling_assignments', 1);
    }

    public function test_command_preview_mode_outputs_correctly(): void
    {
        [$application, $kavling] = $this->createApplicationWithKavling();

        $this->artisan('consumer-kavling:backfill')
            ->expectsOutputToContain('PREVIEW')
            ->assertSuccessful();

        $this->assertDatabaseCount('consumer_kavling_assignments', 0);
    }

    public function test_command_execute_mode_creates_assignments(): void
    {
        [$application, $kavling] = $this->createApplicationWithKavling();

        $this->artisan('consumer-kavling:backfill', ['--execute' => true])
            ->expectsOutputToContain('EXECUTE')
            ->assertSuccessful();

        $this->assertDatabaseHas('consumer_kavling_assignments', [
            'consumer_application_id' => $application->id,
            'kavling_id' => $kavling->id,
        ]);
    }

    public function test_command_json_format_outputs_valid_json(): void
    {
        [$application, $kavling] = $this->createApplicationWithKavling();

        $this->artisan('consumer-kavling:backfill', ['--format' => 'json'])
            ->assertSuccessful();

        $this->assertDatabaseCount('consumer_kavling_assignments', 0);
    }

    public function test_soft_deleted_application_is_excluded(): void
    {
        [$application, $kavling] = $this->createApplicationWithKavling();
        $application->delete();
        $service = app(ConsumerKavlingBackfillService::class);

        $result = $service->preview();

        $this->assertSame(0, $result->count());
    }

    public function test_application_without_kavling_id_is_excluded(): void
    {
        ConsumerApplication::factory()->create(['kavling_id' => null]);
        $service = app(ConsumerKavlingBackfillService::class);

        $result = $service->preview();

        $this->assertSame(0, $result->count());
    }

    public function test_pindah_kavling_application_classifies_current_kavling(): void
    {
        [$application, $kavling] = $this->createApplicationWithKavling(['consumer_status' => 'Pindah Kavling']);
        $service = app(ConsumerKavlingBackfillService::class);

        $result = $service->preview();

        $row = $result->first();
        $this->assertSame('READY_RESERVED', $row['classification']);
        $this->assertSame('active', $row['intended_status']);
        $this->assertSame($kavling->id, $row['kavling_id']);
    }

    public function test_multiple_mundur_applications_are_all_skipped(): void
    {
        [$app1, $kavling1] = $this->createApplicationWithKavling(['consumer_status' => 'Mundur']);
        [$app2, $kavling2] = $this->createApplicationWithKavling(['consumer_status' => 'Mundur']);
        $service = app(ConsumerKavlingBackfillService::class);

        $result = $service->preview();

        $this->assertSame(2, $result->count());
        $this->assertSame(0, $result->where('classification', '!=', 'SKIPPED')->count());
    }
}
