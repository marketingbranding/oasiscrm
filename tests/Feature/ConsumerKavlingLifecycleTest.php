<?php

namespace Tests\Feature;

use App\Models\ConsumerApplication;
use App\Models\ConsumerBankProcess;
use App\Models\ConsumerKavlingAssignment;
use App\Models\ConsumerStageEvent;
use App\Models\Kavling;
use App\Services\ConsumerKavlingLifecycleService;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConsumerKavlingLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_unassigned_kavling_is_available_and_assignment_is_reserved(): void
    {
        [$application, $kavling] = $this->records();
        $service = app(ConsumerKavlingLifecycleService::class);

        $this->assertSame('AVAILABLE', $service->availability($kavling));
        $service->assign($application, $kavling);

        $this->assertSame('RESERVED', $service->availability($kavling->fresh()));
        $this->assertSame($kavling->id, $application->fresh()->kavling_id);
    }

    public function test_second_application_cannot_reserve_same_kavling(): void
    {
        [$application, $kavling] = $this->records();
        $second = ConsumerApplication::factory()->create(['branch_id' => $application->branch_id, 'project_id' => $application->project_id]);
        $service = app(ConsumerKavlingLifecycleService::class);
        $service->assign($application, $kavling);

        $this->expectException(DomainException::class);
        $service->assign($second, $kavling);
    }

    public function test_mundur_releases_assignment_and_preserves_process_history(): void
    {
        [$application, $kavling] = $this->records();
        $stage = ConsumerStageEvent::factory()->create(['consumer_application_id' => $application->id, 'stage' => 'bi_checking']);
        $bank = ConsumerBankProcess::factory()->create(['consumer_application_id' => $application->id]);
        $service = app(ConsumerKavlingLifecycleService::class);
        $service->assign($application, $kavling);
        $service->mundur($application);

        $assignment = ConsumerKavlingAssignment::firstOrFail();
        $this->assertSame('released', $assignment->assignment_status);
        $this->assertSame('mundur', $assignment->release_reason);
        $this->assertNull($application->fresh()->kavling_id);
        $this->assertSame('Mundur', $application->fresh()->consumer_status);
        $this->assertSame('AVAILABLE', $service->availability($kavling->fresh()));
        $this->assertDatabaseHas('consumer_stage_events', ['id' => $stage->id]);
        $this->assertDatabaseHas('consumer_bank_processes', ['id' => $bank->id]);
    }

    public function test_pindah_releases_old_and_reserves_new_on_same_application(): void
    {
        [$application, $old] = $this->records();
        $new = Kavling::create(['project_id' => $application->project_id, 'kavling_code' => 'A-02', 'name' => 'A-02']);
        $service = app(ConsumerKavlingLifecycleService::class);
        $service->assign($application, $old);
        $service->pindahKavling($application, $new);

        $assignments = ConsumerKavlingAssignment::query()->orderBy('id')->get();
        $this->assertCount(2, $assignments);
        $this->assertSame('pindah_kavling', $assignments[0]->release_reason);
        $this->assertSame('active', $assignments[1]->assignment_status);
        $this->assertSame($application->id, $assignments[1]->consumer_application_id);
        $this->assertSame($new->id, $application->fresh()->kavling_id);
        $this->assertSame('AVAILABLE', $service->availability($old->fresh()));
        $this->assertSame('RESERVED', $service->availability($new->fresh()));
    }

    public function test_pindah_rejects_occupied_target_without_half_move(): void
    {
        [$application, $old] = $this->records();
        $other = ConsumerApplication::factory()->create(['branch_id' => $application->branch_id, 'project_id' => $application->project_id]);
        $target = Kavling::create(['project_id' => $application->project_id, 'kavling_code' => 'A-02', 'name' => 'A-02']);
        $service = app(ConsumerKavlingLifecycleService::class);
        $service->assign($application, $old);
        $service->assign($other, $target);

        try {
            $service->pindahKavling($application, $target);
            $this->fail('Expected occupied target rejection.');
        } catch (DomainException) {
            $this->assertSame($old->id, $application->fresh()->kavling_id);
        }

        $this->assertCount(2, ConsumerKavlingAssignment::query()->get());
        $this->assertSame('RESERVED', $service->availability($old->fresh()));
    }

    public function test_reject_bank_keeps_assignment_reserved(): void
    {
        [$application, $kavling] = $this->records();
        $service = app(ConsumerKavlingLifecycleService::class);
        $service->assign($application, $kavling);
        $application->update(['consumer_status' => 'Reject']);

        $this->assertSame('RESERVED', $service->availability($kavling->fresh()));
        $this->assertSame('active', ConsumerKavlingAssignment::firstOrFail()->assignment_status);
    }

    public function test_akad_makes_kavling_sold_and_bast_keeps_it_sold(): void
    {
        [$application, $kavling] = $this->records();
        $service = app(ConsumerKavlingLifecycleService::class);
        $service->assign($application, $kavling);
        $service->markAkad($application);

        $this->assertSame('SOLD', $service->availability($kavling->fresh()));
        $this->assertSame('sold', ConsumerKavlingAssignment::firstOrFail()->assignment_status);
        ConsumerStageEvent::factory()->create(['consumer_application_id' => $application->id, 'stage' => 'bast']);
        $this->assertSame('SOLD', $service->availability($kavling->fresh()));
    }

    public function test_active_assignment_constraint_is_database_enforced(): void
    {
        [$application, $kavling] = $this->records();
        ConsumerKavlingAssignment::create(['consumer_application_id' => $application->id, 'kavling_id' => $kavling->id, 'assigned_at' => now(), 'assignment_status' => 'active']);
        $second = ConsumerApplication::factory()->create(['branch_id' => $application->branch_id, 'project_id' => $application->project_id]);

        $this->expectException(QueryException::class);
        ConsumerKavlingAssignment::create(['consumer_application_id' => $second->id, 'kavling_id' => $kavling->id, 'assigned_at' => now(), 'assignment_status' => 'active']);
    }

    public function test_released_assignments_remain_queryable(): void
    {
        [$application, $kavling] = $this->records();
        $service = app(ConsumerKavlingLifecycleService::class);
        $service->assign($application, $kavling);
        $service->mundur($application);

        $this->assertCount(1, $kavling->fresh()->consumerAssignments);
        $this->assertCount(0, $kavling->fresh()->activeConsumerAssignment);
    }

    private function records(): array
    {
        $application = ConsumerApplication::factory()->create();
        $kavling = Kavling::create(['project_id' => $application->project_id, 'kavling_code' => 'A-01', 'name' => 'A-01']);

        return [$application, $kavling];
    }
}
