<?php

namespace App\Imports;

use App\Imports\Concerns\ParsesImport;
use App\Models\ContentItem;
use Illuminate\Support\Facades\Auth;

class ContentItemImport
{
    use ParsesImport;

    public static function import(string $filePath, ?int $branchId = null, ?array $preservedParams = [], array $allowedBranchIds = []): array
    {
        $imported = 0;
        $errors = [];
        $rowNum = 0;
        $user = Auth::user();
        $allowedBranchIds = $allowedBranchIds ?: array_values(array_filter([$branchId]));

        [$spreadsheet, $sheet, $rows] = self::spreadsheetLoad($filePath);

        [$hasCabang, $branchNames] = self::detectHasCabang($rows, $allowedBranchIds);
        $branchNameToId = self::branchNameToIdMap($allowedBranchIds);
        $header = array_map(fn ($value) => mb_strtolower(trim((string) $value)), array_values($rows[0] ?? []));
        $newFormat = in_array('tipe', $header, true);

        foreach ($rows as $cells) {
            $rowNum++;
            if ($rowNum === 1) {
                continue;
            }

            if (! is_array($cells) || count($cells) < 2) {
                continue;
            }

            $cells = array_values($cells);

            $offset = $hasCabang ? 1 : 0;

            $branchFromFile = null;
            if ($hasCabang) {
                $cabangName = trim((string) ($cells[0] ?? ''));
                $branchFromFile = self::resolveBranchFromFile($cabangName, $branchNameToId, $branchNames);
                if (! $branchFromFile || ($branchId && (int) $branchId !== (int) $branchFromFile)) {
                    $errors[] = "Baris {$rowNum}: Cabang tidak dikenal atau tidak sesuai izin import.";

                    continue;
                }
            }

            if ($newFormat) {
                $type = strtolower(trim($cells[1] ?? 'task'));
                $visibility = strtolower(trim($cells[2] ?? 'team'));
                $judul = trim($cells[3] ?? '');
                $detail = trim($cells[4] ?? '');
                $platform = trim($cells[5] ?? '');
                $projectName = trim($cells[6] ?? '');
                $startRaw = $cells[7] ?? '';
                $startTime = trim($cells[8] ?? '');
                $deadlineRaw = $cells[9] ?? '';
                $endTime = trim($cells[10] ?? '');
                $priorityRaw = strtolower(trim($cells[11] ?? ''));
                $picNames = trim($cells[12] ?? '');
                $statusRaw = strtolower(trim($cells[13] ?? ''));
                $agendaType = trim($cells[14] ?? '');
                $location = trim($cells[15] ?? '');
                $contentFormat = trim($cells[16] ?? '');
                $tujuanKonten = trim($cells[17] ?? '');
                $catatan = trim($cells[18] ?? '');
            } else {
                $type = 'task';
                $visibility = 'team';
                $judul = trim($cells[0 + $offset] ?? '');
                $detail = trim($cells[1 + $offset] ?? '');
                $platform = trim($cells[2 + $offset] ?? '');
                $projectName = trim($cells[3 + $offset] ?? '');
                $startRaw = $cells[4 + $offset] ?? '';
                $startTime = '';
                $deadlineRaw = $cells[5 + $offset] ?? '';
                $endTime = '';
                $priorityRaw = strtolower(trim($cells[6 + $offset] ?? ''));
                $picNames = trim($cells[7 + $offset] ?? '');
                $statusRaw = strtolower(trim($cells[8 + $offset] ?? ''));
                $agendaType = $location = $contentFormat = $tujuanKonten = '';
                $catatan = trim($cells[9 + $offset] ?? '');
            }

            if (empty($judul)) {
                $errors[] = "Baris {$rowNum}: Judul kosong.";

                continue;
            }

            $startDate = self::parseDate((string) $startRaw) ?: null;
            $deadline = self::parseDate((string) $deadlineRaw);
            if ($type !== 'content' && empty($deadline)) {
                $errors[] = "Baris {$rowNum}: Deadline tidak valid ('{$deadlineRaw}').";

                continue;
            }

            $type = in_array($type, ContentItem::TYPES, true) ? $type : 'task';
            $visibility = in_array($visibility, ['personal', 'team'], true) ? $visibility : 'team';
            $priority = in_array($priorityRaw, ['low', 'medium', 'high', 'urgent'], true) ? $priorityRaw : 'medium';
            $status = in_array($statusRaw, ContentItem::STATUSES[$type], true) ? $statusRaw : ContentItem::STATUSES[$type][0];
            $picNamesArray = $picNames !== '' ? array_map('trim', explode(',', $picNames)) : [];

            $resolvedBranchId = $hasCabang ? $branchFromFile : ($branchId ?? $user->branch_id);
            if (! $resolvedBranchId) {
                $errors[] = "Baris {$rowNum}: Cabang tidak dapat ditentukan.";

                continue;
            }
            if (! in_array((int) $resolvedBranchId, $allowedBranchIds, true)) {
                $errors[] = "Baris {$rowNum}: Anda tidak memiliki izin edit untuk cabang tersebut.";

                continue;
            }

            $data = [
                'branch_id' => $resolvedBranchId,
                'item_type' => $type,
                'visibility' => $visibility,
                'project_name' => $type === 'content' ? null : ($projectName ?: null),
                'title' => $judul,
                'task_detail' => $type === 'content' ? null : ($detail ?: null),
                'platform' => $platform ?: null,
                'start_date' => $type === 'content' ? null : $startDate,
                'start_time' => $type === 'content' ? null : ($startTime ?: null),
                'deadline_date' => $type === 'content' ? null : $deadline,
                'end_time' => $type === 'content' ? null : ($endTime ?: null),
                'scheduled_date' => $type === 'content' ? null : ($type === 'agenda' ? ($startDate ?: $deadline) : $deadline),
                'agenda_type' => $type === 'content' ? null : ($agendaType ?: null),
                'location' => $type === 'content' ? null : ($location ?: null),
                'content_format' => $type === 'content' ? ($contentFormat ?: null) : null,
                'tujuan_konten' => $type === 'content' ? ($tujuanKonten ?: null) : null,
                'asset_url' => null,
                'priority' => $priority,
                'pic_names' => $type === 'content' ? null : ($picNamesArray ?: null),
                'status' => $status,
                'completed_at' => in_array($status, ['completed', 'done', 'uploaded', 'cancelled'], true) ? now() : null,
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
