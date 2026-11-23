<?php

namespace App\Console\Commands;

use App\Models\ConsumerApplication;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ConsumerWorkspaceInputAudit extends Command
{
    protected $signature = 'consumer:workspace-input-audit {--days=30 : Jumlah hari yang diperiksa}';

    protected $description = 'Melaporkan input Workspace Konsumen manual per cabang tanpa mengubah data.';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $since = now()->subDays($days);
        $applications = DB::table('consumer_applications as application')
            ->leftJoin('activity_log as log', function ($join): void {
                $join->on('application.id', '=', 'log.subject_id')->where('log.subject_type', ConsumerApplication::class);
            })
            ->join('branches as branch', 'branch.id', '=', 'application.branch_id')
            ->where(function ($query) use ($since): void {
                $query->where('application.created_at', '>=', $since)
                    ->orWhere('log.created_at', '>=', $since);
            })
            ->where(function ($query): void {
                $query->whereIn('log.event', [
                    'consumer_slik_recorded', 'consumer_psjb_recorded', 'consumer_bank_stage_recorded', 'consumer_bank_attempt_created',
                    'consumer_ppjb_recorded', 'consumer_akad_recorded', 'consumer_ready_100_recorded', 'consumer_bast_recorded',
                    'consumer_mundur', 'consumer_kavling_moved', 'consumer_warranty_recorded', 'consumer_issue_created', 'consumer_issue_resolved',
                ])->orWhere(function ($manual): void {
                    $manual->whereNull('log.event')->where(function ($application): void {
                        $application->whereIn('application.entry_mode', ['new', 'manual'])
                            ->orWhere('application.current_process_source', 'operational');
                    });
                });
            })
            ->groupBy('branch.id', 'branch.code', 'branch.name')
            ->select('branch.id', 'branch.code', 'branch.name')
            ->selectRaw('COUNT(DISTINCT application.id) as application_count')
            ->get()
            ->keyBy('id');

        $nups = DB::table('consumer_nups as nup')
            ->join('branches as branch', 'branch.id', '=', 'nup.branch_id')
            ->where('nup.created_at', '>=', $since)
            ->where(function ($query): void {
                $query->where('nup.source', 'manual')->orWhereNull('nup.source');
            })
            ->groupBy('branch.id', 'branch.code', 'branch.name')
            ->select('branch.id', 'branch.code', 'branch.name')
            ->selectRaw('COUNT(nup.id) as nup_count')
            ->get();

        foreach ($nups as $nup) {
            $row = $applications->get($nup->id);
            if ($row === null) {
                $applications->put($nup->id, (object) [
                    'id' => $nup->id,
                    'code' => $nup->code,
                    'name' => $nup->name,
                    'application_count' => 0,
                    'nup_count' => $nup->nup_count,
                ]);
            } else {
                $row->nup_count = $nup->nup_count;
            }
        }

        $this->line('Rentang: '.$since->toDateTimeString().' sampai sekarang. Tidak ada data yang diubah.');
        $this->table(
            ['branch_id', 'kode', 'cabang', 'consumer_applications', 'consumer_nups'],
            $applications->sortBy('code')->map(fn (object $row): array => [
                $row->id,
                $row->code,
                $row->name,
                $row->application_count,
                $row->nup_count ?? 0,
            ])->values()->all(),
        );

        return self::SUCCESS;
    }
}
