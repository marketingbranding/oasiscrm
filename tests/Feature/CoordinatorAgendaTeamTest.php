<?php

namespace Tests\Feature;

use Tests\TestCase;

class CoordinatorAgendaTeamTest extends TestCase
{
    public function test_coordinator_agenda_workspace_supports_team_create_update_and_monitoring_scope(): void
    {
        $view = file_get_contents(resource_path('views/crm/sales-pocketbook/coordinator-leads.blade.php'));
        $service = file_get_contents(app_path('Services/CoordinatorSalesMonitoringService.php'));

        $this->assertStringContainsString('Agenda Sales Tim', $view);
        $this->assertStringContainsString('sales_activity_category', $view);
        $this->assertStringContainsString("route('sales-agendas.store')", $view);
        $this->assertStringContainsString('route(\'sales-agendas.update\', $agenda)', $view);
        $this->assertStringNotContainsString("route('sales-agendas.reschedule')", $view);
        $this->assertStringContainsString("->whereIn('owner_user_id', \$salesIds)", $service);
        $this->assertStringContainsString("->whereIn('branch_id', \$scope['branch_ids'])", $service);
        $this->assertStringContainsString("->whereIn('sales_project_id', \$scope['project_ids'])", $service);
        $this->assertStringContainsString("->whereDate('scheduled_date', '>='", $service);
        $this->assertStringContainsString("->whereDate('scheduled_date', '<='", $service);
        $this->assertStringContainsString('abort_if($salesId && ! $filterSalesUsers->contains', $service);
        $this->assertStringContainsString('canManageAgenda', $service);
    }
}
