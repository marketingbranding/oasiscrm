<?php

namespace App\Console\Commands;

use App\Models\ConsumerApplication;
use App\Services\ConsumerLeadLinkCarryOverService;
use Illuminate\Console\Command;

class ConsumerLeadLinkCarryOver extends Command
{
    protected $signature = 'consumer-lead-link:carry-over {--dry-run : Hanya melaporkan kandidat tanpa mengubah data}';

    protected $description = 'Menyapu keputusan penautan Lead Sheet/NUP ke ConsumerApplication.';

    public function handle(ConsumerLeadLinkCarryOverService $service): int
    {
        $counts = ['moved' => 0, 'already_moved' => 0, 'conflict' => 0, 'not_found' => 0];
        ConsumerApplication::query()->orderBy('id')->each(function (ConsumerApplication $application) use ($service, &$counts): void {
            if ($this->option('dry-run')) {
                $this->line($application->id.': kandidat carry-over');

                return;
            }
            $result = $service->carryApplication($application);
            $counts[$result] = ($counts[$result] ?? 0) + 1;
        });

        if (! $this->option('dry-run')) {
            $this->table(['Hasil', 'Jumlah'], collect($counts)->map(fn (int $count, string $key): array => [$key, $count])->all());
        }

        return self::SUCCESS;
    }
}
