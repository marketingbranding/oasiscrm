<?php

namespace Tests\Feature;

use Tests\TestCase;

class CoordinatorAgendaTeamTest extends TestCase
{
    public function test_coordinator_agenda_table_is_read_only_and_scoped_by_monitoring_service(): void
    {
        $view = file_get_contents(resource_path('views/crm/sales-pocketbook/coordinator-leads.blade.php'));
        $service = file_get_contents(app_path('Services/CoordinatorSalesMonitoringService.php'));

        $this->assertStringContainsString('Agenda Sales Tim', $view);
        $this->assertStringContainsString('sales_activity_category', $view);
        $this->assertStringNotContainsString("route('sales-agendas.store')", $view);
        $this->assertStringNotContainsString("route('sales-agendas.update')", $view);
        $this->assertStringNotContainsString("route('sales-agendas.reschedule')", $view);
        $this->assertStringContainsString("->whereIn('owner_user_id', \$salesIds)", $service);
        $this->assertStringContainsString("->whereIn('branch_id', \$scope['branch_ids'])", $service);
        $this->assertStringContainsString("->whereIn('sales_project_id', \$scope['project_ids'])", $service);
        $this->assertStringContainsString("->whereDate('scheduled_date', '>='", $service);
        $this->assertStringContainsString("->whereDate('scheduled_date', '<='", $service);
        $this->assertStringContainsString('abort_if($salesId && ! $filterSalesUsers->contains', $service);
    }
}
