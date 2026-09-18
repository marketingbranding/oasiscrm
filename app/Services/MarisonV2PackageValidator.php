<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use JsonException;
use SplFileObject;
use Throwable;

class MarisonV2PackageValidator
{
    public const CONTRACT = 'marison-v2-to-oasis';

    public const CONTRACT_VERSION = '1.0';

    public const SOURCE_SYSTEM = 'marison_v2';

    public const SOURCE_VERSION = 'V2.5.50';

    public const MAX_BYTES = 20 * 1024 * 1024;

    public const MAX_DEPTH = 64;

    private const ROOT_KEYS = ['contract', 'contract_version', 'source', 'manifest', 'transactions', 'unlinked_records'];

    private const PROCESS_KEYS = ['bi_checking', 'psjb', 'pemberkasan', 'bank_attempts', 'proses_bank', 'ppjb_dev', 'akad', 'bast'];

    private const COUNT_KEYS = ['transactions', 'bi_checking', 'psjb', 'pemberkasan', 'bank_attempts', 'proses_bank', 'ppjb_dev', 'akad', 'bast', 'kavling_events', 'status_events', 'unlinked_records'];

    private const SOURCE_IDS = [
        'bi_checking' => ['id_kons'],
        'psjb' => ['id_psjb'],
        'pemberkasan' => ['id_berkas'],
        'bank_attempts' => ['id_bank_attempt'],
        'proses_bank' => ['id_bank_attempt', 'no_sp3k'],
        'ppjb_dev' => ['id_ppjb_dev'],
        'akad' => ['no_ppjb_akad'],
        'bast' => ['no_bast'],
        'kavling' => ['event_id'],
        'status' => ['event_id'],
    ];

    private const FORBIDDEN_PII = [
        'nama_konsumen', 'nik', 'no_ktp', 'no_hp', 'nomor_hp', 'phone', 'phone_number',
        'alamat', 'address', 'alamat_lengkap', 'alamat_domisili', 'alamat_ktp', 'customer_address',
        'kontak_darurat', 'emergency_contact', 'emergency_contact_name', 'emergency_contact_phone',
        'nama_kontak_darurat', 'no_hp_darurat',
    ];

    public function validate(UploadedFile|string $file): array
    {
        try {
            $path = $file instanceof UploadedFile ? $file->getRealPath() : $file;
            if (! is_string($path) || $path === '' || ! is_file($path) || ! is_readable($path)) {
                $this->fail('package', 'Paket JSON tidak dapat dibaca.');
            }

            $size = filesize($path);
            if ($size === false || $size < 2 || $size > self::MAX_BYTES) {
                $this->fail('package', 'Ukuran paket JSON tidak valid atau melebihi 20 MB.');
            }

            $contents = (new SplFileObject($path, 'rb'))->fread($size + 1);
            if (strlen($contents) !== $size) {
                $this->fail('package', 'Paket JSON gagal dibaca secara lengkap.');
            }

            $package = json_decode($contents, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
            if (! is_array($package) || array_is_list($package)) {
                $this->fail('package', 'Root paket harus berupa objek JSON.');
            }

            $this->validatePackage($package);
            $hash = $this->hash($package, true);
            $declaredHash = $package['manifest']['payload_sha256'] ?? null;
            if ($declaredHash !== null && ! hash_equals(strtolower($declaredHash), $hash)) {
                $this->fail('manifest.payload_sha256', 'Hash payload paket tidak cocok.');
            }

            return ['package' => $package, 'package_hash' => $hash];
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (JsonException) {
            $this->fail('package', 'Paket bukan JSON valid atau terlalu dalam.');
        } catch (Throwable) {
            $this->fail('package', 'Paket JSON tidak dapat divalidasi.');
        }
    }

    public function hash(array $value, bool $excludeManifestHash = false): string
    {
        if ($excludeManifestHash && isset($value['manifest']) && is_array($value['manifest'])) {
            unset($value['manifest']['payload_sha256']);
        }

        return hash('sha256', json_encode($this->canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function validatePackage(array $package): void
    {
        $this->exactKeys($package, self::ROOT_KEYS, 'package');
        $this->expect($package['contract'] === self::CONTRACT, 'contract', 'Kontrak paket tidak didukung.');
        $this->expect($package['contract_version'] === self::CONTRACT_VERSION, 'contract_version', 'Versi kontrak paket tidak didukung.');
        $this->object($package['source'], ['branch_code', 'spreadsheet_id', 'v2_version', 'exported_at'], 'source');
        $this->exactKeys($package['source'], ['branch_code', 'spreadsheet_id', 'v2_version', 'exported_at'], 'source');
        $this->boundedString($package['source']['branch_code'], 2, 20, 'source.branch_code');
        $this->boundedString($package['source']['spreadsheet_id'], 1, 255, 'source.spreadsheet_id');
        $this->expect($package['source']['v2_version'] === self::SOURCE_VERSION, 'source.v2_version', 'Versi sumber harus V2.5.50.');
        $this->expect(is_string($package['source']['exported_at']) && filter_var($package['source']['exported_at'], FILTER_VALIDATE_REGEXP, ['options' => ['regexp' => '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/']]) !== false, 'source.exported_at', 'Waktu ekspor harus berformat date-time RFC 3339.');
        $this->object($package['manifest'], ['counts'], 'manifest', false);
        $this->expect(is_array($package['manifest']['counts']) && ! array_is_list($package['manifest']['counts']), 'manifest.counts', 'Manifest counts harus berupa objek.');
        if (array_key_exists('payload_sha256', $package['manifest'])) {
            $hash = $package['manifest']['payload_sha256'];
            $this->expect($hash === null || (is_string($hash) && preg_match('/^[a-fA-F0-9]{64}$/D', $hash) === 1), 'manifest.payload_sha256', 'Hash payload harus SHA-256 heksadesimal.');
        }
        $this->expect(is_array($package['transactions']) && array_is_list($package['transactions']), 'transactions', 'Transactions harus berupa array.');
        $this->expect(is_array($package['unlinked_records']) && array_is_list($package['unlinked_records']), 'unlinked_records', 'Unlinked records harus berupa array.');
        $this->rejectPii($package);
        $this->validateTransactions($package);
        $this->validateUnlinked($package['unlinked_records']);
        $this->validateCounts($package);
    }

    private function validateTransactions(array $package): void
    {
        $rootIds = [];
        $sourceIds = [];
        foreach ($package['transactions'] as $index => $transaction) {
            $path = "transactions.{$index}";
            $required = ['id_transaksi_v2', 'id_konsumen_v2', 'branch_code', 'project_source_id', 'current_kavling', 'payment', 'source_state', 'lineage', 'process', 'history'];
            $this->object($transaction, $required, $path);
            $this->exactKeys($transaction, $required, $path);
            $id = $this->boundedString($transaction['id_transaksi_v2'], 1, 150, "{$path}.id_transaksi_v2");
            $this->expect(! isset($rootIds[$id]), "{$path}.id_transaksi_v2", 'ID transaksi duplikat dalam paket.');
            $rootIds[$id] = true;
            $this->expect($transaction['branch_code'] === $package['source']['branch_code'], "{$path}.branch_code", 'Kode cabang transaksi berbeda dari sumber paket.');
            $this->boundedString($transaction['project_source_id'], 1, 100, "{$path}.project_source_id");
            $this->nullableString($transaction['id_konsumen_v2'], "{$path}.id_konsumen_v2");
            $this->nullableString($transaction['current_kavling'], "{$path}.current_kavling");
            $this->object($transaction['payment'], [], "{$path}.payment", false);
            $this->expect(array_diff(array_keys($transaction['payment']), ['method', 'detail']) === [], "{$path}.payment", 'Payment memuat field yang tidak didukung.');
            foreach (['method', 'detail'] as $key) {
                if (array_key_exists($key, $transaction['payment'])) {
                    $this->nullableString($transaction['payment'][$key], "{$path}.payment.{$key}");
                }
            }
            $this->object($transaction['source_state'], [], "{$path}.source_state", false);
            $this->object($transaction['lineage'], [], "{$path}.lineage", false);
            $this->object($transaction['process'], self::PROCESS_KEYS, "{$path}.process");
            $this->exactKeys($transaction['process'], self::PROCESS_KEYS, "{$path}.process");
            $this->object($transaction['history'], ['kavling', 'status'], "{$path}.history");
            $this->exactKeys($transaction['history'], ['kavling', 'status'], "{$path}.history");

            foreach (self::PROCESS_KEYS as $group) {
                $this->records($transaction['process'][$group], "{$path}.process.{$group}", $group, $id, $sourceIds);
            }
            foreach (['kavling', 'status'] as $group) {
                $this->records($transaction['history'][$group], "{$path}.history.{$group}", $group, $id, $sourceIds);
            }
        }
    }

    private function records(mixed $records, string $path, string $group, string $transactionId, array &$sourceIds): void
    {
        $this->expect(is_array($records) && array_is_list($records), $path, 'Kumpulan record harus berupa array.');
        foreach ($records as $index => $record) {
            $this->expect(is_array($record) && ! array_is_list($record), "{$path}.{$index}", 'Record harus berupa objek.');
            $hasIdentity = collect(self::SOURCE_IDS[$group])->contains(fn (string $field) => isset($record[$field]) && is_scalar($record[$field]) && trim((string) $record[$field]) !== '');
            if ($group === 'bank_attempts' && ! $hasIdentity) {
                $hasIdentity = filled($record['id_berkas'] ?? null) && isset($record['attempt_no']) && is_numeric($record['attempt_no']);
            }
            if ($group === 'pemberkasan') {
                $hasIdentity = filled($record['id_berkas'] ?? null);
            }
            $this->expect($hasIdentity, "{$path}.{$index}", 'Record sumber wajib memiliki identitas proses yang stabil.');
            if (isset($record['id_transaksi_v2'])) {
                $this->expect((string) $record['id_transaksi_v2'] === $transactionId, "{$path}.{$index}.id_transaksi_v2", 'Record tertaut ke transaksi berbeda.');
            }
            foreach (self::SOURCE_IDS[$group] as $field) {
                if (! isset($record[$field]) || ! is_scalar($record[$field]) || trim((string) $record[$field]) === '') {
                    continue;
                }
                $recordValue = trim((string) $record[$field]);
                $this->expect(mb_strlen($recordValue) <= 150, "{$path}.{$index}.{$field}", 'ID sumber terlalu panjang.');
                $key = $group.':'.$field.':'.$recordValue;
                if (isset($sourceIds[$key]) && $sourceIds[$key] !== $transactionId) {
                    $this->fail("{$path}.{$index}.{$field}", 'Source ID yang sama tertaut ke transaksi berbeda.');
                }
                $sourceIds[$key] = $transactionId;
            }
        }
    }

    private function validateUnlinked(array $records): void
    {
        foreach ($records as $index => $record) {
            $path = "unlinked_records.{$index}";
            $this->object($record, ['source_sheet', 'reason', 'payload'], $path, false);
            $this->boundedString($record['source_sheet'], 1, 255, "{$path}.source_sheet");
            $this->boundedString($record['reason'], 1, 1000, "{$path}.reason");
            $this->expect(is_array($record['payload']) && ! array_is_list($record['payload']), "{$path}.payload", 'Payload unlinked harus berupa objek.');
            if (isset($record['source_row'])) {
                $this->expect($record['source_row'] === null || (is_int($record['source_row']) && $record['source_row'] >= 0), "{$path}.source_row", 'Nomor baris sumber tidak valid.');
            }
        }
    }

    private function validateCounts(array $package): void
    {
        $actual = array_fill_keys(self::COUNT_KEYS, 0);
        $actual['transactions'] = count($package['transactions']);
        $actual['unlinked_records'] = count($package['unlinked_records']);
        foreach ($package['transactions'] as $transaction) {
            foreach (self::PROCESS_KEYS as $key) {
                $actual[$key] += count($transaction['process'][$key]);
            }
            $actual['kavling_events'] += count($transaction['history']['kavling']);
            $actual['status_events'] += count($transaction['history']['status']);
        }
        foreach (self::COUNT_KEYS as $key) {
            $declared = $package['manifest']['counts'][$key] ?? null;
            $this->expect(is_int($declared) && $declared >= 0, "manifest.counts.{$key}", 'Manifest count wajib berupa bilangan bulat non-negatif.');
            $this->expect($declared === $actual[$key], "manifest.counts.{$key}", 'Manifest count tidak cocok dengan payload.');
        }
        foreach ($package['manifest']['counts'] as $key => $count) {
            $this->expect(is_string($key) && is_int($count) && $count >= 0, "manifest.counts.{$key}", 'Manifest count tidak valid.');
        }
    }

    private function rejectPii(array $value, string $path = 'package'): void
    {
        foreach ($value as $key => $item) {
            $normalized = mb_strtolower((string) $key);
            if (in_array($normalized, self::FORBIDDEN_PII, true)
                || str_contains($normalized, 'emergency')
                || str_contains($normalized, 'darurat')
                || str_contains($normalized, 'alamat')
                || str_contains($normalized, 'address')) {
                $this->fail("{$path}.{$key}", 'Paket memuat field PII yang dilarang.');
            }
            if (is_array($item)) {
                $this->rejectPii($item, "{$path}.{$key}");
            }
        }
    }

    private function canonicalize(array $value): array
    {
        if (array_is_list($value)) {
            return array_map(fn ($item) => is_array($item) ? $this->canonicalize($item) : $item, $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = $this->canonicalize($item);
            }
        }

        return $value;
    }

    private function object(mixed $value, array $required, string $path, bool $rejectExtra = true): void
    {
        $this->expect(is_array($value) && ! array_is_list($value), $path, 'Nilai harus berupa objek.');
        foreach ($required as $key) {
            $this->expect(array_key_exists($key, $value), "{$path}.{$key}", 'Field wajib tidak tersedia.');
        }
        if ($rejectExtra) {
            $this->exactKeys($value, $required, $path);
        }
    }

    private function exactKeys(array $value, array $allowed, string $path): void
    {
        $extra = array_diff(array_keys($value), $allowed);
        $this->expect($extra === [], $path, 'Objek memuat field yang tidak didukung.');
    }

    private function boundedString(mixed $value, int $min, int $max, string $path): string
    {
        $this->expect(is_string($value), $path, 'Nilai harus berupa string.');
        $length = mb_strlen($value);
        $this->expect($length >= $min && $length <= $max, $path, 'Panjang nilai tidak valid.');

        return $value;
    }

    private function nullableString(mixed $value, string $path): void
    {
        $this->expect($value === null || is_string($value), $path, 'Nilai harus berupa string atau null.');
    }

    private function expect(bool $condition, string $key, string $message): void
    {
        if (! $condition) {
            $this->fail($key, $message);
        }
    }

    private function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
