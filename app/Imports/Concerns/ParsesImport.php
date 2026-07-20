<?php

namespace App\Imports\Concerns;

use App\Models\Branch;
use PhpOffice\PhpSpreadsheet\IOFactory;

trait ParsesImport
{
    protected static function parseDate(string $value): ?string
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

    protected static function parseNumeric(string $value, bool $stripCurrency = false): ?float
    {
        $value = trim($value);
        if (empty($value)) return null;

        if ($stripCurrency) {
            $value = str_replace(['Rp', '.', ','], ['', '', '.'], $value);
        } else {
            $value = str_replace(['.', ','], ['', '.'], $value);
        }

        return is_numeric($value) ? (float) $value : null;
    }

    protected static function parseBool(string $value): bool
    {
        $v = strtoupper(trim($value));
        return in_array($v, ['YA', 'TRUE', '1', '✓', 'YES']);
    }

    protected static function spreadsheetLoad(string $filePath): array
    {
        $reader = IOFactory::createReaderForFile($filePath);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray();

        return [$spreadsheet, $sheet, $rows];
    }

    protected static function spreadsheetDisconnect($spreadsheet): void
    {
        $spreadsheet->disconnectWorksheets();
    }

    protected static function detectHasCabang(array $rows): array
    {
        $hasCabang = false;
        $branchNames = [];

        if (isset($rows[1]) && is_array($rows[1])) {
            $firstDataRow = array_values($rows[1]);
            $firstVal = trim((string) ($firstDataRow[0] ?? ''));
            if (!empty($firstVal) && !is_numeric($firstVal)) {
                $branchNames = Branch::where('is_active', true)->forDropdown()->pluck('name')->toArray();
                $hasCabang = in_array($firstVal, $branchNames);
            }
        }

        return [$hasCabang, $branchNames];
    }

    protected static function resolveBranchFromFile(string $cabangName, array $branchNameToId, array $branchNames): ?int
    {
        if (!empty($cabangName) && !empty($branchNames)) {
            $branchIdx = array_search($cabangName, $branchNames);
            if ($branchIdx !== false) {
                return $branchNameToId[$branchNames[$branchIdx]] ?? null;
            }
        }
        return null;
    }

    protected static function branchNameToIdMap(): array
    {
        return Branch::where('is_active', true)->forDropdown()->pluck('id', 'name')->toArray();
    }
}
