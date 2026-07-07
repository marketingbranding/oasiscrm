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
            $detail = trim($cells[1 + $offset] ?? '');
            $platform = trim($cells[2 + $offset] ?? '');
            $projectName = trim($cells[3 + $offset] ?? '');
            $startRaw = $cells[4 + $offset] ?? '';
            $deadlineRaw = $cells[5 + $offset] ?? '';
            $priorityRaw = strtolower(trim($cells[6 + $offset] ?? ''));
            $picNames = trim($cells[7 + $offset] ?? '');
            $statusRaw = strtolower(trim($cells[8 + $offset] ?? ''));
            $catatan = trim($cells[9 + $offset] ?? '');

            if (empty($judul)) {
                $errors[] = "Baris {$rowNum}: Judul kosong.";
                continue;
            }

            $startDate = self::parseDate((string) $startRaw) ?: null;
            $deadline = self::parseDate((string) $deadlineRaw);
            if (empty($deadline)) {
                $errors[] = "Baris {$rowNum}: Deadline tidak valid ('{$deadlineRaw}').";
                continue;
            }

            $priority = in_array($priorityRaw, ['low', 'medium', 'high', 'urgent'], true) ? $priorityRaw : 'medium';
            $status = in_array($statusRaw, ['todo', 'in_progress', 'completed', 'lost_track'], true) ? $statusRaw : 'todo';
            $picNamesArray = $picNames !== '' ? array_map('trim', explode(',', $picNames)) : [];

            $resolvedBranchId = $branchFromFile ?? $branchId ?? $user->branch_id ?? 1;

            $data = [
                'branch_id' => $resolvedBranchId,
                'project_name' => $projectName ?: null,
                'title' => $judul,
                'task_detail' => $detail ?: null,
                'platform' => $platform ?: null,
                'start_date' => $startDate,
                'deadline_date' => $deadline,
                'scheduled_date' => $deadline,
                'priority' => $priority,
                'pic_names' => $picNamesArray ?: null,
                'status' => $status,
                'completed_at' => $status === 'completed' ? now() : null,
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
