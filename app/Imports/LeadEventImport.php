<?php

namespace App\Imports;

use App\Models\Branch;
use App\Models\LeadEvent;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Illuminate\Support\Facades\Auth;

class LeadEventImport
{
    private static function parseDate(string $value): ?string
    {
        $value = trim($value);
        if (empty($value)) return null;

        if (is_numeric($value)) {
            $unix = ($value - 25569) * 86400;
            return date('Y-m-d', (int) $unix);
        }

        $formats = ['d M Y', 'd/m/Y', 'Y-m-d', 'd-m-Y', 'd/m/y', 'd F Y'];
        foreach ($formats as $fmt) {
            $dt = \DateTime::createFromFormat($fmt, $value);
            if ($dt) return $dt->format('Y-m-d');
        }

        try {
            return (new \Carbon\Carbon($value))->format('Y-m-d');
        } catch (\Exception $e) {
            return null;
        }
    }

    private static function parseNumeric(string $value): ?float
    {
        $value = trim($value);
        if (empty($value)) return null;
        $value = str_replace(['Rp', '.', ','], ['', '', '.'], $value);
        return is_numeric($value) ? (float) $value : null;
    }

    public static function import(string $filePath, ?int $branchId = null, ?array $preservedParams = []): array
    {
        $imported = 0;
        $errors = [];
        $rowNum = 0;
        $user = Auth::user();

        $reader = IOFactory::createReaderForFile($filePath);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray();

        $hasCabang = false;
        $branchNames = [];
        if (isset($rows[1]) && is_array($rows[1])) {
            $firstDataRow = array_values($rows[1]);
            $firstVal = trim((string) ($firstDataRow[0] ?? ''));
            if (!empty($firstVal) && !is_numeric($firstVal)) {
                $branchNames = Branch::where('is_active', true)->pluck('name')->toArray();
                $hasCabang = in_array($firstVal, $branchNames);
            }
        }

        $now = now();

        foreach ($rows as $cells) {
            $rowNum++;
            if ($rowNum === 1) continue;

            if (!is_array($cells) || count($cells) < 2) {
                continue;
            }

            $cells = array_values($cells);

            $offset = $hasCabang ? 1 : 0;

            $branchFromFile = null;
            if ($hasCabang) {
                $cabangName = trim((string) ($cells[0] ?? ''));
                if (!empty($cabangName) && !empty($branchNames)) {
                    $branchIdx = array_search($cabangName, $branchNames);
                    if ($branchIdx !== false) {
                        $branch = Branch::where('name', $branchNames[$branchIdx])->first();
                        $branchFromFile = $branch ? $branch->id : null;
                    }
                }
            }

            $eventIdRaw = trim($cells[0 + $offset] ?? '');
            $projectName = trim($cells[1 + $offset] ?? '');
            $leadSource = trim($cells[2 + $offset] ?? '');
            $tglMulaiRaw = $cells[3 + $offset] ?? '';
            $tglSelesaiRaw = $cells[4 + $offset] ?? '';
            $anggaranRaw = trim($cells[5 + $offset] ?? '');
            $statusRaw = trim($cells[6 + $offset] ?? '');
            $catatan = trim($cells[7 + $offset] ?? '');

            if (empty($eventIdRaw) && empty($projectName)) {
                $errors[] = "Baris {$rowNum}: Event ID atau Proyek harus diisi.";
                continue;
            }

            $tglMulai = self::parseDate((string) $tglMulaiRaw);
            if (empty($tglMulai)) {
                $errors[] = "Baris {$rowNum}: Tanggal Mulai tidak valid ('{$tglMulaiRaw}').";
                continue;
            }

            $tglSelesai = self::parseDate((string) $tglSelesaiRaw);

            $anggaran = self::parseNumeric($anggaranRaw);
            $status = in_array(strtolower($statusRaw), ['berlangsung', 'selesai']) ? strtolower($statusRaw) : 'berlangsung';

            $resolvedBranchId = $branchFromFile ?? $branchId ?? $user->branch_id ?? 1;

            $data = [
                'branch_id' => $resolvedBranchId,
                'event_id' => $eventIdRaw ?: null,
                'project_name' => $projectName,
                'lead_source' => $leadSource ?: null,
                'start_date' => $tglMulai,
                'end_date' => $tglSelesai,
                'total_budget' => $anggaran !== null ? (int) $anggaran : null,
                'status' => $status,
                'notes' => $catatan ?: null,
                'created_by' => $user->id,
            ];

            try {
                LeadEvent::create($data);
                $imported++;
            } catch (\Exception $e) {
                $errors[] = "Baris {$rowNum}: Gagal menyimpan — {$e->getMessage()}";
            }
        }

        $spreadsheet->disconnectWorksheets();

        return [
            'imported' => $imported,
            'errors' => $errors,
        ];
    }
}
