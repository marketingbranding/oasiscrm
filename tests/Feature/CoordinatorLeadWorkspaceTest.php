<?php

namespace Tests\Feature;

use App\Http\Controllers\Crm\CoordinatorSalesLeadWorkspaceController;
use ReflectionMethod;
use Tests\TestCase;

class CoordinatorLeadWorkspaceTest extends TestCase
{
    public function test_controller_exposes_workspace_export_and_push_actions(): void
    {
        foreach (['index', 'export', 'push'] as $method) {
            $this->assertTrue((new ReflectionMethod(CoordinatorSalesLeadWorkspaceController::class, $method))->isPublic());
        }
    }

    public function test_workspace_keeps_team_lead_contract_without_agenda_or_lifecycle_ui(): void
    {
        $view = file_get_contents(resource_path('views/crm/sales-pocketbook/coordinator-leads.blade.php'));

        $this->assertStringContainsString("route('sales-leads.store')", $view);
        $this->assertStringContainsString("route('sales-leads.edit'", $view);
        $this->assertStringContainsString("route('coordinator-leads.export')", $view);
        $this->assertStringContainsString("route('coordinator-leads.sync')", $view);
        $this->assertStringContainsString('name="branch_id" x-model="branch"', $view);
        $this->assertStringContainsString('projectsBySales', $view);
        $this->assertStringContainsString('BELUM SYNC', $view);
        $this->assertStringContainsString('TERSYNC', $view);
        $this->assertStringContainsString('PERLU SYNC ULANG', $view);
        $this->assertStringContainsString('SYNC GAGAL', $view);
        $this->assertStringNotContainsString('Agenda', $view);
        $this->assertStringNotContainsString('lifecycle', $view);
        $this->assertStringNotContainsString('reconciliation', $view);
        $this->assertStringNotContainsString('sales-leads.options', $view);
    }
}
