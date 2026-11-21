<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ConsumerKavlingHolderAudit extends Command
{
    protected $signature = 'consumer:kavling-holder-audit';

    protected $description = 'Melaporkan kavling dengan lebih dari satu assignment aktif atau terjual yang belum dilepas.';

    public function handle(): int
    {
        $duplicates = DB::table('consumer_kavling_assignments')
            ->select('kavling_id', DB::raw('COUNT(*) as holder_count'))
            ->whereIn('assignment_status', ['active', 'sold'])
            ->whereNull('released_at')
            ->groupBy('kavling_id')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('kavling_id')
            ->get();

        $this->table(['kavling_id', 'jumlah_holder'], $duplicates->map(fn (object $row): array => [$row->kavling_id, $row->holder_count])->all());
        $this->line('Kavling perlu ditinjau: '.$duplicates->count());

        return $duplicates->isEmpty() ? self::SUCCESS : self::FAILURE;
    }
}
