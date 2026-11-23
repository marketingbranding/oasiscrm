<?php

namespace Tests\Feature;

use App\Models\BranchMigrationState;
use App\Models\ConsumerApplication;
use App\Models\ConsumerIssue;
use App\Models\Customer;
use App\Models\Kavling;
use App\Models\Role;
use App\Models\User;
use App\Services\ConsumerApplicationLifecycleService;
use App\Services\ConsumerEntryService;
use App\Services\ConsumerKavlingLifecycleService;
use App\Services\ConsumerNupService;
use App\Services\ConsumerOperationalService;
use App\Services\ConsumerProcessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConsumerWorkspaceInputGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_br32_workspace_input_audit_reports_recent_manual_activity_without_mutation(): void
    {
        $application = ConsumerApplication::factory()->create();
        DB::table('activity_log')->insert([
            'causer_id' => null,
            'subject_type' => ConsumerApplication::class,
            'subject_id' => $application->id,
            'event' => 'consumer_ppjb_recorded',
            'description' => 'PPJB dicatat.',
            'properties' => json_encode(['source' => 'manual']),
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);
        $countBefore = ConsumerApplication::query()->count();

        DB::table('consumer_nups')->insertGetId([
            'customer_id' => $application->customer_id,
            'branch_id' => $application->branch_id,
            'project_id' => $application->project_id,
            'nup_number' => 'AUDIT-NUP',
            'registered_at' => today()->toDateString(),
            'source' => 'manual',
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);

        $this->artisan('consumer:workspace-input-audit', ['--days' => 30])
            ->expectsOutputToContain($application->branch->code)
            ->expectsOutputToContain('1')
            ->assertExitCode(0);

        $this->artisan('consumer:workspace-input-audit', ['--days' => 1])
            ->expectsOutput('Rentang: '.now()->subDay()->toDateTimeString().' sampai sekarang. Tidak ada data yang diubah.')
            ->assertExitCode(0);

        $this->assertSame($countBefore, ConsumerApplication::query()->count());
    }

    public function test_br32_sheet_branch_rejects_every_workspace_consumer_mutation_without_unlocking_kavling(): void
    {
        $application = ConsumerApplication::factory()->create();
        DB::table('branch_migration_states')->where('branch_id', $application->branch_id)->update(['state' => BranchMigrationState::STATE_SHEET]);
        $actor = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'superadmin')->value('id'),
            'branch_id' => $application->branch_id,
        ]);
        $kavling = Kavling::create(['project_id' => $application->project_id, 'kavling_code' => 'GUARD-01', 'name' => 'GUARD-01']);
        app(ConsumerKavlingLifecycleService::class)->assign($application, $kavling);
        $issue = ConsumerIssue::create(['consumer_application_id' => $application->id, 'process_key' => 'bast', 'description' => 'Kendala lama', 'status' => 'open', 'opened_at' => now()]);
        $before = [
            'application' => $application->fresh()->getAttributes(),
            'kavling' => $kavling->fresh()->getAttributes(),
            'applications' => ConsumerApplication::query()->count(),
            'issues' => ConsumerIssue::query()->count(),
        ];

        $operations = [
            'entry' => fn () => app(ConsumerEntryService::class)->create(['branch_id' => $application->branch_id, 'project_id' => $application->project_id, 'customer_id' => $application->customer_id], $actor),
            'nup' => fn () => app(ConsumerNupService::class)->create(['branch_id' => $application->branch_id, 'project_id' => $application->project_id, 'nup_number' => 'GUARD-NUP', 'registered_at' => today()->toDateString()], $actor),
            'pemberkasan' => fn () => app(ConsumerOperationalService::class)->recordPemberkasan($application, [], $actor),
            'ganti_bank' => fn () => app(ConsumerOperationalService::class)->gantiBank($application, [], $actor),
            'sp3k' => fn () => app(ConsumerOperationalService::class)->recordSp3k($application, [], $actor),
            'ganti_konsumen' => fn () => app(ConsumerApplicationLifecycleService::class)->gantiKonsumen($application, Customer::factory()->create(), $actor),
            'bi' => fn () => app(ConsumerOperationalService::class)->recordBiChecking($application, [], $actor),
            'psjb' => fn () => app(ConsumerOperationalService::class)->recordPsjb($application, [], $actor),
            'bank' => fn () => app(ConsumerOperationalService::class)->recordProsesBank($application, [], $actor),
            'ready100' => fn () => app(ConsumerOperationalService::class)->recordReady100($application, [], $actor),
            'ppjb' => fn () => app(ConsumerOperationalService::class)->recordPpjb($application, [], $actor),
            'akad' => fn () => app(ConsumerOperationalService::class)->recordAkad($application, [], $actor, app(ConsumerKavlingLifecycleService::class)),
            'bast' => fn () => app(ConsumerOperationalService::class)->recordBast($application, [], $actor, app(ConsumerKavlingLifecycleService::class)),
            'mundur' => fn () => app(ConsumerApplicationLifecycleService::class)->mundur($application, 'Mundur atas permintaan konsumen', $actor),
            'pindah_kavling' => fn () => app(ConsumerApplicationLifecycleService::class)->pindahKavling($application, $kavling, $actor),
            'event' => fn () => app(ConsumerOperationalService::class)->appendTransactionEvent($application, 'KOREKSI_DATA', $actor),
            'garansi' => fn () => app(ConsumerProcessService::class)->recordWarranty($application, [], $actor),
            'kendala' => fn () => app(ConsumerProcessService::class)->createIssue($application, [], $actor),
            'selesaikan_kendala' => fn () => app(ConsumerProcessService::class)->resolveIssue($issue, ['resolution' => 'Selesai'], $actor),
        ];

        foreach ($operations as $name => $operation) {
            try {
                $operation();
                $this->fail($name.' harus ditolak pada status sheet.');
            } catch (AuthorizationException $exception) {
                $this->assertSame('Cabang ini masih memakai spreadsheet untuk proses penjualan.', $exception->getMessage(), $name);
            }
        }

        $this->assertSame($before['applications'], ConsumerApplication::query()->count());
        $this->assertSame($before['issues'], ConsumerIssue::query()->count());
        $this->assertSame($before['application'], $application->fresh()->getAttributes());
        $this->assertSame($before['kavling'], $kavling->fresh()->getAttributes());
    }
}
