<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ConsumerApplication;
use App\Models\Customer;
use App\Models\DanaTalangan;
use App\Models\Kavling;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DanaTalanganBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_br42_dry_run_reports_strong_match_as_pasti_and_does_not_write(): void
    {
        [$branch, $project, $user] = $this->makeContext();
        $application = $this->makeApplication($branch, $project, 'Konsumen Pasti', 'TRX-PASTI');
        $psjb = $application->psjbs()->create([
            'id_psjb' => 'PSJB-PASTI',
            'tanggal_psjb' => '2026-11-01',
        ]);
        $record = $this->makeRecord($branch, $project, 'Nama Berbeda', [
            'id_transaksi' => $application->id_transaksi,
            'id_psjb' => $psjb->id_psjb,
        ]);
        $this->assertSame($application->id_transaksi, $record->id_transaksi);

        $this->artisan('dana-talangan:backfill-relations')
            ->expectsOutputToContain('PASTI')
            ->assertExitCode(0);

        $this->assertNull($record->fresh()->consumer_application_id);
    }

    public function test_br43_kavling_and_name_match_is_candidate_and_apply_does_not_write(): void
    {
        [$branch, $project, $user] = $this->makeContext();
        $kavling = Kavling::create(['project_id' => $project->id, 'kavling_code' => 'A-01', 'name' => 'A-01']);
        $this->makeApplication($branch, $project, 'Konsumen Kandidat', null, $kavling);
        $record = $this->makeRecord($branch, $project, 'Konsumen Kandidat', ['kav' => 'A-01']);

        $this->artisan('dana-talangan:backfill-relations --apply')
            ->expectsOutputToContain('KANDIDAT')
            ->assertExitCode(0);

        $this->assertNull($record->fresh()->consumer_application_id);
    }

    public function test_br44_name_only_is_not_found_and_apply_writes_no_relation(): void
    {
        [$branch, $project, $user] = $this->makeContext();
        $this->makeApplication($branch, $project, 'Konsumen Nama');
        $record = $this->makeRecord($branch, $project, 'Konsumen Nama');

        $this->artisan('dana-talangan:backfill-relations --apply')
            ->expectsOutputToContain('TIDAK DITEMUKAN')
            ->assertExitCode(0);

        $this->assertNull($record->fresh()->consumer_application_id);
    }

    private function makeContext(): array
    {
        $branch = Branch::create(['name' => 'Cabang Backfill', 'code' => 'BFL', 'is_active' => true]);
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Proyek Backfill', 'is_active' => true]);
        $user = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'admin')->value('id'),
            'branch_id' => $branch->id,
        ]);

        return [$branch, $project, $user];
    }

    private function makeApplication(Branch $branch, LeadMaster $project, string $name, ?string $transactionId = null, ?Kavling $kavling = null): ConsumerApplication
    {
        return ConsumerApplication::create([
            'customer_id' => Customer::create(['name' => $name])->id,
            'branch_id' => $branch->id,
            'project_id' => $project->id,
            'kavling_id' => $kavling?->id,
            'id_transaksi' => $transactionId,
            'application_status' => 'draft',
            'transaction_status' => 'LANJUT',
        ]);
    }

    private function makeRecord(Branch $branch, LeadMaster $project, string $name, array $attributes = []): DanaTalangan
    {
        return DanaTalangan::create(array_merge([
            'tanggal' => '2026-11-01',
            'nama_konsumen' => $name,
            'project_name' => $project->project_name,
            'branch_id' => $branch->id,
            'project_id' => $project->id,
            'created_by' => User::query()->latest('id')->value('id'),
        ], $attributes));
    }
}
