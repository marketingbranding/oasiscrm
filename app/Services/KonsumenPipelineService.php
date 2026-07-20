<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\KonsumenProgressSheetRow;
use Carbon\Carbon;
use Illuminate\Support\Str;

class KonsumenPipelineService
{
    public const STAGES = [
        'bi_checking' => 'BI Checking',
        'PSJB' => 'PSJB',
        'pemberkasan' => 'Pemberkasan',
        'proses_bank' => 'Proses Bank',
        'ppjb_dev' => 'PPJB Dev',
        'akad' => 'Akad',
        'bast' => 'BAST',
    ];

    public const SHEETS = ['data_konsumen', 'bi_checking', 'PSJB', 'pemberkasan', 'proses_bank', 'ppjb_dev', 'akad', 'bast'];

    public const STAGE_ALIASES = [
        'bi checking' => 'bi_checking',
        'bi_checking' => 'bi_checking',
        'psjb' => 'PSJB',
        'pemberkasan' => 'pemberkasan',
        'proses bank' => 'proses_bank',
        'proses_bank' => 'proses_bank',
        'ppjb dev' => 'ppjb_dev',
        'ppjb_dev' => 'ppjb_dev',
        'akad' => 'akad',
        'akad kredit' => 'akad',
        'bast' => 'bast',
    ];

    public const STAGE_DATE_FIELDS = [
        'bi_checking' => ['tanggal_bi_checking', 'tgl_bi_checking'],
        'PSJB' => ['tanggal_psjb', 'tgl_psjb'],
        'pemberkasan' => ['tanggal_pemberkasan', 'tgl_pemberkasan'],
        'proses_bank' => ['tanggal_proses_bank', 'tgl_proses_bank'],
        'ppjb_dev' => ['tanggal_ppjb_dev', 'tgl_ppjb_dev'],
        'akad' => ['tanggal_akad', 'tgl_akad'],
        'bast' => ['tanggal_bast', 'tgl_bast'],
    ];

    public function stages(): array
    {
        return self::STAGES;
    }

    public function canonicalStage(?string $stage): ?string
    {
        $key = Str::lower(trim((string) $stage));
        if ($key === '') {
            return null;
        }

        return self::STAGE_ALIASES[$key] ?? null;
    }

    public function buildPipeline(Branch $branch, ?Carbon $dateFrom = null, ?Carbon $dateTo = null): array
    {
        $rowsBySheet = $this->rowsBySheet($branch);
        $customers = $this->customerIndex($rowsBySheet['data_konsumen'] ?? []);
        $pipeline = array_fill_keys(array_keys(self::STAGES), []);
        $seen = [];

        foreach (array_reverse(array_keys(self::STAGES)) as $stage) {
            foreach (($rowsBySheet[$stage] ?? []) as $row) {
                $idKavling = $this->idKavling($row);
                if ($idKavling === '' || isset($seen[$idKavling]) || ! isset($customers[$idKavling])) {
                    continue;
                }

                if (! $this->rowMatchesDate($stage, $row, $dateFrom, $dateTo)) {
                    continue;
                }

                $seen[$idKavling] = true;
                $customer = $customers[$idKavling];
                $pipeline[$stage][] = [
                    'id_kavling' => $idKavling,
                    'kavling' => $idKavling,
                    'nama' => $customer['nama_konsumen'],
                    'nama_konsumen' => $customer['nama_konsumen'],
                    'project_name' => $customer['project_name'],
                    'current_stage' => $stage,
                    'current_stage_label' => self::STAGES[$stage],
                    'branch' => $branch->name,
                    'source_module' => 'Konsumen Progress',
                    'source_sheet' => $stage,
                ];
            }
        }

        return $pipeline;
    }

    public function customersForStage(Branch $branch, ?string $stage, ?Carbon $dateFrom = null, ?Carbon $dateTo = null): array
    {
        $pipeline = $this->buildPipeline($branch, $dateFrom, $dateTo);
        if ($stage === null) {
            return collect($pipeline)->flatten(1)->values()->all();
        }

        return $pipeline[$stage] ?? [];
    }

    public function countByStage(Branch $branch, ?string $stage = null, ?Carbon $dateFrom = null, ?Carbon $dateTo = null): array
    {
        $pipeline = $this->buildPipeline($branch, $dateFrom, $dateTo);
        $counts = collect($pipeline)->map(fn ($items) => count($items))->all();

        return [
            'count' => $stage ? ($counts[$stage] ?? 0) : array_sum($counts),
            'by_stage' => $counts,
        ];
    }

    public function search(Branch $branch, string $term, int $limit = 10): array
    {
        $needle = Str::lower(trim($term));
        if ($needle === '') {
            return [];
        }

        return collect($this->buildPipeline($branch))
            ->flatten(1)
            ->filter(fn (array $item) => Str::contains(Str::lower($item['nama_konsumen'].' '.$item['id_kavling'].' '.$item['project_name']), $needle))
            ->take($limit)
            ->values()
            ->all();
    }

    public function currentCustomer(Branch $branch, string $idKavling): ?array
    {
        return collect($this->buildPipeline($branch))
            ->flatten(1)
            ->first(fn (array $item) => $item['id_kavling'] === trim($idKavling));
    }

    private function rowsBySheet(Branch $branch): array
    {
        $rowsBySheet = [];
        $rows = KonsumenProgressSheetRow::query()
            ->where('branch_id', $branch->id)
            ->whereIn('sheet_name', self::SHEETS)
            ->orderBy('id')
            ->get(['sheet_name', 'row_data']);

        foreach ($rows as $row) {
            $rowsBySheet[$row->sheet_name][] = $row->row_data ?? [];
        }

        return $rowsBySheet;
    }

    private function customerIndex(array $rows): array
    {
        $customers = [];
        foreach ($rows as $row) {
            $idKavling = $this->idKavling($row);
            if ($idKavling === '') {
                continue;
            }

            $customers[$idKavling] = [
                'nama_konsumen' => $this->value($row, ['nama_konsumen', 'nama konsumen', 'nama']) ?: null,
                'project_name' => $this->value($row, ['project_name', 'proyek', 'project']) ?: null,
            ];
        }

        return array_filter($customers, fn ($row) => filled($row['nama_konsumen']));
    }

    private function idKavling(array $row): string
    {
        return trim((string) $this->value($row, ['id_kavling', 'id kavling', 'kavling', 'kav']));
    }

    private function value(array $row, array $keys): ?string
    {
        $normalized = [];
        foreach ($row as $key => $value) {
            $normalized[Str::lower(trim((string) $key))] = is_scalar($value) ? trim((string) $value) : null;
        }

        foreach ($keys as $key) {
            $value = $normalized[Str::lower($key)] ?? null;
            if (filled($value)) {
                return $value;
            }
        }

        return null;
    }

    private function rowMatchesDate(string $stage, array $row, ?Carbon $from, ?Carbon $to): bool
    {
        if (! $from && ! $to) {
            return true;
        }

        foreach (self::STAGE_DATE_FIELDS[$stage] ?? [] as $field) {
            $date = $this->dateOrNull($this->value($row, [$field]));
            if ($date && (! $from || $date->gte($from)) && (! $to || $date->lte($to))) {
                return true;
            }
        }

        return false;
    }

    private function dateOrNull(?string $value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
