<?php

namespace Tests\Feature;

use App\Exports\CoordinatorSalesLeadExport;
use App\Models\Branch;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\SalesLead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class CoordinatorSalesLeadExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_uses_sync_status_even_when_every_lead_has_external_sync_identity(): void
    {
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Solo Project', 'is_active' => true]);
        $sales = User::factory()->create(['role_id' => Role::where('slug', 'sales')->value('id'), 'branch_id' => $branch->id]);
        $statuses = [
            'pending_create' => 'Belum Sync',
            'synced' => 'Tersinkron',
            'pending_update' => 'Perlu Sync Ulang',
            'sync_failed' => 'Sync Gagal',
        ];

        foreach (array_keys($statuses) as $index => $status) {
            SalesLead::create([
                'branch_id' => $branch->id,
                'project_id' => $project->id,
                'sales_user_id' => $sales->id,
                'lead_date' => '2026-08-10',
                'customer_name' => 'Lead '.$status,
                'source' => 'Referral',
                'platform' => 'WhatsApp',
                'campaign_name' => 'Campaign',
                'external_sync_id' => (string) Str::uuid(),
                'sync_status' => $status,
                'last_synced_at' => in_array($status, ['synced', 'pending_update'], true) ? now() : null,
                'created_by' => $sales->id,
                'updated_by' => $sales->id,
            ]);
        }

        $leads = SalesLead::with(['branch', 'project', 'sales'])->orderBy('id')->get();
        $response = CoordinatorSalesLeadExport::toBrowser($leads, 'lead-status.xlsx');
        $path = $response->getFile()->getPathname();
        $workbook = IOFactory::load($path);

        foreach (array_values($statuses) as $index => $label) {
            $this->assertSame($label, $workbook->getActiveSheet()->getCell('M'.($index + 2))->getValue());
        }
        $this->assertNotNull($leads->first()->external_sync_id);
        $this->assertSame('Belum Sync', $workbook->getActiveSheet()->getCell('M2')->getValue());

        $workbook->disconnectWorksheets();
        @unlink($path);
    }
}
