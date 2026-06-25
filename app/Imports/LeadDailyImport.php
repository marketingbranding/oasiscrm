<?php

namespace App\Imports;

use App\Models\Branch;
use App\Models\LeadDaily;
use App\Models\LeadEvent;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Illuminate\Support\Facades\Auth;

class LeadDailyImport
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
        $value = str_replace(['.', ','], ['', '.'], $value);
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

        // Pre-fetch events for lookup
        $events = LeadEvent::pluck('id', 'event_id');

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

            $leadEventId = $events[$eventIdParsed] ?? null;
            if (!$leadEventId) {
                $errors[] = "Baris {$rowNum}: Event ID '{$eventIdParsed}' tidak ditemukan.";
                continue;
            }

            $event = LeadEvent::find($leadEventId);
            if (!$event) {
                $errors[] = "Baris {$rowNum}: Event dengan ID '{$eventIdParsed}' tidak ditemukan.";
                continue;
            }

            $leadsCount = self::parseNumeric($leadsRaw);
            if ($leadsCount === null) {
                $errors[] = "Baris {$rowNum}: Jumlah Leads tidak valid ('{$leadsRaw}').";
                continue;
            }

            $resolvedBranchId = $branchFromFile ?? $branchId ?? $user->branch_id ?? 1;

            $dayNumber = !empty($hariKeRaw) ? (int) $hariKeRaw : ($event->start_date ? $event->start_date->diffInDays(now()->parse($tanggal)) + 1 : null);

            $cumulativeLeads = !empty($kumulatifRaw)
                ? (int) self::parseNumeric($kumulatifRaw)
                : LeadDaily::where('lead_event_id', $leadEventId)
                    ->where('date', '<=', $tanggal)
                    ->sum('leads_count') + $leadsCount;

            $achievementPct = null;
            if (!empty($achievementRaw)) {
                $achievementPct = (float) str_replace('%', '', $achievementRaw);
            } elseif ($event->daily_target && $event->daily_target > 0) {
                $achievementPct = round(($leadsCount / $event->daily_target) * 100, 2);
            }

            $data = [
                'lead_event_id' => $leadEventId,
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

        $spreadsheet->disconnectWorksheets();

        return [
            'imported' => $imported,
            'errors' => $errors,
        ];
    }
}
