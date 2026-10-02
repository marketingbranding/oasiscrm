<?php

namespace Tests\Feature;

use Database\Seeders\WorkspaceV2DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WorkspaceV2DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_local_workspace_scenarios_across_the_main_process_records(): void
    {
        $this->seed(WorkspaceV2DemoSeeder::class);

        $this->assertDatabaseCount('consumer_applications', 28);
        $this->assertDatabaseCount('consumer_nups', 3);
        $this->assertDatabaseCount('sales_leads', 5);
        $this->assertDatabaseHas('consumer_applications', [
            'notes' => '[W2-DEMO] bank-sp3k Data demo lokal untuk UAT Workspace V2.',
            'current_process' => 'proses_bank',
            'payment_method' => 'kpr',
        ]);
        $this->assertDatabaseHas('consumer_bank_processes', [
            'no_sp3k' => 'SP3K-DEMO-6',
            'source' => 'workspace_v2_demo',
        ]);
        $this->assertDatabaseHas('consumer_issues', [
            'status' => 'open',
            'source' => null,
        ]);
        $this->assertDatabaseHas('consumer_warranties', [
            'status_garansi' => 'Proses',
        ]);
        $this->assertDatabaseHas('consumer_process_applicabilities', [
            'consumer_application_id' => $this->demoApplicationId('cash'),
            'process_key' => 'pemberkasan',
            'applicability' => 'not_applicable',
        ]);
    }

    private function demoApplicationId(string $key): int
    {
        return (int) DB::table('consumer_applications')
            ->where('notes', 'like', '[W2-DEMO] '.$key.'%')
            ->value('id');
    }
}
