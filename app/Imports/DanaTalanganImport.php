<?php

namespace App\Imports;

use App\Imports\Concerns\ParsesImport;
use App\Models\DanaTalangan;
use Illuminate\Support\Facades\Auth;

class DanaTalanganImport
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

        foreach ($rows as $cells) {
            $rowNum++;
            if ($rowNum === 1) {
                continue;
            }

            if (! is_array($cells) || count($cells) < 2) {
                continue;
            }

            $cells = array_values($cells);

            $minExpected = $hasCabang ? 15 : 14;
            if (count($cells) < $minExpected) {
                if (count($cells) < 14) {
                    $cells = array_pad($cells, 14, '');
                }
                if ($hasCabang && count($cells) < 15) {
                    $cells = array_pad($cells, 15, '');
                }
            }

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

            $tanggalRaw = $cells[1 + $offset] ?? '';
            $namaKonsumen = trim($cells[2 + $offset] ?? '');
            $kav = trim($cells[3 + $offset] ?? '');
            $projectName = trim($cells[4 + $offset] ?? '');
            $pinjamNama = trim($cells[5 + $offset] ?? '');
            $pekerjaan = trim($cells[6 + $offset] ?? '');
            $statusKawin = trim($cells[7 + $offset] ?? '');
            $umurRaw = trim($cells[8 + $offset] ?? '');
            $marketing = trim($cells[9 + $offset] ?? '');
            $tglKomitmen = trim($cells[10 + $offset] ?? '');
            $penyelesaian = trim($cells[11 + $offset] ?? '');
            $konfirmasiRaw = trim($cells[12 + $offset] ?? '');
            $statusRaw = trim($cells[13 + $offset] ?? '');

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
            $status = in_array(strtolower($statusRaw), ['lunas', 'sanggup', 'tidak_sanggup']) ? strtolower(str_replace(' ', '_', $statusRaw)) : 'sanggup';

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
                'tanggal' => $tanggal,
                'nama_konsumen' => $namaKonsumen,
                'kav' => $kav ?: null,
                'project_name' => $projectName ?: null,
                'pinjam_nama' => self::parseBool((string) $pinjamNama),
                'pekerjaan' => $pekerjaan ?: null,
                'status_perkawinan' => $statusKawin ?: null,
                'umur' => $umur,
                'nama_marketing' => $marketing ?: null,
                'tgl_komitmen' => self::parseDate((string) $tglKomitmen) ?: null,
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

        self::spreadsheetDisconnect($spreadsheet);

        return [
            'imported' => $imported,
            'errors' => $errors,
        ];
    }
}
