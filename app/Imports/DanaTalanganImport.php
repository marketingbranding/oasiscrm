<?php

namespace App\Imports;

use App\Imports\Concerns\ParsesImport;
use App\Models\DanaTalangan;
use App\Services\ProjectIdentityResolver;
use Illuminate\Support\Facades\Auth;

class DanaTalanganImport
{
    use ParsesImport;

    private const BOOL_ALIASES = [
        'YA' => true, 'TIDAK' => false, 'TRUE' => true, 'FALSE' => false,
        'YES' => true, 'NO' => false, '1' => true, '0' => false, '✓' => true,
    ];

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
            $pinjamNamaRaw = trim($cells[5 + $offset] ?? '');
            $pekerjaan = trim($cells[6 + $offset] ?? '');
            $statusKawin = trim($cells[7 + $offset] ?? '');
            $umurRaw = trim($cells[8 + $offset] ?? '');
            $marketing = trim($cells[9 + $offset] ?? '');
            $tglKomitmenRaw = trim($cells[10 + $offset] ?? '');
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

            $status = str_replace(' ', '_', strtolower($statusRaw));
            if (! in_array($status, ['lunas', 'sanggup', 'tidak_sanggup'], true)) {
                $errors[] = "Baris {$rowNum}: Status cicilan tidak valid ('{$statusRaw}').";

                continue;
            }

            $umur = null;
            if ($umurRaw !== '') {
                if (! ctype_digit($umurRaw)) {
                    $errors[] = "Baris {$rowNum}: Umur harus berupa angka ('{$umurRaw}').";

                    continue;
                }
                $umur = (int) $umurRaw;
                if ($umur < 0 || $umur > 150) {
                    $errors[] = "Baris {$rowNum}: Umur harus antara 0 dan 150 ('{$umurRaw}').";

                    continue;
                }
            }

            $pinjamNama = self::strictBool($pinjamNamaRaw);
            if ($pinjamNama === null) {
                $errors[] = "Baris {$rowNum}: Pinjam Nama hanya boleh YA atau TIDAK ('{$pinjamNamaRaw}').";

                continue;
            }

            $konfirmasiKeuangan = self::strictBool($konfirmasiRaw);
            if ($konfirmasiKeuangan === null) {
                $errors[] = "Baris {$rowNum}: Konfirmasi hanya boleh YA atau TIDAK ('{$konfirmasiRaw}').";

                continue;
            }

            $tglKomitmen = null;
            if ($tglKomitmenRaw !== '') {
                $tglKomitmen = self::parseDate($tglKomitmenRaw);
                if ($tglKomitmen === null) {
                    $errors[] = "Baris {$rowNum}: TGL Komitmen tidak valid ('{$tglKomitmenRaw}').";

                    continue;
                }
            }

            $resolvedBranchId = $hasCabang ? $branchFromFile : ($branchId ?? $user->branch_id);
            if (! $resolvedBranchId) {
                $errors[] = "Baris {$rowNum}: Cabang tidak dapat ditentukan.";

                continue;
            }
            if (! in_array((int) $resolvedBranchId, $allowedBranchIds, true)) {
                $errors[] = "Baris {$rowNum}: Anda tidak memiliki izin edit untuk cabang tersebut.";

                continue;
            }

            if ($projectName === '') {
                $errors[] = "Baris {$rowNum}: Proyek wajib diisi.";

                continue;
            }
            $project = app(ProjectIdentityResolver::class)->resolveExactOrNull($resolvedBranchId, $projectName);
            if ($project === null) {
                $errors[] = "Baris {$rowNum}: Proyek harus cocok tepat dengan satu proyek aktif pada cabang.";

                continue;
            }
            if (! in_array((int) $project->id, $allowedProjectIds, true)) {
                $errors[] = "Baris {$rowNum}: Proyek tidak termasuk cakupan pengelolaan Anda.";

                continue;
            }

            $data = [
                'branch_id' => $resolvedBranchId,
                'project_id' => $project->id,
                'sync_status' => 'pending_create',
                'tanggal' => $tanggal,
                'nama_konsumen' => $namaKonsumen,
                'kav' => $kav ?: null,
                'project_name' => $project->project_name,
                'pinjam_nama' => $pinjamNama,
                'pekerjaan' => $pekerjaan ?: null,
                'status_perkawinan' => $statusKawin ?: null,
                'umur' => $umur,
                'nama_marketing' => $marketing ?: null,
                'tgl_komitmen' => $tglKomitmen,
                'penyelesaian' => $penyelesaian ?: null,
                'konfirmasi_keuangan' => $konfirmasiKeuangan,
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

    private static function strictBool(string $raw): ?bool
    {
        $normalized = strtoupper(trim($raw));
        if ($normalized === '') {
            return false;
        }

        return self::BOOL_ALIASES[$normalized] ?? null;
    }
}
