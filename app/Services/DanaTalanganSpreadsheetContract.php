<?php

namespace App\Services;

use App\Exceptions\DanaTalanganSpreadsheetContractException;
use App\ValueObjects\ResolvedDanaTalanganSpreadsheetContract;
use Carbon\CarbonImmutable;
use Throwable;

class DanaTalanganSpreadsheetContract
{
    public const SHEET = 'Talangan';

    public const BUSINESS_HEADERS = [
        'No', 'Tanggal', 'Nama Konsumen', 'Kav', 'Proyek', 'Pinjam Nama', 'Pekerjaan', 'Status Kawin',
        'Umur', 'Marketing', 'TGL Komitmen', 'Penyelesaian', 'Konfirmasi', 'Status Cicilan',
    ];

    public const META_HEADERS = ['oasis_sync_id', 'oasis_deleted_at', 'oasis_deleted_by'];

    public const HEADERS = [
        ...self::BUSINESS_HEADERS,
        ...self::META_HEADERS,
    ];

    public function __construct(private readonly GoogleSheetsApiService $googleSheets) {}

    public function resolve(): ResolvedDanaTalanganSpreadsheetContract
    {
        $spreadsheetId = trim((string) config('services.google_sheets.dana_talangan_spreadsheet_id'));
        $sheetName = (string) config('services.google_sheets.dana_talangan_sheet_name', self::SHEET);
        if ($spreadsheetId === '') {
            throw new DanaTalanganSpreadsheetContractException('DANA_TALANGAN_SHEET_ID belum dikonfigurasi.');
        }
        if ($sheetName !== self::SHEET) {
            throw new DanaTalanganSpreadsheetContractException('Tab Dana Talangan harus bernama Talangan.');
        }

        try {
            $sheetIds = $this->googleSheets->sheetIds($spreadsheetId);
            if (! isset($sheetIds[self::SHEET])) {
                throw new DanaTalanganSpreadsheetContractException('Tab Talangan tidak ditemukan.');
            }
            $rows = $this->googleSheets->batchGetRaw(
                $spreadsheetId,
                [$this->googleSheets->quoteSheetName(self::SHEET).'!A1:Q1'],
                'FORMATTED_VALUE',
            )[self::SHEET] ?? [];
            $headers = array_map(fn ($value) => trim((string) $value), array_pad($rows[0] ?? [], 17, ''));
            if (array_map($this->normalize(...), $headers) !== array_map($this->normalize(...), self::HEADERS)) {
                throw new DanaTalanganSpreadsheetContractException('Header A:Q tab Talangan tidak sesuai kontrak OASIS.');
            }
            if (array_slice($headers, 14, 3) !== self::META_HEADERS) {
                throw new DanaTalanganSpreadsheetContractException('Header metadata O:Q tab Talangan tidak aman.');
            }
            $metadata = $this->googleSheets->gridMetadata($spreadsheetId, self::SHEET, 'A:Q');
            if (($metadata['formulas'] ?? []) !== []) {
                throw new DanaTalanganSpreadsheetContractException('Formula ditemukan pada A:Q tab Talangan.');
            }
            if (($metadata['validations'] ?? []) !== []) {
                throw new DanaTalanganSpreadsheetContractException('Validasi data ditemukan pada A:Q tab Talangan.');
            }
            $hash = hash('sha256', json_encode([
                'spreadsheet_id' => $spreadsheetId,
                'sheet' => self::SHEET,
                'headers' => $headers,
                'formulas' => $metadata['formulas'] ?? [],
                'validations' => $metadata['validations'] ?? [],
                'sheet_id' => $metadata['sheet_id'] ?? $sheetIds[self::SHEET],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return new ResolvedDanaTalanganSpreadsheetContract(
                $spreadsheetId,
                self::SHEET,
                (int) ($metadata['sheet_id'] ?? $sheetIds[self::SHEET]),
                $headers,
                $metadata,
                $hash,
            );
        } catch (DanaTalanganSpreadsheetContractException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            throw new DanaTalanganSpreadsheetContractException('Kontrak spreadsheet Dana Talangan tidak dapat diperiksa.');
        }
    }

    public function rows(ResolvedDanaTalanganSpreadsheetContract $contract): array
    {
        $values = $this->googleSheets->batchGetRaw(
            $contract->spreadsheetId,
            [$this->googleSheets->quoteSheetName(self::SHEET).'!A:Q'],
            'FORMATTED_VALUE',
        )[self::SHEET] ?? [];
        $rows = [];
        foreach (array_slice($values, 1) as $offset => $cells) {
            $cells = array_pad($cells, 17, '');
            $row = ['_row_number' => $offset + 2];
            foreach (self::HEADERS as $index => $header) {
                $row[$header] = trim((string) ($cells[$index] ?? ''));
            }
            $rows[] = $row;
        }

        return $rows;
    }

    public function valueForWrite(mixed $value): mixed
    {
        return is_string($value) && preg_match('/^[=+\-@]/', $value) ? "'".$value : $value;
    }

    public function normalizeBusinessPayload(array $row): array
    {
        $payload = [];
        foreach (self::BUSINESS_HEADERS as $field) {
            $payload[$field] = trim((string) ($row[$field] ?? ''));
            if (preg_match("/^'[=+\\-@]/", $payload[$field])) {
                $payload[$field] = substr($payload[$field], 1);
            }
        }
        foreach (['Tanggal', 'TGL Komitmen'] as $field) {
            $payload[$field] = $this->date($payload[$field]) ?? '';
        }
        foreach (['Pinjam Nama', 'Konfirmasi'] as $field) {
            $payload[$field] = in_array(mb_strtolower($payload[$field]), ['1', 'true', 'ya', 'iya', 'yes', 'y', '✓'], true) ? '1' : '0';
        }
        $payload['Status Cicilan'] = str_replace(' ', '_', mb_strtolower($payload['Status Cicilan']));
        $payload['Umur'] = $payload['Umur'] === '' ? '' : (string) ((int) $payload['Umur']);

        return $payload;
    }

    private function date(string $value): ?string
    {
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'm/d/Y'] as $format) {
            try {
                $date = CarbonImmutable::createFromFormat('!'.$format, trim($value));
                if ($date && $date->format($format) === trim($value)) {
                    return $date->format('Y-m-d');
                }
            } catch (Throwable) {
            }
        }

        return null;
    }

    private function normalize(mixed $value): string
    {
        return mb_strtolower(preg_replace('/[^a-z0-9]+/i', '', trim((string) $value)));
    }
}
