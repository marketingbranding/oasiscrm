<?php

namespace Tests\Feature;

use App\Exports\SupervisorSalesAgendaExport;
use App\Exports\SupervisorSalesLeadExport;
use App\Models\ContentItem;
use App\Models\LeadMaster;
use App\Models\SalesLead;
use App\Models\SalesLeadStatusHistory;
use App\Models\User;
use App\Services\AppSettingResolver;
use App\Services\ReportingHierarchyService;
use App\Services\SalesAgendaReceiptService;
use App\Services\SalesMonitoringProjectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class SalesMonitoringProjectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_br51_distant_lead_projection_is_allowlisted_and_flag_off_keeps_model(): void
    {
        $viewer = new User(['id' => 1]);
        $sales = new User(['id' => 2, 'name' => 'Sales Jauh']);
        $project = new LeadMaster(['project_name' => 'Proyek Jauh']);
        $lead = new SalesLead([
            'id' => 9,
            'sales_user_id' => 2,
            'customer_name' => 'Nama Lead',
            'phone' => '08123456789',
            'notes' => 'Catatan rahasia',
            'current_status' => 'discussion',
            'current_status_changed_at' => '2026-08-10 10:00:00',
            'updated_at' => '2026-08-11 10:00:00',
        ]);
        $lead->forceFill(['updated_at' => '2026-08-11 10:00:00']);
        $lead->setRelation('sales', $sales);
        $lead->setRelation('project', $project);

        $this->setHierarchicalMonitoring(false);
        $this->mock(ReportingHierarchyService::class, function ($mock): void {
            $mock->shouldReceive('distance')->never();
        });
        $this->mock(SalesAgendaReceiptService::class);

        $projection = app(SalesMonitoringProjectionService::class);
        $this->assertSame($lead, $projection->project($viewer, 2, 'lead', $lead));

        $this->setHierarchicalMonitoring(true);
        $this->app->forgetInstance(SalesMonitoringProjectionService::class);
        $this->mock(ReportingHierarchyService::class, function ($mock): void {
            $mock->shouldReceive('distance')->once()->andReturn(2);
        });

        $projected = app(SalesMonitoringProjectionService::class)->project($viewer, 2, 'lead', $lead);

        $this->assertIsArray($projected);
        $this->assertSame('Nama Lead', $projected['name']);
        $this->assertSame('Sales Jauh', $projected['sales_name']);
        $this->assertSame('Proyek Jauh', $projected['project_name']);
        $this->assertSame('Diskusi', $projected['stage']);
        $this->assertSame('2026-08-10 10:00:00', $projected['last_active_at']);
        $this->assertArrayNotHasKey('phone', $projected);
        $this->assertArrayNotHasKey('notes', $projected);
    }

    public function test_br51_distant_lead_projection_uses_latest_status_activity_not_updated_at(): void
    {
        $this->setHierarchicalMonitoring(true);
        $viewer = new User(['id' => 1]);
        $lead = new SalesLead([
            'sales_user_id' => 2,
            'customer_name' => 'Lead Aktif',
            'current_status' => 'discussion',
            'current_status_changed_at' => '2026-08-01 10:00:00',
            'updated_at' => '2026-08-11 10:00:00',
        ]);
        $lead->setRelation('sales', new User(['id' => 2, 'name' => 'Sales Jauh']));
        $lead->setRelation('project', new LeadMaster(['project_name' => 'Proyek Jauh']));
        $lead->setRelation('latestStatusHistory', new SalesLeadStatusHistory([
            'changed_at' => '2026-08-05 09:00:00',
        ]));

        $hierarchy = Mockery::mock(ReportingHierarchyService::class);
        $hierarchy->shouldReceive('distance')->once()->with($viewer, 2)->andReturn(2);
        $this->app->instance(ReportingHierarchyService::class, $hierarchy);
        $this->mock(SalesAgendaReceiptService::class);

        $projected = app(SalesMonitoringProjectionService::class)->project($viewer, 2, 'lead', $lead);

        $this->assertSame('2026-08-05 09:00:00', $projected['last_active_at']);
    }

    public function test_br51_distant_agenda_projection_counts_all_late_activities_for_owner_date(): void
    {
        $this->setHierarchicalMonitoring(true);
        $viewer = new User(['id' => 1]);
        $owner = new User(['id' => 2, 'name' => 'Sales Jauh']);
        $first = new ContentItem(['owner_user_id' => 2, 'scheduled_date' => '2026-08-10', 'status' => 'planned']);
        $first->setRelation('owner', $owner);
        $second = new ContentItem(['owner_user_id' => 2, 'scheduled_date' => '2026-08-10', 'status' => 'planned']);
        $second->setRelation('owner', $owner);
        $hierarchy = Mockery::mock(ReportingHierarchyService::class);
        $hierarchy->shouldReceive('distance')->once()->with($viewer, 2)->andReturn(2);
        $this->app->instance(ReportingHierarchyService::class, $hierarchy);
        $receipt = Mockery::mock(SalesAgendaReceiptService::class);
        $receipt->shouldReceive('summarize')->once()->andReturn([
            'normal_minutes' => 120, 'target_minutes' => 420, 'submitted' => false,
            'agenda_count' => 2, 'late_activity_count' => 2,
        ]);
        $this->app->instance(SalesAgendaReceiptService::class, $receipt);

        $projected = app(SalesMonitoringProjectionService::class)->projectCollection($viewer, 'agenda', collect([$first, $second]), 'owner_user_id');

        $this->assertSame([2, 2], $projected->pluck('late_activity_count')->all());
    }

    public function test_br51_direct_lead_projection_keeps_existing_model_shape(): void
    {
        $this->setHierarchicalMonitoring(true);
        $viewer = new User(['id' => 1]);
        $lead = new SalesLead(['sales_user_id' => 2]);
        $hierarchy = Mockery::mock(ReportingHierarchyService::class);
        $hierarchy->shouldReceive('distance')->once()->with($viewer, 2)->andReturn(1);
        $this->app->instance(ReportingHierarchyService::class, $hierarchy);
        $this->mock(SalesAgendaReceiptService::class);

        $this->assertSame($lead, app(SalesMonitoringProjectionService::class)->project($viewer, 2, 'lead', $lead));
    }

    public function test_br51_distant_agenda_projection_excludes_contents_location_notes_and_evidence(): void
    {
        $this->setHierarchicalMonitoring(true);
        $viewer = new User(['id' => 1]);
        $sales = new User(['id' => 2, 'name' => 'Sales Jauh']);
        $agenda = new ContentItem([
            'id' => 3,
            'owner_user_id' => 2,
            'scheduled_date' => '2026-08-11',
            'title' => 'Agenda rahasia',
            'location' => 'Lokasi rahasia',
            'notes' => 'Catatan rahasia',
            'activity_result' => 'Hasil rahasia',
            'duration_minutes' => 60,
            'status' => 'done',
        ]);
        $agenda->setRelation('owner', $sales);
        $hierarchy = Mockery::mock(ReportingHierarchyService::class);
        $hierarchy->shouldReceive('distance')->once()->with($viewer, 2)->andReturn(2);
        $this->app->instance(ReportingHierarchyService::class, $hierarchy);
        $receipt = Mockery::mock(SalesAgendaReceiptService::class);
        $receipt->shouldReceive('summarize')->once()->andReturn([
            'normal_minutes' => 60,
            'target_minutes' => 420,
            'submitted' => true,
            'agenda_count' => 1,
        ]);
        $this->app->instance(SalesAgendaReceiptService::class, $receipt);

        $projected = app(SalesMonitoringProjectionService::class)->project($viewer, 2, 'agenda', $agenda);

        $this->assertIsArray($projected);
        $this->assertSame('Sales Jauh', $projected['sales_name']);
        $this->assertSame('2026-08-11', $projected['date']);
        $this->assertSame(60, $projected['total_minutes']);
        $this->assertSame(420, $projected['target_minutes']);
        $this->assertTrue($projected['submitted']);
        $this->assertSame(1, $projected['activity_count']);
        $this->assertArrayNotHasKey('title', $projected);
        $this->assertArrayNotHasKey('location', $projected);
        $this->assertArrayNotHasKey('notes', $projected);
        $this->assertArrayNotHasKey('activity_result', $projected);
        $this->assertArrayNotHasKey('evidence', $projected);
    }

    public function test_br51_export_projection_contains_no_distant_lead_or_agenda_secret_fields(): void
    {
        $lead = [
            'lead_date' => '2026-08-11',
            'name' => 'Nama Lead',
            'sales_name' => 'Sales Jauh',
            'project_name' => 'Proyek Jauh',
            'stage' => 'Diskusi',
            'last_active_at' => '2026-08-11 10:00:00',
        ];
        $agenda = [
            'date' => '2026-08-11',
            'sales_name' => 'Sales Jauh',
            'project_name' => 'Proyek Jauh',
            'total_minutes' => 60,
            'target_minutes' => 420,
            'submitted' => true,
            'activity_count' => 1,
            'late_activity_count' => 0,
        ];

        $leadSheet = $this->sheet(SupervisorSalesLeadExport::toBrowser(collect([$lead]), [], 'distant-lead.xlsx'));
        $agendaSheet = $this->sheet(SupervisorSalesAgendaExport::toBrowser(collect([$agenda]), [], 'distant-agenda.xlsx'));
        $leadValues = collect($leadSheet->toArray())->flatten()->implode('|');
        $agendaValues = collect($agendaSheet->toArray())->flatten()->implode('|');

        $this->assertStringContainsString('Nama Lead', $leadValues);
        $this->assertStringContainsString('Sales Jauh', $leadValues);
        $this->assertStringNotContainsString('08123456789', $leadValues);
        $this->assertStringNotContainsString('Catatan rahasia', $leadValues);
        $this->assertStringContainsString('60 / 420 menit', $agendaValues);
        $this->assertStringNotContainsString('Agenda rahasia', $agendaValues);
        $this->assertStringNotContainsString('Lokasi rahasia', $agendaValues);
        $this->assertStringNotContainsString('Hasil rahasia', $agendaValues);
    }

    private function sheet($response)
    {
        $path = $response->getFile()->getPathname();
        $workbook = IOFactory::load($path);
        $sheet = clone $workbook->getActiveSheet();
        $workbook->disconnectWorksheets();
        @unlink($path);

        return $sheet;
    }

    private function setHierarchicalMonitoring(bool $enabled): void
    {
        DB::table('app_settings')->updateOrInsert(
            ['key' => 'org_hierarchical_monitoring'],
            [
                'value' => json_encode($enabled, JSON_THROW_ON_ERROR),
                'group' => 'feature_flags',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
        app(AppSettingResolver::class)->forget('org_hierarchical_monitoring');
    }
}
