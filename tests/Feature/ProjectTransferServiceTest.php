<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\User;
use App\Services\ProjectTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProjectTransferServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_create_leads_move_in_one_transaction(): void
    {
        $source = $this->branch('Batang', 'BAT', 'sheet-batang');
        $target = $this->branch('Tegal', 'TEG', 'sheet-tegal');
        $project = LeadMaster::query()->create(['branch_id' => $source->id, 'project_name' => 'UAT-Tegal', 'is_active' => true]);
        $sales = $this->user('sales', $source);
        DB::table('project_user')->insert(['project_id' => $project->id, 'user_id' => $sales->id, 'is_active' => true, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('sales_leads')->insert([
            'branch_id' => $source->id,
            'project_id' => $project->id,
            'sales_user_id' => $sales->id,
            'lead_date' => today(),
            'customer_name' => 'UAT-Lead',
            'sync_status' => 'pending_create',
            'external_sync_id' => 'UAT-sync-1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $preview = app(ProjectTransferService::class)->preview($source, $target, [$project->id], [$sales->id => 'pindah']);
        $this->assertSame([], $preview['blockers']);
        $this->assertSame(1, $preview['impact']['sales_leads']);

        $result = app(ProjectTransferService::class)->execute(User::factory()->create(), $source, $target, [$project->id], [$sales->id => 'pindah']);

        $this->assertSame('completed', $result['batch']->status);
        $this->assertSame($target->id, $project->fresh()->branch_id);
        $this->assertSame($target->id, DB::table('sales_leads')->where('project_id', $project->id)->value('branch_id'));
        $this->assertSame($target->id, DB::table('users')->where('id', $sales->id)->value('branch_id'));
    }

    public function test_sent_lead_blocks_transfer(): void
    {
        $source = $this->branch('Batang', 'BAT', 'sheet-batang');
        $target = $this->branch('Tegal', 'TEG', 'sheet-tegal');
        $project = LeadMaster::query()->create(['branch_id' => $source->id, 'project_name' => 'UAT-Tegal', 'is_active' => true]);
        $sales = $this->user('sales', $source);
        DB::table('sales_leads')->insert([
            'branch_id' => $source->id,
            'project_id' => $project->id,
            'sales_user_id' => $sales->id,
            'lead_date' => today(),
            'customer_name' => 'UAT-Lead',
            'sync_status' => 'synced',
            'external_sync_id' => 'UAT-sync-1',
            'last_synced_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(ValidationException::class);
        app(ProjectTransferService::class)->execute(User::factory()->create(), $source, $target, [$project->id], []);
    }

    private function branch(string $name, string $code, string $sheetId): Branch
    {
        return Branch::query()->create(['name' => $name, 'code' => $code, 'sheet_id' => $sheetId, 'is_active' => true]);
    }

    private function user(string $role, Branch $branch): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $role)->value('id'),
            'branch_id' => $branch->id,
            'password_changed_at' => now(),
        ]);
    }
}
