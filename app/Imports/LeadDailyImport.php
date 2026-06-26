<?php

namespace App\Imports;

use App\Imports\Concerns\ParsesImport;
use App\Models\Branch;
use App\Models\LeadDaily;
use App\Models\LeadEvent;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Illuminate\Support\Facades\Auth;

class LeadDailyImport
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

        // Pre-fetch events for N+1 safety: id + start_date + daily_target keyed by event_id
        $eventData = LeadEvent::get(['id', 'event_id', 'start_date', 'daily_target'])->keyBy('event_id');
        // Pre-build branch name→id map for N+1 safety
        $branchNameToId = self::branchNameToIdMap();
        // In-memory cumulative per event (avoids DB sum per row)
        $cumulativeMap = [];

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

            $tanggalRaw = $cells[0 + $offset] ?? '';
            $eventIdRaw = trim($cells[1 + $offset] ?? '');
            $hariKeRaw = trim($cells[3 + $offset] ?? '');
            $leadsRaw = trim($cells[4 + $offset] ?? '');
            $kumulatifRaw = trim($cells[5 + $offset] ?? '');
            $achievementRaw = trim($cells[6 + $offset] ?? '');

            $tanggal = self::parseDate((string) $tanggalRaw);
            if (empty($tanggal)) {
                $errors[] = "Baris {$rowNum}: Tanggal tidak valid ('{$tanggalRaw}').";
                continue;
            }

            if (empty($eventIdRaw)) {
                $errors[] = "Baris {$rowNum}: Event ID kosong.";
                continue;
            }

            // Extract event_id from format "EV-xxx — ProjectName (Branch)" or plain "EV-xxx"
            $eventIdParsed = explode(' — ', $eventIdRaw)[0];

            $leadEvent = $eventData[$eventIdParsed] ?? null;
            if (!$leadEvent) {
                $errors[] = "Baris {$rowNum}: Event ID '{$eventIdParsed}' tidak ditemukan.";
                continue;
            }

            $leadsCount = self::parseNumeric($leadsRaw);
            if ($leadsCount === null) {
                $errors[] = "Baris {$rowNum}: Jumlah Leads tidak valid ('{$leadsRaw}').";
                continue;
            }

            $resolvedBranchId = $branchFromFile ?? $branchId ?? $user->branch_id ?? 1;

            $dayNumber = !empty($hariKeRaw) ? (int) $hariKeRaw : ($leadEvent->start_date ? $leadEvent->start_date->diffInDays(now()->parse($tanggal)) + 1 : null);

            $cumulativeLeads = !empty($kumulatifRaw)
                ? (int) self::parseNumeric($kumulatifRaw)
                : ($cumulativeMap[$leadEvent->id] ?? 0) + $leadsCount;
            $cumulativeMap[$leadEvent->id] = $cumulativeLeads;

            $achievementPct = null;
            if (!empty($achievementRaw)) {
                $achievementPct = (float) str_replace('%', '', $achievementRaw);
            } elseif ($leadEvent->daily_target && $leadEvent->daily_target > 0) {
                $achievementPct = round(($leadsCount / $leadEvent->daily_target) * 100, 2);
            }

            $data = [
                'lead_event_id' => $leadEvent->id,
                'branch_id' => $resolvedBranchId,
                'date' => $tanggal,
                'day_number' => $dayNumber,
                'leads_count' => (int) $leadsCount,
                'cumulative_leads' => $cumulativeLeads,
                'achievement_pct' => $achievementPct,
                'created_by' => $user->id,
            ];

            try {
                LeadDaily::create($data);
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
