<?php

namespace App\Imports;

use App\Models\Branch;
use App\Models\DanaTalangan;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Illuminate\Support\Facades\Auth;

class DanaTalanganImport
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

    private static function parseBool(string $value): bool
    {
        $v = strtoupper(trim($value));
        return in_array($v, ['YA', 'TRUE', '1', '✓', 'YES']);
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

        // --- Detect if file has Cabang column (template format) ---
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

            $minExpected = $hasCabang ? 14 : 13;
            if (count($cells) < $minExpected) {
                if (count($cells) < 13) {
                    $cells = array_pad($cells, 13, '');
                }
                if ($hasCabang && count($cells) < 14) {
                    $cells = array_pad($cells, 14, '');
                }
            }

            $offset = $hasCabang ? 1 : 0;

            // Read branch from file if available
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

            $tanggalRaw = $cells[1 + $offset] ?? '';
            $namaKonsumen = trim($cells[2 + $offset] ?? '');
            $kav = trim($cells[3 + $offset] ?? '');
            $projectName = trim($cells[4 + $offset] ?? '');
            $pinjamNama = trim($cells[5 + $offset] ?? '');
            $pekerjaan = trim($cells[6 + $offset] ?? '');
            $statusKawin = trim($cells[7 + $offset] ?? '');
            $umurRaw = trim($cells[8 + $offset] ?? '');
            $marketing = trim($cells[9 + $offset] ?? '');
            $penyelesaian = trim($cells[10 + $offset] ?? '');
            $konfirmasiRaw = trim($cells[11 + $offset] ?? '');
            $statusRaw = trim($cells[12 + $offset] ?? '');

            if (empty($namaKonsumen)) {
                $errors[] = "Baris {$rowNum}: Nama konsumen kosong.";
                continue;
            }

            $tanggal = self::parseDate((string) $tanggalRaw);
            if (empty($tanggal)) {
                $errors[] = "Baris {$rowNum}: Tanggal tidak valid ('{$tanggalRaw}').";
                continue;
            }

            $umur = is_numeric($umurRaw) ? (int) $umurRaw : null;
            $status = in_array(strtolower($statusRaw), ['lunas', 'aktif']) ? strtolower($statusRaw) : 'aktif';

            $resolvedBranchId = $branchFromFile ?? $branchId ?? $user->branch_id ?? 1;

            $data = [
                'branch_id' => $resolvedBranchId,
                'tanggal' => $tanggal,
                'nama_konsumen' => $namaKonsumen,
                'kav' => $kav ?: null,
                'project_name' => $projectName ?: null,
                'pinjam_nama' => self::parseBool((string) $pinjamNama),
                'pekerjaan' => $pekerjaan ?: null,
                'status_perkawinan' => $statusKawin ?: null,
                'umur' => $umur,
                'nama_marketing' => $marketing ?: null,
                'penyelesaian' => $penyelesaian ?: null,
                'konfirmasi_keuangan' => self::parseBool((string) $konfirmasiRaw),
                'status' => $status,
                'created_by' => $user->id,
            ];

            try {
                DanaTalangan::create($data);
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
