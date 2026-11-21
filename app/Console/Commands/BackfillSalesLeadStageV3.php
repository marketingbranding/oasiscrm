<?php

namespace App\Console\Commands;

use App\Enums\SalesLeadStatus;
use App\Models\SalesLead;
use Illuminate\Console\Command;

class BackfillSalesLeadStageV3 extends Command
{
    protected $signature = 'sales-lead:stage-v3-backfill {--dry-run : Hanya menghitung proposal tanpa menulis data}';

    protected $description = 'Melaporkan atau mengisi tahap Lead v3 dari data historis tanpa membuat riwayat palsu.';

    public function handle(): int
    {
        if (! $this->option('dry-run')) {
            $this->error('Gunakan --dry-run. Backfill produksi dijalankan lewat migrasi setelah laporan ditinjau.');

            return self::FAILURE;
        }

        $counts = [];
        $review = [];
        SalesLead::query()->with('statusHistories')->orderBy('id')->chunkById(500, function ($leads) use (&$counts, &$review): void {
            foreach ($leads as $lead) {
                $legacy = $this->legacyStatus($lead);
                $stage = $this->stage($lead);
                $key = $legacy.' -> '.$stage;
                $counts[$key] = ($counts[$key] ?? 0) + 1;

                if ($lead->akad_at !== null && $lead->utj_at === null) {
                    $review[] = ['lead_id' => $lead->id, 'case' => 'akad_without_utj'];
                }
                if ($lead->documents_completed_at !== null && $lead->utj_at === null) {
                    $review[] = ['lead_id' => $lead->id, 'case' => 'documents_without_utj'];
                }
                $hasLegacyHistory = $lead->statusHistories->isNotEmpty();
                if ($lead->lead_stage === null && ! $hasLegacyHistory && $lead->contacted_at === null && $lead->met_at === null && $lead->surveyed_at === null && $lead->utj_at === null) {
                    $review[] = ['lead_id' => $lead->id, 'case' => 'without_stage'];
                }
            }
        });

        $this->table(['Tahap lama terdeteksi', 'Tahap v3', 'Jumlah'], collect($counts)->map(function (int $count, string $mapping): array {
            [$legacy, $stage] = explode(' -> ', $mapping, 2);

            return [$legacy, $stage, $count];
        })->values()->all());
        foreach ($counts as $mapping => $count) {
            $this->line($mapping.': '.$count);
        }
        $this->line('Kasus perlu tinjau: '.count($review));
        foreach ($review as $item) {
            $this->line($item['case'].' lead_id='.$item['lead_id']);
        }

        return self::SUCCESS;
    }

    private function legacyStatus(SalesLead $lead): string
    {
        return $lead->current_status?->value ?? 'null';
    }

    private function stage(SalesLead $lead): string
    {
        if ($lead->gugur_at !== null || $lead->lead_stage === 'gugur') {
            return 'gugur';
        }
        if ($lead->utj_at !== null || ($lead->current_status?->precedence() ?? -1) >= SalesLeadStatus::Utj->precedence()) {
            return 'utj';
        }
        if ($lead->surveyed_at !== null) {
            return 'survey';
        }
        if ($lead->met_at !== null) {
            return 'tatap_muka';
        }
        if ($lead->contacted_at !== null) {
            return 'dihubungi';
        }

        return 'lead_baru';
    }
}
