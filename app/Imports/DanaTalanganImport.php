<?php

namespace App\Imports;

use App\Imports\Concerns\ParsesImport;
use App\Models\DanaTalangan;
use App\Services\ProjectIdentityResolver;
use App\Support\DanaTalanganMoney;
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
        $headerMap = self::headerMap($rows[0] ?? []);
        $hasNominal = isset($headerMap['nominal']);

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

            $branchFromFile = null;
            if ($hasCabang) {
                $cabangName = self::cell($cells, $headerMap, 'cabang', 0);
                $branchFromFile = self::resolveBranchFromFile($cabangName, $branchNameToId, $branchNames);
                if (! $branchFromFile || ($branchId && (int) $branchId !== (int) $branchFromFile)) {
                    $errors[] = "Baris {$rowNum}: Cabang tidak dikenal atau tidak sesuai izin import.";

                    continue;
                }
            }

            $tanggalRaw = self::cell($cells, $headerMap, 'tanggal', 1);
            $namaKonsumen = self::cell($cells, $headerMap, 'nama konsumen', 2);
            $kav = self::cell($cells, $headerMap, 'kav', 3);
            $projectName = self::cell($cells, $headerMap, 'proyek', 4);
            $pinjamNamaRaw = self::cell($cells, $headerMap, 'pinjam nama', 5);
            $pekerjaan = self::cell($cells, $headerMap, 'pekerjaan', 6);
            $statusKawin = self::cell($cells, $headerMap, 'status kawin', 7);
            $umurRaw = self::cell($cells, $headerMap, 'umur', 8);
            $marketing = self::cell($cells, $headerMap, 'marketing', 9);
            $tglKomitmenRaw = self::cell($cells, $headerMap, 'tgl komitmen', 10);
            $nominalValue = $hasNominal ? self::rawCell($cells, $headerMap, 'nominal', 11) : null;
            $nominalRaw = trim((string) $nominalValue);
            $penyelesaian = self::cell($cells, $headerMap, 'penyelesaian', $hasNominal ? 12 : 11);
            $konfirmasiRaw = self::cell($cells, $headerMap, 'konfirmasi', $hasNominal ? 13 : 12);
            $statusRaw = self::cell($cells, $headerMap, 'status cicilan', $hasNominal ? 14 : 13);

            if (empty($namaKonsumen)) {
                $errors[] = "Baris {$rowNum}: Nama konsumen kosong.";

                continue;
            }

            $tanggal = self::parseDate((string) $tanggalRaw);
            if (empty($tanggal)) {
                $errors[] = "Baris {$rowNum}: Tanggal tidak valid ('{$tanggalRaw}').";

                continue;
            }

            $status = self::normalizeStatus($statusRaw);
            if ($status === null) {
                $errors[] = "Baris {$rowNum}: Status cicilan tidak valid ('{$statusRaw}').";

                continue;
            }

            $umur = null;
            if ($umurRaw !== '') {
                if (! preg_match('/^(\d{1,3})(?:\s*th(?:n)?\.?)?$/iu', $umurRaw, $ageMatches)) {
                    $errors[] = "Baris {$rowNum}: Umur harus berupa angka ('{$umurRaw}').";

                    continue;
                }
                $umur = (int) $ageMatches[1];
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

            $nominal = $hasNominal ? DanaTalanganMoney::normalize($nominalValue) : null;
            if ($nominalRaw !== '' && $nominal === null) {
                $errors[] = "Baris {$rowNum}: Nominal tidak valid ('{$nominalRaw}').";

                continue;
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
                'nominal' => $nominal,
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

    /** @return array<string, int> */
    private static function headerMap(array $headers): array
    {
        $map = [];
        foreach (array_values($headers) as $index => $header) {
            $normalized = mb_strtolower(trim((string) $header));
            if ($normalized !== '') {
                $map[$normalized] = $index;
            }
        }

        return $map;
    }

    /** @param array<int, mixed> $cells */
    private static function cell(array $cells, array $headerMap, string $header, int $fallback): string
    {
        return trim((string) self::rawCell($cells, $headerMap, $header, $fallback));
    }

    /** @param array<int, mixed> $cells */
    private static function rawCell(array $cells, array $headerMap, string $header, int $fallback): mixed
    {
        $index = $headerMap[$header] ?? $fallback;

        return $cells[$index] ?? null;
    }

    private static function normalizeStatus(string $raw): ?string
    {
        $status = str_replace(' ', '_', mb_strtolower(trim($raw)));
        if ($status === '') {
            return 'belum_ditentukan';
        }

        return in_array($status, ['belum_ditentukan', 'sanggup', 'tidak_sanggup', 'lunas'], true) ? $status : null;
    }
}
