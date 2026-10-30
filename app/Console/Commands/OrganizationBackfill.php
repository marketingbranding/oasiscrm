<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\User;
use App\Services\OrganizationBackfillService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('organization:backfill
    {--dry-run : Hanya menghitung proposal tanpa menulis data}
    {--write : Menulis hanya pada lingkungan terisolasi yang dikonfirmasi}
    {--confirm-isolated : Mengonfirmasi lingkungan terisolasi}
    {--user=* : ID pengguna yang dipilih secara eksplisit}
    {--branch= : ID cabang yang dipilih secara eksplisit}')]
#[Description('Memeriksa dan melakukan backfill idempoten dari struktur organisasi legacy.')]
class OrganizationBackfill extends Command
{
    public function handle(OrganizationBackfillService $backfill): int
    {
        $write = (bool) $this->option('write');
        if ($write && (bool) $this->option('dry-run')) {
            $this->error('Gunakan tepat satu dari --dry-run atau --write.');

            return self::FAILURE;
        }
        if (! $write && ! (bool) $this->option('dry-run')) {
            $this->error('Gunakan --dry-run untuk diagnostik atau --write dengan --confirm-isolated dan pilihan pengguna/cabang.');

            return self::FAILURE;
        }

        try {
            $userIds = collect((array) $this->option('user'))
                ->map(function (mixed $id): int {
                    if (! is_scalar($id) || filter_var($id, FILTER_VALIDATE_INT) === false || (int) $id < 1) {
                        throw new InvalidArgumentException('Opsi --user harus berupa ID positif.');
                    }

                    return (int) $id;
                })
                ->unique()
                ->values();
            $branchId = $this->option('branch');
            if (filled($branchId)) {
                if (! is_scalar($branchId) || filter_var($branchId, FILTER_VALIDATE_INT) === false || (int) $branchId < 1) {
                    throw new InvalidArgumentException('Opsi --branch harus berupa ID positif.');
                }
                $branch = Branch::query()->whereKey((int) $branchId)->where('is_active', true)->first();
                if ($branch === null) {
                    throw new InvalidArgumentException('Opsi --branch harus menunjuk cabang aktif.');
                }
                if ($userIds->isNotEmpty() && User::query()->whereIn('id', $userIds)->where('branch_id', '!=', $branch->id)->exists()) {
                    throw new InvalidArgumentException('Opsi --user harus berada pada cabang yang dipilih.');
                }
                $userIds = $userIds->merge(User::query()->where('branch_id', $branch->id)->pluck('id'));
            }
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($write || $userIds->isNotEmpty()) {
            $report = $backfill->runControlled($userIds->all(), $write, (bool) $this->option('confirm-isolated'));
        } else {
            $report = $backfill->run(true);
        }
        $this->table(
            ['Metrik', 'Nilai'],
            collect($report)->except('dry_run', 'details', 'proposals')->map(function (mixed $value, string $key): array {
                return [$key, is_scalar($value) || $value === null ? $value : json_encode($value, JSON_UNESCAPED_SLASHES)];
            })->values()->all(),
        );

        if ($report['details'] !== [] || $report['proposals'] !== []) {
            $this->warn('Detail konflik/anomali:');
            $this->line(json_encode([
                'proposals' => $report['proposals'],
                'details' => $report['details'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        return ($report['safety']['allowed'] ?? true) ? self::SUCCESS : self::FAILURE;
    }
}
