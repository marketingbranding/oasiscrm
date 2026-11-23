<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\ConsumerApplication;
use App\Models\DanaTalangan;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class DanaTalanganBackfillRelations extends Command
{
    protected $signature = 'dana-talangan:backfill-relations {--apply : Terapkan hanya hasil PASTI} {--confirm= : Terapkan KANDIDAT yang sudah dikonfirmasi, contoh 12,15}';

    protected $description = 'Menilai dan, bila diminta, menerapkan relasi Dana Talangan ke Konsumen dan Kavling.';

    public function handle(): int
    {
        $confirmedIds = collect(explode(',', (string) $this->option('confirm')))
            ->map(fn (string $id): string => trim($id))
            ->filter(fn (string $id): bool => ctype_digit($id))
            ->map(fn (string $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $apply = (bool) $this->option('apply');
        $confirm = $confirmedIds !== [];
        $rows = DanaTalangan::query()->whereNull('consumer_application_id')->with('project')->orderBy('id')->get();
        $summary = ['PASTI' => 0, 'KANDIDAT' => 0, 'PERLU DITINJAU' => 0, 'TIDAK DITEMUKAN' => 0];
        $rejected = false;
        $seenConfirmedIds = [];

        foreach ($rows as $record) {
            [$category, $reason, $application] = $this->match($record);
            $summary[$category]++;
            $action = $apply ? '--apply' : ($confirm ? '--confirm' : 'dry-run');
            $written = false;

            if ($apply && $category === 'PASTI' && $application !== null) {
                $this->writeRelation($record, $application);
                $written = true;
            } elseif ($confirm && in_array($record->id, $confirmedIds, true)) {
                $seenConfirmedIds[] = $record->id;
                if ($category === 'KANDIDAT' && $application !== null) {
                    $this->writeRelation($record, $application);
                    $written = true;
                } else {
                    $reason .= ' --confirm ditolak: hanya boleh untuk KANDIDAT.';
                    $rejected = true;
                }
            }

            if ($written || ($confirm && in_array($record->id, $confirmedIds, true))) {
                $this->audit($record, $category, $application, $action, $written, $reason);
            }
            $displayCategory = $rejected && $confirm && in_array($record->id, $confirmedIds, true) && ! $written ? 'DITOLAK' : $category;
            $this->line(sprintf('#%d | %s | %s%s', $record->id, $displayCategory, $reason, $written ? ' | DITERAPKAN' : ''));
        }

        foreach ($confirmedIds as $confirmedId) {
            if (in_array($confirmedId, $seenConfirmedIds, true)) {
                continue;
            }

            $rejected = true;
            $reason = 'ID tidak ada, sudah tertaut, atau tidak termasuk hasil backfill.';
            $this->line('#'.$confirmedId.' | DITOLAK | '.$reason);
            ActivityLog::query()->create([
                'causer_id' => null,
                'subject_type' => DanaTalangan::class,
                'subject_id' => $confirmedId,
                'event' => 'relation_backfill_--confirm',
                'description' => 'Konfirmasi backfill relasi Dana Talangan ditolak.',
                'properties' => ['actor_type' => 'console', 'dana_talangan_id' => $confirmedId, 'category' => null, 'written' => false, 'reason' => $reason],
            ]);
        }

        foreach ($summary as $category => $count) {
            $this->line($category.': '.$count);
        }

        return $rejected ? self::FAILURE : self::SUCCESS;
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
                $query->where(function ($query) use ($record): void {
                    $query->whereNotNull('id_transaksi')->where('id_transaksi', $record->id_transaksi);
                })->when($record->id_psjb, function ($query) use ($record): void {
                    $query->orWhereHas('psjbs', fn ($psjbs) => $psjbs->whereNotNull('id_psjb')->where('id_psjb', $record->id_psjb));
                });
            })
            ->get();

        if ($strongMatches->count() === 1) {
            return ['PASTI', 'ID Transaksi/PSJB cocok dalam cabang dan proyek.', $strongMatches->first()];
        }

        if ($strongMatches->count() > 1) {
            return ['PERLU DITINJAU', 'ID Transaksi/PSJB menunjuk aplikasi berbeda atau lebih dari satu aplikasi.', null];
        }

        if (blank($record->kav) || blank($record->nama_konsumen)) {
            return ['TIDAK DITEMUKAN', 'Data kavling atau nama konsumen tidak lengkap.', null];
        }

        $normalizedKav = $this->normalizeKavling($record->kav);
        $matches = ConsumerApplication::query()
            ->where('branch_id', $record->branch_id)
            ->where('project_id', $record->project_id)
            ->whereHas('customer', fn ($query) => $query->whereRaw('lower(name) = ?', [Str::lower(trim($record->nama_konsumen))]))
            ->whereHas('kavling', function ($query) use ($record, $normalizedKav): void {
                $query->where('project_id', $record->project_id)
                    ->where(function ($query) use ($normalizedKav): void {
                        $query->whereRaw("upper(replace(replace(trim(kavling_code), '-', ''), ' ', '')) = ?", [$normalizedKav])
                            ->orWhereRaw("upper(replace(replace(trim(name), '-', ''), ' ', '')) = ?", [$normalizedKav]);
                    });
            })
            ->with('kavling')
            ->get();

        if ($matches->count() === 1) {
            return ['KANDIDAT', 'Kavling dan nama cocok setelah normalisasi; perlu konfirmasi manusia.', $matches->first()];
        }

        if ($matches->count() > 1) {
            return ['PERLU DITINJAU', 'Kavling dan nama menghasilkan lebih dari satu aplikasi.', null];
        }

        return ['TIDAK DITEMUKAN', 'Tidak ada kecocokan kuat atau kandidat kavling dan nama.', null];
    }

    private function writeRelation(DanaTalangan $record, ConsumerApplication $application): void
    {
        $record->forceFill([
            'consumer_application_id' => $application->id,
            'kavling_id' => $application->kavling_id,
        ])->save();
    }

    private function audit(DanaTalangan $record, string $category, ?ConsumerApplication $application, string $action, bool $written, string $reason): void
    {
        ActivityLog::query()->create([
            'causer_id' => null,
            'subject_type' => DanaTalangan::class,
            'subject_id' => $record->id,
            'event' => 'relation_backfill_'.$action,
            'description' => 'Backfill relasi Dana Talangan '.$action.'.',
            'properties' => [
                'actor_type' => 'console',
                'dana_talangan_id' => $record->id,
                'consumer_application_id' => $application?->id,
                'kavling_id' => $application?->kavling_id,
                'category' => $category,
                'written' => $written,
                'reason' => $reason,
            ],
        ]);
    }

    private function normalizeKavling(string $value): string
    {
        return Str::upper((string) preg_replace('/[\s-]+/', '', trim($value)));
    }
}
