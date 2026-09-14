<?php

namespace App\Imports;

use App\Imports\Concerns\ParsesImport;
use App\Models\ContentItem;
use App\Services\ProjectIdentityResolver;
use Illuminate\Support\Facades\Auth;

class ContentItemImport
{
    use ParsesImport;

    public static function import(string $filePath, ?int $branchId = null, ?array $preservedParams = [], array $allowedBranchIds = [], array $allowedProjectIds = []): array
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

            if (! in_array($type, ContentItem::TYPES, true)) {
                $errors[] = "Baris {$rowNum}: Tipe item tidak valid ('{$type}').";

                continue;
            }
            if (! in_array($visibility, ['personal', 'team'], true)) {
                $errors[] = "Baris {$rowNum}: Visibilitas tidak valid ('{$visibility}').";

                continue;
            }
            if (! in_array($priorityRaw, ['low', 'medium', 'high', 'urgent'], true)) {
                $errors[] = "Baris {$rowNum}: Prioritas tidak valid ('{$priorityRaw}').";

                continue;
            }
            if (! in_array($statusRaw, ContentItem::STATUSES[$type], true)) {
                $errors[] = "Baris {$rowNum}: Status tidak valid untuk tipe {$type} ('{$statusRaw}').";

                continue;
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

            if ($type === 'agenda') {
                if ($agendaType === '') {
                    $errors[] = "Baris {$rowNum}: Jenis Agenda wajib diisi untuk agenda.";

                    continue;
                }
                if (mb_strlen($agendaType) > 50) {
                    $errors[] = "Baris {$rowNum}: Jenis Agenda maksimal 50 karakter.";

                    continue;
                }
                if ($startTime === '') {
                    $errors[] = "Baris {$rowNum}: Jam Mulai wajib diisi untuk agenda.";

                    continue;
                }
            }
            if ($type === 'content') {
                if ($platform === '') {
                    $errors[] = "Baris {$rowNum}: Platform wajib diisi untuk konten.";

                    continue;
                }
                if (! in_array($contentFormat, ['Video', 'Gambar', 'Video Karosel', 'Karosel', 'Artikel'], true)) {
                    $errors[] = "Baris {$rowNum}: Format Konten wajib dan harus valid untuk konten ('{$contentFormat}').";

                    continue;
                }
                if (! in_array($tujuanKonten, ['Edukasi', 'Entertainment', 'Inspirasi'], true)) {
                    $errors[] = "Baris {$rowNum}: Tujuan Konten wajib dan harus valid untuk konten ('{$tujuanKonten}').";

                    continue;
                }
            }
            if (in_array($type, ['agenda', 'content'], true) && $startDate === null) {
                $errors[] = "Baris {$rowNum}: Tanggal mulai wajib diisi untuk {$type}.";

                continue;
            }
            if ($platform !== '' && ! in_array($platform, ['Sosial Media', 'Website'], true)) {
                $errors[] = "Baris {$rowNum}: Platform tidak valid ('{$platform}').";

                continue;
            }
            foreach (['Jam Mulai' => $startTime, 'Jam Selesai' => $endTime] as $timeLabel => $timeValue) {
                if ($timeValue !== '' && ! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $timeValue)) {
                    $errors[] = "Baris {$rowNum}: {$timeLabel} harus berformat HH:MM ('{$timeValue}').";

                    continue 2;
                }
            }
            if (mb_strlen($location) > 255) {
                $errors[] = "Baris {$rowNum}: Lokasi maksimal 255 karakter.";

                continue;
            }

            $priority = $priorityRaw;
            $status = $statusRaw;
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

            $visibility = $type === 'content' ? 'team' : $visibility;

            $project = null;
            if ($type !== 'content' && $projectName !== '') {
                $project = app(ProjectIdentityResolver::class)->resolveExactOrNull($resolvedBranchId, $projectName);
                if ($project === null) {
                    $errors[] = "Baris {$rowNum}: Proyek harus cocok tepat dengan satu proyek aktif pada cabang.";

                    continue;
                }
                if (! in_array((int) $project->id, $allowedProjectIds, true)) {
                    $errors[] = "Baris {$rowNum}: Proyek tidak termasuk cakupan pengelolaan Anda.";

                    continue;
                }
            }

            $data = [
                'branch_id' => $resolvedBranchId,
                'item_type' => $type,
                'visibility' => $visibility,
                'project_name' => $type === 'content' ? null : ($project?->project_name ?? ($projectName ?: null)),
                'title' => $judul,
                'task_detail' => $type === 'content' ? null : ($detail ?: null),
                'platform' => $platform ?: null,
                'start_date' => $type === 'content' ? null : $startDate,
                'start_time' => $type === 'content' ? null : ($startTime ?: null),
                'deadline_date' => $type === 'content' ? null : $deadline,
                'end_time' => $type === 'content' ? null : ($endTime ?: null),
                'scheduled_date' => $type === 'task' ? ($deadline ?: null) : $startDate,
                'agenda_type' => $type === 'content' ? null : ($agendaType ?: null),
                'location' => $type === 'content' ? null : ($location ?: null),
                'content_format' => $type === 'content' ? ($contentFormat ?: null) : null,
                'tujuan_konten' => $type === 'content' ? ($tujuanKonten ?: null) : null,
                'asset_url' => null,
                'priority' => $priority,
                'pic_names' => $type === 'content' ? null : ($picNamesArray ?: null),
                'status' => $status,
                'completed_at' => in_array($status, ['completed', 'done', 'uploaded', 'cancelled', 'rescheduled'], true) ? now() : null,
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
