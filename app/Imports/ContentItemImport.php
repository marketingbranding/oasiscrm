<?php

namespace App\Imports;

use App\Imports\Concerns\ParsesImport;
use App\Models\Branch;
use App\Models\ContentItem;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Illuminate\Support\Facades\Auth;

class ContentItemImport
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

            $judul = trim($cells[0 + $offset] ?? '');
            $platform = trim($cells[1 + $offset] ?? '');
            $projectName = trim($cells[2 + $offset] ?? '');
            $tanggalRaw = $cells[3 + $offset] ?? '';
            $statusRaw = trim($cells[4 + $offset] ?? '');
            $catatan = trim($cells[5 + $offset] ?? '');

            if (empty($judul)) {
                $errors[] = "Baris {$rowNum}: Judul kosong.";
                continue;
            }

            $tanggal = self::parseDate((string) $tanggalRaw);
            if (empty($tanggal)) {
                $errors[] = "Baris {$rowNum}: Tanggal tidak valid ('{$tanggalRaw}').";
                continue;
            }

            $status = in_array(strtolower($statusRaw), ['rencana', 'terbit']) ? strtolower($statusRaw) : 'rencana';

            $resolvedBranchId = $branchFromFile ?? $branchId ?? $user->branch_id ?? 1;

            $data = [
                'branch_id' => $resolvedBranchId,
                'project_name' => $projectName ?: null,
                'title' => $judul,
                'platform' => $platform ?: null,
                'scheduled_date' => $tanggal,
                'status' => $status,
                'notes' => $catatan ?: null,
                'created_by' => $user->id,
            ];

            try {
                ContentItem::create($data);
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
