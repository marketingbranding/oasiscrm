<?php

namespace App\Console\Commands;

use App\Services\OrganizationCutoverHealthService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('organization:cutover-health
    {--user=* : ID pengguna yang dipilih untuk pemeriksaan}
    {--controlled-evidence : Mengharuskan bukti controlled yang lengkap}
    {--json : Keluarkan JSON machine-readable}')]
#[Description('Memeriksa kesiapan cutover graph organisasi tanpa mengubah data.')]
class OrganizationCutoverHealth extends Command
{
    public function handle(OrganizationCutoverHealthService $health): int
    {
        try {
            $userIds = collect((array) $this->option('user'))->map(function (mixed $id): int {
                if (! is_scalar($id) || filter_var($id, FILTER_VALIDATE_INT) === false || (int) $id < 1) {
                    throw new InvalidArgumentException('Opsi --user harus berupa ID positif.');
                }

                return (int) $id;
            })->unique()->sort()->values()->all();
            $report = $health->check($userIds === [] ? null : $userIds, (bool) $this->option('controlled-evidence'));
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('Status: '.$report['status']);
        $this->table(
            ['Metrik', 'Nilai'],
            collect($report['parity'])->map(fn (mixed $value, string $key): array => [$key, $value])->values()->all(),
        );
        if ($report['blockers'] !== []) {
            $this->warn('Blockers: '.implode(', ', $report['blockers']));
        }

        return self::SUCCESS;
    }
}
