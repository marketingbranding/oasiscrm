<?php

namespace App\Imports;

use App\Models\Branch;
use App\Models\ContentItem;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Illuminate\Support\Facades\Auth;

class ContentItemImport
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

        $spreadsheet->disconnectWorksheets();

        return [
            'imported' => $imported,
            'errors' => $errors,
        ];
    }
}
