<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\ConsumerApplication;
use App\Models\ConsumerBankProcess;
use App\Models\ConsumerLegacyIdentity;
use App\Models\ConsumerStageEvent;
use App\Models\HistoricalProcessImportBatch;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class HistoricalProcessImportService
{
    public const HEADERS = [
        'bi_checking' => ['id_kavling', 'nama_konsumen', 'no_ktp', 'id_kons', 'tanggal_slik', 'hasil_slik', 'keterangan'],
        'PSJB' => ['id_kavling', 'id_kons', 'id_psjb', 'tanggal_psjb', 'nama_koordinator', 'nama_sales', 'harga_unit', 'tanggal_utj', 'utj', 'tanggal_dp_klt', 'dp_all_in', 'nominal_cicilan', 'jumlah_cicilan', 'luas_klt', 'harga_klt/m', 'harga_klt_total', 'cara_pembayaran', 'id_promo', 'lead_time_hari', 'status', 'keterangan'],
        'pemberkasan' => ['id_kavling', 'id_psjb', 'id_berkas', 'tanggal_terima_bank', 'bank', 'kc/unit', 'request_plafond', 'request_tenor', 'tipe_pemberkasan', 'lead_time_hari', 'status', 'keterangan'],
        'proses_bank' => ['id_kavling', 'id_berkas', 'no_sp3k', 'jenis_respon', 'approved_plafond', 'approved_tenor', 'lead_time_hari', 'status', 'kategori_revisi', 'detail_revisi', 'kendala', 'keterangan'],
        'ppjb_dev' => ['id_kavling', 'no_sp3k', 'id_ppjb_dev', 'tanggal_sp3k', 'tanggal_ttd_ppjb', 'lead_time_hari', 'status', 'keterangan'],
        'akad' => ['id_kavling', 'id_ppjb_dev', 'no_ppjb_akad', 'tanggal_akad', 'kualitas_akad', 'lead_time_hari', 'status', 'status_bangunan', 'status_dp_konsumen', 'status_utilitas', 'status_konsumen', 'keterangan_terlambat', 'keterangan'],
        'bast' => ['id_kavling', 'no_ppjb_akad', 'no_bast', 'tanggal_bast', 'lead_time_hari', 'status', 'keterangan'],
    ];

    private const CHAIN = [
        'bi_checking' => ['resolve' => null, 'produce' => 'id_kons'],
        'PSJB' => ['resolve' => 'id_kons', 'produce' => 'id_psjb'],
        'pemberkasan' => ['resolve' => 'id_psjb', 'produce' => 'id_berkas'],
        'proses_bank' => ['resolve' => 'id_berkas', 'produce' => 'no_sp3k'],
        'ppjb_dev' => ['resolve' => 'no_sp3k', 'produce' => 'id_ppjb_dev'],
        'akad' => ['resolve' => 'id_ppjb_dev', 'produce' => 'no_ppjb_akad'],
        'bast' => ['resolve' => 'no_ppjb_akad', 'produce' => 'no_bast'],
    ];

    private const REQUIRED = [
        'bi_checking' => ['id_kavling', 'nama_konsumen', 'id_kons'],
        'PSJB' => ['id_kavling', 'id_kons', 'id_psjb'],
        'pemberkasan' => ['id_kavling', 'id_psjb', 'id_berkas'],
        'proses_bank' => ['id_kavling', 'id_berkas', 'no_sp3k'],
        'ppjb_dev' => ['id_kavling', 'no_sp3k', 'id_ppjb_dev'],
        'akad' => ['id_kavling', 'id_ppjb_dev', 'no_ppjb_akad'],
        'bast' => ['id_kavling', 'no_ppjb_akad', 'no_bast'],
    ];

    private const DATE_FIELDS = [
        'bi_checking' => ['tanggal_slik'],
        'PSJB' => ['tanggal_psjb', 'tanggal_utj', 'tanggal_dp_klt'],
        'pemberkasan' => ['tanggal_terima_bank'],
        'proses_bank' => [],
        'ppjb_dev' => ['tanggal_sp3k', 'tanggal_ttd_ppjb'],
        'akad' => ['tanggal_akad'],
        'bast' => ['tanggal_bast'],
    ];

    private const AMOUNT_FIELDS = [
        'PSJB' => ['harga_unit', 'utj', 'dp_all_in', 'nominal_cicilan', 'harga_klt/m', 'harga_klt_total'],
        'pemberkasan' => ['request_plafond'],
        'proses_bank' => ['approved_plafond'],
    ];

    private const STAGE_LABELS = [
        'bi_checking' => 'BI Checking',
        'PSJB' => 'PSJB',
        'pemberkasan' => 'Pemberkasan',
        'proses_bank' => 'Proses Bank',
        'ppjb_dev' => 'PPJB Dev',
        'akad' => 'Akad',
        'bast' => 'BAST',
    ];

    public function parse(string $input): array
    {
        if (strlen($input) > 262144) {
            throw new \InvalidArgumentException('Data TSV maksimal 256KB.');
        }

        $input = preg_replace('/^\xEF\xBB\xBF/', '', str_replace(["\r\n", "\r"], "\n", $input));
        $lines = explode("\n", $input);
        while ($lines !== [] && trim(end($lines)) === '') {
            array_pop($lines);
        }
        if ($lines === []) {
            throw new \InvalidArgumentException('Data TSV kosong.');
        }

        $first = array_map('trim', str_getcsv($lines[0], "\t"));
        while ($first !== [] && end($first) === '') {
            array_pop($first);
        }
        $sheetType = null;
        foreach (self::HEADERS as $type => $headers) {
            if ($first === $headers) {
                $sheetType = $type;
                break;
            }
        }
        if ($sheetType === null) {
            $found = array_values(array_filter(self::HEADERS, fn ($headers) => array_intersect($first, $headers) !== []));
            if ($found !== []) {
                throw new \InvalidArgumentException('Header harus tepat sesuai tahap. Periksa ejaan dan urutan kolom.');
            }

            throw new \InvalidArgumentException('Header tidak dikenali. Tempel data dari salah satu tahap Database Master 2026.');
        }

        array_shift($lines);
        if (count($lines) > 500) {
            throw new \InvalidArgumentException('Data TSV maksimal 500 baris.');
        }

        $headers = self::HEADERS[$sheetType];
        $parsed = [];
        foreach ($lines as $offset => $line) {
            $values = array_map('trim', str_getcsv($line, "\t"));
            while ($values !== [] && end($values) === '') {
                array_pop($values);
            }
            $errors = [];
            if (count($values) > count($headers)) {
                $errors[] = 'Jumlah kolom maksimal '.count($headers).'.';
            }
            $values = array_pad(array_slice($values, 0, count($headers)), count($headers), '');
            $raw = array_combine($headers, $values);

            foreach (self::REQUIRED[$sheetType] as $required) {
                if (($raw[$required] ?? '') === '') {
                    $errors[] = ucfirst(str_replace(['_', '/'], ' ', $required)).' wajib diisi.';
                }
            }

            $nik = null;
            if ($sheetType === 'bi_checking' && isset($raw['no_ktp'])) {
                $nik = $raw['no_ktp'] !== '' ? $raw['no_ktp'] : null;
                if ($nik !== null && ! preg_match('/^\d{16}$/', $nik)) {
                    $errors[] = 'No KTP harus tepat 16 digit.';
                }
            }

            $normalized = [];
            foreach ($headers as $header) {
                if ($header === 'no_ktp') {
                    continue;
                }
                $value = $raw[$header] ?? '';
                if (in_array($header, self::DATE_FIELDS[$sheetType], true)) {
                    $normalized[$header] = $value === '' ? null : $this->date($value);
                    if ($value !== '' && $normalized[$header] === null) {
                        $errors[] = ucfirst(str_replace('_', ' ', $header)).' tidak valid.';
                    }
                } elseif (in_array($header, self::AMOUNT_FIELDS[$sheetType] ?? [], true)) {
                    $normalized[$header] = $value === '' ? null : $this->amount($value);
                    if ($value !== '' && $normalized[$header] === null) {
                        $errors[] = ucfirst(str_replace('_', ' ', $header)).' tidak valid.';
                    }
                } else {
                    $normalized[$header] = $value === '' ? null : $value;
                }
            }

            unset($raw['no_ktp']);
            $parsed[] = [
                'line_number' => $offset + 2,
                'sheet_type' => $sheetType,
                'raw_data' => $raw,
                'normalized_data' => $normalized,
                'nik' => $nik,
                'status' => $errors === [] ? 'Baru' : 'Tidak Valid',
                'errors' => $errors,
            ];
        }

        return $parsed;
    }

    public function resolvePreviewRows(Branch $branch, array $parsed): array
    {
        foreach ($parsed as &$row) {
            if ($row['status'] === 'Tidak Valid') {
                continue;
            }
            $resolution = $this->previewResolve($branch->id, $row['sheet_type'], $row['normalized_data']);
            if ($resolution['application'] === null) {
                if ($row['sheet_type'] === 'bi_checking') {
                    $row['resolution'] = ['application' => null, 'will_create' => true];
                    $row['status'] = 'Baru';
                    $row['errors'][] = 'Aplikasi baru akan dibuat dari id_kons.';
                } else {
                    $row['status'] = 'Perlu Diperiksa';
                    $row['errors'][] = 'Rantai proses belum tersedia: '.self::CHAIN[$row['sheet_type']]['resolve'].' tidak ditemukan di database.';
                    $row['resolution'] = $resolution;
                }

                continue;
            }
            $produce = self::CHAIN[$row['sheet_type']]['produce'];
            $sourceId = $row['normalized_data'][$produce] ?? null;
            $duplicate = $sourceId !== null && ConsumerStageEvent::query()
                ->where('application_id', $resolution['application'])
                ->where('stage', $row['sheet_type'])
                ->where('source_id', $sourceId)
                ->exists();
            if ($duplicate) {
                $row['status'] = 'Duplikat';
                $row['errors'][] = 'Baris sudah pernah diimpor (source_id sama).';
            }
            $row['resolution'] = $resolution;
        }

        return $parsed;
    }

    public function stageBatch(array $rows, int $userId, int $branchId): HistoricalProcessImportBatch
    {
        $valid = collect($rows)->where('status', 'Baru')->count();

        return DB::transaction(function () use ($rows, $userId, $branchId, $valid) {
            $batch = HistoricalProcessImportBatch::create([
                'public_id' => (string) Str::uuid(),
                'uploaded_by' => $userId,
                'branch_id' => $branchId,
                'status' => 'preview_ready',
                'expires_at' => now()->addHour(),
                'total_rows' => count($rows),
                'valid_rows' => $valid,
                'invalid_rows' => count($rows) - $valid,
            ]);
            $stored = [];
            foreach ($rows as $row) {
                $stored[] = [
                    'line_number' => $row['line_number'],
                    'sheet_type' => $row['sheet_type'],
                    'raw_data' => $row['raw_data'],
                    'normalized_data' => $row['normalized_data'],
                    'nik' => $row['nik'] ?? null,
                    'status' => $row['status'],
                    'errors' => $row['errors'],
                ];
            }
            $batch->rows()->createMany($stored);

            return $batch;
        });
    }

    public function confirm(HistoricalProcessImportBatch $batch): array
    {
        return DB::transaction(function () use ($batch) {
            $locked = HistoricalProcessImportBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
            Branch::query()->whereKey($locked->branch_id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'preview_ready' || $locked->expires_at->isPast()) {
                throw new \RuntimeException('Preview impor tidak lagi dapat dikonfirmasi.', 409);
            }
            $rows = $locked->rows()->lockForUpdate()->orderBy('line_number')->get();
            $created = $skipped = 0;
            foreach ($rows as $row) {
                if ($row->status !== 'Baru') {
                    $skipped++;

                    continue;
                }
                $resolution = $this->confirmResolve($locked->branch_id, $row->sheet_type, $row->normalized_data, $row->nik);
                if ($resolution['application'] === null) {
                    $skipped++;

                    continue;
                }
                $produce = self::CHAIN[$row->sheet_type]['produce'];
                $sourceId = $row->normalized_data[$produce] ?? null;
                $exists = $sourceId !== null && ConsumerStageEvent::query()
                    ->where('application_id', $resolution['application'])
                    ->where('stage', $row->sheet_type)
                    ->where('source_id', $sourceId)
                    ->exists();
                if ($exists) {
                    $skipped++;

                    continue;
                }
                $this->applyRow($resolution['application'], $row);
                $created++;
            }
            $locked->update([
                'status' => 'completed',
                'confirmed_at' => now(),
                'created_rows' => $created,
            ]);

            return ['created' => $created, 'skipped' => $skipped, 'batch' => $locked];
        });
    }

    private function applyRow(int $applicationId, $row): void
    {
        $sheetType = $row->sheet_type;
        $normalized = $row->normalized_data;
        $produce = self::CHAIN[$sheetType]['produce'];

        if ($sheetType === 'bi_checking') {
            $application = ConsumerApplication::find($applicationId);
            if ($application !== null) {
                $application->update([
                    'id_kavling' => $normalized['id_kavling'],
                    'nama_konsumen' => $normalized['nama_konsumen'],
                    'nik' => $row->nik,
                ]);
            }
        }

        $identity = ConsumerLegacyIdentity::query()->where('application_id', $applicationId)->first();
        if ($identity === null) {
            $identity = ConsumerLegacyIdentity::create(['application_id' => $applicationId]);
        }
        $identity->update([$produce => $normalized[$produce]]);

        $eventDate = null;
        foreach (self::DATE_FIELDS[$sheetType] as $dateField) {
            if (filled($normalized[$dateField] ?? null)) {
                $eventDate = $normalized[$dateField];
                break;
            }
        }

        $notes = json_encode($normalized, JSON_UNESCAPED_UNICODE);
        ConsumerStageEvent::create([
            'application_id' => $applicationId,
            'stage' => $sheetType,
            'source_id' => $normalized[$produce],
            'event_date' => $eventDate,
            'status' => $normalized['status'] ?? null,
            'notes' => $notes !== false ? $notes : null,
        ]);

        if ($sheetType === 'proses_bank') {
            ConsumerBankProcess::create([
                'application_id' => $applicationId,
                'id_berkas' => $normalized['id_berkas'] ?? null,
                'no_sp3k' => $normalized['no_sp3k'] ?? null,
                'response_type' => $normalized['jenis_respon'] ?? null,
                'approved_plafond' => $normalized['approved_plafond'] ?? null,
                'approved_tenor' => $normalized['approved_tenor'] ?? null,
                'status' => $normalized['status'] ?? null,
                'revision_category' => $normalized['kategori_revisi'] ?? null,
                'revision_detail' => $normalized['detail_revisi'] ?? null,
                'obstacle' => $normalized['kendala'] ?? null,
                'notes' => $normalized['keterangan'] ?? null,
            ]);
        }
    }

    private function previewResolve(int $branchId, string $sheetType, array $normalized): array
    {
        $chain = self::CHAIN[$sheetType];
        if ($sheetType === 'bi_checking') {
            $idKons = $normalized['id_kons'] ?? null;
            if ($idKons === null) {
                return ['application' => null];
            }
            $application = ConsumerLegacyIdentity::query()
                ->where('id_kons', $idKons)
                ->whereHas('application', fn ($q) => $q->where('branch_id', $branchId))
                ->value('application_id');

            return ['application' => $application];
        }

        $resolveColumn = $chain['resolve'];
        $resolveValue = $normalized[$resolveColumn] ?? null;
        if ($resolveValue === null || $resolveValue === '') {
            return ['application' => null];
        }

        return ['application' => $this->resolveByIdentity($branchId, $resolveColumn, $resolveValue)];
    }

    private function confirmResolve(int $branchId, string $sheetType, array $normalized, ?string $nik): array
    {
        $chain = self::CHAIN[$sheetType];
        if ($sheetType === 'bi_checking') {
            $idKons = $normalized['id_kons'] ?? null;
            if ($idKons === null) {
                return ['application' => null];
            }
            $application = ConsumerLegacyIdentity::query()
                ->where('id_kons', $idKons)
                ->whereHas('application', fn ($q) => $q->where('branch_id', $branchId))
                ->value('application_id');
            if ($application !== null) {
                return ['application' => $application];
            }
            $application = ConsumerApplication::create([
                'branch_id' => $branchId,
                'id_kavling' => $normalized['id_kavling'],
                'nama_konsumen' => $normalized['nama_konsumen'],
                'nik' => $nik,
            ])->id;
            ConsumerLegacyIdentity::create(['application_id' => $application, 'id_kons' => $idKons]);

            return ['application' => $application];
        }

        $resolveColumn = $chain['resolve'];
        $resolveValue = $normalized[$resolveColumn] ?? null;
        if ($resolveValue === null || $resolveValue === '') {
            return ['application' => null];
        }

        return ['application' => $this->resolveByIdentity($branchId, $resolveColumn, $resolveValue)];
    }

    private function resolveByIdentity(int $branchId, string $column, string $value): ?int
    {
        return ConsumerLegacyIdentity::query()
            ->where($column, $value)
            ->whereHas('application', fn ($q) => $q->where('branch_id', $branchId))
            ->value('application_id');
    }

    private function date(string $value): ?string
    {
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $value, $matches)) {
            $date = DateTimeImmutable::createFromFormat('!n/j/Y', "{$matches[1]}/{$matches[2]}/{$matches[3]}");
            $errors = DateTimeImmutable::getLastErrors();
            if ($date && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)) && (int) $date->format('n') === (int) $matches[1] && (int) $date->format('j') === (int) $matches[2]) {
                return $date->format('Y-m-d');
            }
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            $errors = DateTimeImmutable::getLastErrors();
            if ($date && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)) && $date->format('Y-m-d') === $value) {
                return $value;
            }
        }

        return null;
    }

    private function amount(string $value): ?string
    {
        $value = trim(str_replace(['Rp', 'rp', ' '], '', $value));
        if ($value === '' || ! preg_match('/^[0-9.,]+$/', $value)) {
            return null;
        }
        if (str_contains($value, ',') && str_contains($value, '.')) {
            $value = str_replace(['.', ','], ['', '.'], $value);
        } elseif (str_contains($value, ',')) {
            $value = substr_count($value, ',') === 1 && preg_match('/,\d{1,2}$/', $value)
                ? str_replace(',', '.', $value)
                : str_replace(',', '', $value);
        } elseif (str_contains($value, '.')) {
            $parts = explode('.', $value);
            if (count($parts) > 1 && strlen(end($parts)) <= 2 && count($parts) === 2) {
                // keep as decimal
            } else {
                $value = str_replace('.', '', $value);
            }
        }
        $value = ltrim($value, '0');
        if ($value === '' || $value === '.') {
            return '0.00';
        }

        return number_format((float) $value, 2, '.', '');
    }

    public function stageLabels(): array
    {
        return self::STAGE_LABELS;
    }
}
