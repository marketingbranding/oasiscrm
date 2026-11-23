<?php

namespace App\Console\Commands;

use App\Models\ConsumerApplication;
use App\Models\DanaTalangan;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class DanaTalanganBackfillRelations extends Command
{
    protected $signature = 'dana-talangan:backfill-relations {--apply : Terapkan hanya hasil PASTI}';

    protected $description = 'Menilai dan, bila diminta, menerapkan relasi Dana Talangan ke Konsumen dan Kavling.';

    public function handle(): int
    {
        $rows = DanaTalangan::query()->whereNull('consumer_application_id')->with('project')->orderBy('id')->get();
        $summary = ['PASTI' => 0, 'KANDIDAT' => 0, 'PERLU DITINJAU' => 0, 'TIDAK DITEMUKAN' => 0];

        foreach ($rows as $record) {
            [$category, $reason, $application] = $this->match($record);
            $summary[$category]++;

            if ($category === 'PASTI' && $this->option('apply')) {
                $record->forceFill([
                    'consumer_application_id' => $application->id,
                    'kavling_id' => $application->kavling_id,
                ])->save();
            }

            $this->line(sprintf('#%d | %s | %s', $record->id, $category, $reason));
        }

        foreach ($summary as $category => $count) {
            $this->line($category.': '.$count);
        }

        return self::SUCCESS;
    }

    /**
     * @return array{0:string,1:string,2:?ConsumerApplication}
     */
    private function match(DanaTalangan $record): array
    {
        $strongMatches = ConsumerApplication::query()
            ->where('branch_id', $record->branch_id)
            ->where('project_id', $record->project_id)
            ->where(function ($query) use ($record): void {
                $query->where('id_transaksi', $record->id_transaksi)
                    ->when($record->id_psjb, function ($query) use ($record): void {
                        $query->orWhereHas('psjbs', fn ($psjbs) => $psjbs->where('id_psjb', $record->id_psjb));
                    });
            })
            ->get();

        if ($strongMatches->count() === 1) {
            return ['PASTI', 'ID Transaksi/PSJB cocok dalam cabang dan proyek.', $strongMatches->first()];
        }

        if ($strongMatches->count() > 1) {
            return ['PERLU DITINJAU', 'ID Transaksi/PSJB menghasilkan lebih dari satu aplikasi.', null];
        }

        if (blank($record->kav) || blank($record->nama_konsumen)) {
            return ['TIDAK DITEMUKAN', 'Data kavling atau nama konsumen tidak lengkap.', null];
        }

        $matches = ConsumerApplication::query()
            ->where('branch_id', $record->branch_id)
            ->where('project_id', $record->project_id)
            ->whereHas('customer', fn ($query) => $query->whereRaw('lower(name) = ?', [Str::lower(trim($record->nama_konsumen))]))
            ->whereHas('kavling', function ($query) use ($record): void {
                $query->where('project_id', $record->project_id)
                    ->where(function ($query) use ($record): void {
                        $query->where('kavling_code', $record->kav)->orWhere('name', $record->kav);
                    });
            })
            ->with('kavling')
            ->get();

        if ($matches->count() === 1) {
            return ['KANDIDAT', 'Kavling dan nama cocok; perlu tinjauan sebelum diterapkan.', $matches->first()];
        }

        if ($matches->count() > 1) {
            return ['PERLU DITINJAU', 'Kavling dan nama menghasilkan lebih dari satu aplikasi.', null];
        }

        return ['TIDAK DITEMUKAN', 'Tidak ada kecocokan kuat atau kandidat kavling dan nama.', null];
    }
}
