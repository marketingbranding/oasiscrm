<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ConsumerApplication;
use App\Models\ConsumerKavlingAssignment;
use App\Models\ConsumerMigrationReconciliation;
use App\Models\ConsumerStageEvent;
use App\Models\Kavling;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\User;
use App\Services\ConsumerKavlingLifecycleService;
use App\Services\ConsumerMigrationReconciliationService;
use App\Services\ConsumerOperationalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ConsumerMigrationReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private LeadMaster $project;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::create(['code' => 'MGL', 'name' => 'Magelang', 'is_active' => true]);
        $this->project = LeadMaster::create(['branch_id' => $this->branch->id, 'project_name' => 'Proyek Migrasi', 'is_active' => true]);
        $this->admin = User::factory()->create(['role_id' => Role::where('slug', 'superadmin')->value('id'), 'branch_id' => $this->branch->id, 'email_verified_at' => now(), 'password_changed_at' => now()]);
    }

    public function test_pending_queue_and_resolved_item_scope(): void
    {
        [$item] = $this->context();
        $this->assertCount(1, app(ConsumerMigrationReconciliationService::class)->visibleQuery($this->admin)->get());
        app(ConsumerMigrationReconciliationService::class)->apply($item, $this->admin, ['decision' => 'REJECT', 'new_customer_name' => 'Konsumen Baru']);
        $this->assertCount(0, app(ConsumerMigrationReconciliationService::class)->visibleQuery($this->admin)->get());
        $this->assertSame('RESOLVED', $item->fresh()->reconciliation_status);
    }

    public function test_lanjut_creates_customer_and_active_assignment(): void
    {
        [$item, , $kavling] = $this->context();
        app(ConsumerMigrationReconciliationService::class)->apply($item, $this->admin, ['decision' => 'LANJUT', 'new_customer_name' => 'Customer Lanjut', 'target_kavling_id' => $kavling->id]);
        $app = $item->application()->first();
        $this->assertSame('Customer Lanjut', $app->customer->name);
        $this->assertSame('active', $app->application_status);
        $this->assertSame($kavling->id, $app->kavling_id);
        $this->assertDatabaseHas('consumer_kavling_assignments', ['consumer_application_id' => $app->id, 'kavling_id' => $kavling->id, 'assignment_status' => 'active']);
        $this->assertDatabaseHas('activity_log', ['event' => 'marison_reconciliation_applied']);
    }

    public function test_mundur_preserves_process_and_has_no_active_assignment(): void
    {
        [$item, $app, $kavling] = $this->context();
        app(ConsumerKavlingLifecycleService::class)->assign($app, $kavling);
        $event = ConsumerStageEvent::create(['consumer_application_id' => $app->id, 'stage' => 'PSJB', 'source' => 'marison_v2', 'source_id' => 'PSJB-1']);
        app(ConsumerMigrationReconciliationService::class)->apply($item, $this->admin, ['decision' => 'MUNDUR', 'new_customer_name' => 'Customer Mundur']);
        $this->assertSame('Mundur', $app->fresh()->consumer_status);
        $this->assertSame(1, ConsumerStageEvent::whereKey($event->id)->count());
        $this->assertSame(0, ConsumerKavlingAssignment::where('consumer_application_id', $app->id)->where('assignment_status', 'active')->count());
    }

    public function test_reject_keeps_assignment_and_occupied_target_rolls_back(): void
    {
        [$item, $app, $kavling] = $this->context();
        app(ConsumerMigrationReconciliationService::class)->apply($item, $this->admin, ['decision' => 'REJECT', 'new_customer_name' => 'Reject Customer']);
        $this->assertSame(0, ConsumerKavlingAssignment::where('consumer_application_id', $app->id)->count());
        $otherApp = ConsumerApplication::create(['branch_id' => $this->branch->id, 'project_id' => $this->project->id, 'application_status' => 'migration_pending']);
        app(ConsumerKavlingLifecycleService::class)->assign($otherApp, $kavling);
        $thirdApp = ConsumerApplication::create(['branch_id' => $this->branch->id, 'project_id' => $this->project->id, 'application_status' => 'migration_pending']);
        $thirdItem = ConsumerMigrationReconciliation::create(['consumer_application_id' => $thirdApp->id, 'branch_id' => $this->branch->id, 'project_id' => $this->project->id, 'source_system' => 'marison_v2', 'source_transaction_id' => 'TRX-THIRD', 'reconciliation_status' => 'PENDING', 'source_payload_hash' => hash('sha256', 'third')]);
        $this->expectException(\DomainException::class);
        app(ConsumerMigrationReconciliationService::class)->apply($thirdItem, $this->admin, ['decision' => 'LANJUT', 'new_customer_name' => 'Other', 'target_kavling_id' => $kavling->id]);
    }

    public function test_reconciled_application_accepts_next_operational_process(): void
    {
        [$item, $app] = $this->context();
        app(ConsumerMigrationReconciliationService::class)->apply($item, $this->admin, ['decision' => 'REJECT', 'new_customer_name' => 'Operational Customer']);
        $event = app(ConsumerOperationalService::class)->recordBiChecking($app->fresh(), ['tanggal_slik' => '2026-09-18', 'hasil_slik' => 'Lolos', 'keterangan' => 'Lanjut'], $this->admin);
        $this->assertSame('bi_checking', $event->stage);
        $this->assertSame('Lolos', $event->status);
    }

    public function test_selesai_marks_assignment_sold_and_second_apply_is_denied(): void
    {
        [$item, $app, $kavling] = $this->context();
        app(ConsumerMigrationReconciliationService::class)->apply($item, $this->admin, ['decision' => 'SELESAI', 'new_customer_name' => 'Customer Selesai', 'target_kavling_id' => $kavling->id]);
        $this->assertDatabaseHas('consumer_kavling_assignments', ['consumer_application_id' => $app->id, 'assignment_status' => 'sold']);
        $this->expectException(ValidationException::class);
        app(ConsumerMigrationReconciliationService::class)->apply($item->fresh(), $this->admin, ['decision' => 'REJECT', 'customer_id' => $app->customer_id]);
    }

    private function context(string $suffix = ''): array
    {
        $project = $suffix === '' ? $this->project : LeadMaster::create(['branch_id' => $this->branch->id, 'project_name' => 'Proyek '.$suffix, 'is_active' => true]);
        $kavling = Kavling::create(['project_id' => $project->id, 'kavling_code' => 'A-'.$suffix.'01', 'name' => 'A-'.$suffix.'01']);
        $app = ConsumerApplication::create(['branch_id' => $this->branch->id, 'project_id' => $project->id, 'application_status' => 'migration_pending', 'id_kavling' => $kavling->name]);
        $item = ConsumerMigrationReconciliation::create(['consumer_application_id' => $app->id, 'branch_id' => $this->branch->id, 'project_id' => $project->id, 'source_system' => 'marison_v2', 'source_transaction_id' => 'TRX-'.$suffix.uniqid(), 'source_current_kavling' => $kavling->name, 'reconciliation_status' => 'PENDING', 'source_payload_hash' => hash('sha256', $app->id)]);

        return [$item, $app, $kavling];
    }
}
