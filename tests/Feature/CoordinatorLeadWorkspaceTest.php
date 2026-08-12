<?php

namespace Tests\Feature;

use App\Http\Controllers\Crm\CoordinatorSalesLeadWorkspaceController;
use App\Support\SalesLeadMasterData;
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

    public function test_workspace_uses_canonical_local_lead_options_and_monitoring_contract(): void
    {
        $view = file_get_contents(resource_path('views/crm/sales-pocketbook/coordinator-leads.blade.php'));

        foreach (['Ringkasan Periode', 'Lead Baru', 'Tatap Muka', 'Cek Lokasi', 'UTJ', 'Performa Sales', 'Agenda Sales Tim', 'Nama Promo', '<th>Lokasi</th><th>Hasil</th><th>Status</th>', 'Belum ada aktivitas pada periode ini.', 'Metrik akan terisi setelah lead atau agenda selesai tercatat dalam scope dan periode yang dipilih.', 'Pilih sumber lead', 'Pilih promo', 'Pilih Sales'] as $label) {
            $this->assertStringContainsString($label, $view);
        }
        foreach (['BELUM SYNC', 'TERSYNC', 'PERLU SYNC ULANG', 'SYNC GAGAL', 'ID Promo', 'sales-leads.options', 'Tatap Muka Konsumen', '>Survey<', 'Survey Lokasi'] as $label) {
            $this->assertStringNotContainsString($label, $view);
        }
        foreach ([SalesLeadMasterData::SOURCES, SalesLeadMasterData::CHANNELS, SalesLeadMasterData::ACTIVITIES] as $options) {
            foreach ($options as $option) {
                $this->assertContains($option, $options);
            }
        }
        $this->assertStringContainsString('$statuses as $status', $view);
        $this->assertStringContainsString("route('sales-leads.edit'", $view);
        foreach (['id="coordinator-team-agenda"', 'id="coordinator-team-leads"', 'id="monitor-date-from"', 'id="monitor-date-to"', 'id="coordinator-lead-date"'] as $contract) {
            $this->assertStringContainsString($contract, $view);
        }
        $this->assertStringNotContainsString('<section class="border-2 border-black bg-white">', $view);
        $this->assertStringNotContainsString('type="date"', $view);
    }

    public function test_edit_form_uses_local_options_and_preserves_historical_values(): void
    {
        $view = file_get_contents(resource_path('views/crm/sales-pocketbook/edit.blade.php'));

        $this->assertStringNotContainsString('sales-leads.options', $view);
        $this->assertStringNotContainsString('sheetOptions', $view);
        $this->assertStringNotContainsString('ID Promo', $view);
        $this->assertStringContainsString('Nama Promo', $view);
        $this->assertStringContainsString('(historis)', $view);
        $this->assertStringContainsString('SalesLeadStatus::cases()', $view);
        foreach (['Tatap Muka Konsumen', '>Survey<', 'Survey Lokasi', 'status sistem, baca-saja'] as $label) {
            $this->assertStringNotContainsString($label, $view);
        }
    }
}
