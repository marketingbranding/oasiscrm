<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\User;
use App\Services\SalesLeadSyncService;
use App\Services\WorkspaceAccessService;
use Illuminate\Console\Command;

class SyncSalesLead extends Command
{
    protected $signature = 'sales-lead:sync {--branch= : ID cabang yang akan disinkronkan} {--user= : ID Sales aktif untuk sinkronisasi personal (opsional)}';

    protected $description = 'Tarik dan rekonsiliasi tab lead Buku Saku Sales per cabang (atau per Sales saat --user diberikan)';

    public function handle(WorkspaceAccessService $workspaceAccess): int
    {
        if (! config('services.google_sheets.sales_lead_sync_enabled')) {
            $this->warn('Sinkronisasi Google Sheets Lead Sales sedang dinonaktifkan.');

            return self::SUCCESS;
        }

        $results = $this->results(app(SalesLeadSyncService::class), $workspaceAccess);
        if ($results === []) {
            $this->warn('Tidak ada cabang aktif dengan sheet_id untuk disinkronkan.');

            return self::SUCCESS;
        }

        $failed = false;
        foreach ($results as $result) {
            if ($result['ok']) {
                $this->info($result['branch'].': OK');
            } else {
                $failed = true;
                $this->error($result['branch'].': FAILED, '.$result['message']);
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function results(SalesLeadSyncService $service, WorkspaceAccessService $workspaceAccess): array
    {
        $user = $this->user();
        $branchId = $this->option('branch') ? (int) $this->option('branch') : null;
        if ($user !== null) {
            $branch = $this->validateUserBranch($user, $branchId, $workspaceAccess);

            return [$service->sync($branch, $user)];
        }

        return $service->syncAll($branchId);
    }

    private function user(): ?User
    {
        $userId = $this->option('user');
        if (! $userId) {
            return null;
        }
        $user = User::query()->whereKey((int) $userId)->where('is_active', true)->first();
        if ($user === null || ! $user->isSales()) {
            $this->error('Pengguna tidak ditemukan atau bukan Sales aktif.');

            throw new \RuntimeException('Invalid --user.');
        }

        return $user;
    }

    private function validateUserBranch(User $user, ?int $branchId, WorkspaceAccessService $workspaceAccess): Branch
    {
        $branchId ??= $user->branch_id;
        $branch = Branch::query()->whereKey($branchId)->where('is_active', true)->whereNotNull('sheet_id')->where('sheet_id', '!=', '')->first();
        if ($branch === null) {
            $this->error('Cabang tidak ditemukan atau tidak memiliki sheet.');

            throw new \RuntimeException('Invalid branch for --user.');
        }
        if ((int) $user->branch_id !== (int) $branch->id) {
            $this->error('Sales tidak berada pada cabang yang diminta.');

            throw new \RuntimeException('User not in branch.');
        }
        if (! $workspaceAccess->accessibleProjectsQuery($user)->where('branch_id', $branch->id)->exists()) {
            $this->error('Sales tidak memiliki penugasan proyek aktif pada cabang tersebut.');

            throw new \RuntimeException('No active project assignment.');
        }

        return $branch;
    }
}
