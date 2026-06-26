<?php

namespace App\Imports;

use App\Imports\Concerns\ParsesImport;
use App\Models\Branch;
use App\Models\LeadEvent;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Illuminate\Support\Facades\Auth;

class LeadEventImport
{
    use ParsesImport;

    public static function import(string $filePath, ?int $branchId = null, ?array $preservedParams = []): array
    {
        $imported = 0;
        $errors = [];
        $rowNum = 0;
        $user = Auth::user();

        [$spreadsheet, $sheet, $rows] = self::spreadsheetLoad($filePath);

        [$hasCabang, $branchNames] = self::detectHasCabang($rows);
        $branchNameToId = self::branchNameToIdMap();

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
                $branchFromFile = self::resolveBranchFromFile($cabangName, $branchNameToId, $branchNames);
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

            $anggaran = self::parseNumeric($anggaranRaw, true);
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

        self::spreadsheetDisconnect($spreadsheet);

        return [
            'imported' => $imported,
            'errors' => $errors,
        ];
    }
}
