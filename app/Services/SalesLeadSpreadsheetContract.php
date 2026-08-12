<?php

namespace App\Services;

use App\Exceptions\SalesLeadSpreadsheetContractException;
use App\Models\Branch;
use App\Models\SalesLead;
use App\ValueObjects\ResolvedSalesLeadSpreadsheetContract;
use App\ValueObjects\SalesLeadSheetDefinition;
use Throwable;

class SalesLeadSpreadsheetContract
{
    public const META_HEADERS = ['oasis_sync_id', 'oasis_deleted_at', 'oasis_deleted_by'];

    public function __construct(private GoogleSheetsApiService $googleSheets) {}

    /** @return array<string, SalesLeadSheetDefinition> */
    public function definitions(): array
    {
        return [
            'lead' => new SalesLeadSheetDefinition(
                'lead',
                ['id_lead', 'nama_promo', 'tanggal_lead', 'sumber_lead', 'kanal_masuk', 'aktivitas_lead', 'nama_konsumen', 'no_hp', 'proyek', 'sales_pic', 'status_lead', 'keterangan'],
                ['id_lead'],
                [
                    'nama_promo' => ['type' => 'select', 'strict' => true],
                    'tanggal_lead' => ['type' => 'date'],
                    'sumber_lead' => ['type' => 'select', 'strict' => true],
                    'kanal_masuk' => ['type' => 'select', 'strict' => true],
                    'aktivitas_lead' => ['type' => 'select', 'strict' => true],
                    'proyek' => ['type' => 'select', 'strict' => true],
                    'sales_pic' => ['type' => 'select', 'strict' => false],
                    'status_lead' => ['type' => 'select', 'strict' => true, 'values' => ['No Respon', 'Diskusi', 'Tatap Muka', 'Cek Lokasi', 'UTJ', 'Tidak Lolos BI Checking', 'Cek Slik', 'Jadi Freelance', 'Akad']],
                ],
                ['nama_promo' => ['nama_promo', 'id_promo']],
            ),
            'data_ceklok' => new SalesLeadSheetDefinition(
                'data_ceklok',
                ['nama_konsumen', 'tanggal_ceklok', 'waktu_ceklok', 'status_ceklok', 'keterangan'],
                ['nama_konsumen'],
                [
                    'tanggal_ceklok' => ['type' => 'date'],
                    'waktu_ceklok' => ['type' => 'select', 'strict' => true, 'values' => ['malam', 'pagi', 'siang', 'sore']],
                    'status_ceklok' => ['type' => 'select', 'strict' => true, 'values' => ['follow up', 'non ok', 'utj']],
                ],
            ),
            'data_sales' => new SalesLeadSheetDefinition(
                'data_sales',
                ['nik_sales', 'nama_sales', 'nik_koordinator', 'nama_koordinator'],
            ),
            'data_konsumen_nup' => new SalesLeadSheetDefinition(
                'data_konsumen_nup',
                ['nup', 'no_ktp', 'nama_konsumen', 'tanggal_lahir', 'pekerjaan', 'umur', 'alamat', 'kelurahan', 'kecamatan', 'kabupaten/kota', 'no_hp', 'nama_kondar', 'no_hp_kondar', 'keterangan'],
                ['umur'],
                ['tanggal_lahir' => ['type' => 'date']],
            ),
            'data_konsumen' => new SalesLeadSheetDefinition(
                'data_konsumen',
                ['id_kavling', 'no_ktp', 'nama_konsumen', 'tanggal_lahir', 'pekerjaan', 'detail_pekerjaan', 'umur', 'alamat', 'kelurahan', 'kecamatan', 'kabupaten/kota', 'no_hp', 'nama_kondar', 'no_hp_kondar', 'status_cash', 'Status', 'keterangan'],
                ['umur', 'status_konsumen', 'Status'],
                [
                    'id_kavling' => ['type' => 'select', 'strict' => true],
                    'tanggal_lahir' => ['type' => 'date'],
                    'status_cash' => ['type' => 'select', 'strict' => true, 'values' => ['YA', 'TIDAK']],
                ],
            ),
            'bi_checking' => new SalesLeadSheetDefinition(
                'bi_checking',
                ['id_kavling', 'no_ktp', 'id_kons', 'tanggal_slik', 'hasil_slik', 'keterangan'],
                ['no_ktp', 'id_kons'],
                [
                    'id_kavling' => ['type' => 'select', 'strict' => true],
                    'tanggal_slik' => ['type' => 'date'],
                    'hasil_slik' => ['type' => 'select', 'strict' => true, 'values' => ['OK', 'KOL 1', 'KOL 2', 'KOL 3', 'KOL 4', 'KOL 5', 'NO BIC']],
                ],
            ),
            'akad' => new SalesLeadSheetDefinition(
                'akad',
                ['id_kavling', 'id_ppjb_dev', 'no_ppjb_akad', 'tanggal_akad', 'kualitas_akad', 'lead_time_hari', 'status', 'keterangan_terlambat', 'keterangan'],
                ['id_ppjb_dev', 'no_ppjb_akad', 'status'],
                [
                    'id_kavling' => ['type' => 'select', 'strict' => true],
                    'tanggal_akad' => ['type' => 'date'],
                    'kualitas_akad' => ['type' => 'select', 'strict' => true, 'values' => ['Akad Bangunan Belum Jadi', 'Akad DP Belum Lunas', 'Akad KLT Belum Lunas', 'Akad Sempurna']],
                ],
            ),
        ];
    }

    public function resolve(SalesLead $lead, string $sheetName): ResolvedSalesLeadSpreadsheetContract
    {
        $branch = $lead->branch()->first();

        return $this->resolveForBranch($branch, $sheetName);
    }

    public function resolveForBranch(?Branch $branch, string $sheetName): ResolvedSalesLeadSpreadsheetContract
    {
        $definition = $this->definitions()[$sheetName] ?? throw SalesLeadSpreadsheetContractException::unknownSheet($sheetName);

        if ($branch === null || ! $branch->is_active) {
            throw SalesLeadSpreadsheetContractException::branchUnavailable();
        }

        $spreadsheetId = trim((string) $branch->sheet_id);
        if ($spreadsheetId === '') {
            throw SalesLeadSpreadsheetContractException::spreadsheetMissing();
        }

        try {
            $titles = $this->googleSheets->sheetTitles($spreadsheetId);
            if (! in_array($sheetName, $titles, true)) {
                throw SalesLeadSpreadsheetContractException::sheetMissing($sheetName);
            }

            $sheetIds = $this->googleSheets->sheetIds($spreadsheetId);
            if (! isset($sheetIds[$sheetName])) {
                throw SalesLeadSpreadsheetContractException::sheetMissing($sheetName);
            }

            $range = $this->googleSheets->quoteSheetName($sheetName).'!1:2';
            $raw = $this->googleSheets->batchGetRaw($spreadsheetId, [$range], 'FORMATTED_VALUE')[$sheetName] ?? [];
            $formulas = $this->googleSheets->batchGetRaw($spreadsheetId, [$range], 'FORMULA')[$sheetName] ?? [];
            $headers = array_map(fn ($header) => trim((string) $header), $raw[0] ?? []);
            [$headerMap, $resolvedHeaders] = $this->resolveHeaders($definition, $headers);
            $formulaOwnedHeaders = [];
            foreach ($definition->requiredHeaders as $canonicalHeader) {
                $index = $headerMap[$canonicalHeader];
                if (str_starts_with(trim((string) ($formulas[1][$index] ?? '')), '=')) {
                    $formulaOwnedHeaders[] = $canonicalHeader;
                }
            }
            foreach ($definition->formulaOwnedHeaders as $header) {
                if (! isset($headerMap[$header])) {
                    continue;
                }
                if (! str_starts_with(trim((string) ($formulas[1][$headerMap[$header]] ?? '')), '=')) {
                    throw SalesLeadSpreadsheetContractException::formulaMissing($sheetName, $header);
                }
            }

            $metadata = $this->googleSheets->columnMetadata($spreadsheetId, [$sheetName]);
            $sheetMetadata = $metadata[$sheetName] ?? [];
            if ($sheetName === 'lead' && ($sheetMetadata[$headerMap['sales_pic']]['strict'] ?? false)) {
                $this->googleSheets->makeColumnValidationWarningOnly($spreadsheetId, $sheetName, (int) $sheetIds[$sheetName], $headerMap['sales_pic']);
                $sheetMetadata[$headerMap['sales_pic']]['strict'] = false;
            }
            $this->validateColumnMetadata($definition, $headerMap, $sheetMetadata);
            $validationOptions = [];
            foreach ($definition->validations as $header => $expected) {
                $validationOptions[$header] = array_values($sheetMetadata[$headerMap[$header]]['options'] ?? []);
            }
            if (isset($validationOptions['nama_promo'])) {
                $validationOptions['id_promo'] = $validationOptions['nama_promo'];
            }

            return new ResolvedSalesLeadSpreadsheetContract(
                $spreadsheetId,
                $definition,
                (int) $sheetIds[$sheetName],
                $headers,
                $headerMap,
                $formulaOwnedHeaders,
                2,
                $validationOptions,
                $resolvedHeaders,
            );
        } catch (SalesLeadSpreadsheetContractException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw SalesLeadSpreadsheetContractException::inspectionFailed();
        }
    }

    public function normalizeReadStatus(string $value): string
    {
        return $this->normalizeValidationValue('status_lead', $value) === 'cek_slik'
            ? 'Cek Slik'
            : trim($value);
    }

    public function valueForWrite(ResolvedSalesLeadSpreadsheetContract $contract, string $header, mixed $value): mixed
    {
        if (! is_string($value) || $header !== 'status_lead') {
            return $value;
        }

        $normalized = $this->normalizeValidationValue($header, $value);
        foreach ($contract->validationOptions[$header] ?? [] as $option) {
            if ($this->normalizeValidationValue($header, $option) === $normalized) {
                return $option;
            }
        }

        return $value;
    }

    public function assertStrictValues(ResolvedSalesLeadSpreadsheetContract $contract, array $fields): void
    {
        foreach ($contract->definition->validations as $header => $validation) {
            if (! ($validation['strict'] ?? false) || ! array_key_exists($header, $fields) || blank($fields[$header])) {
                continue;
            }
            $candidate = $this->valueForWrite($contract, $header, $fields[$header]);
            if (! in_array($candidate, $contract->validationOptions[$header] ?? [], true)) {
                throw SalesLeadSpreadsheetContractException::valueInvalid($contract->definition->sheetName, $header);
            }
        }
    }

    private function resolveHeaders(SalesLeadSheetDefinition $definition, array $headers): array
    {
        $physicalMap = [];
        foreach ($headers as $index => $header) {
            if ($header !== '' && ! isset($physicalMap[$header])) {
                $physicalMap[$header] = $index;
            }
        }
        $headerMap = $physicalMap;
        $resolvedHeaders = [];
        $missing = [];
        foreach ($definition->requiredHeaders as $canonicalHeader) {
            $actual = collect($definition->headerAliases[$canonicalHeader] ?? [$canonicalHeader])
                ->first(fn (string $alias) => isset($physicalMap[$alias]));
            if ($actual === null) {
                $missing[] = $canonicalHeader;

                continue;
            }
            $headerMap[$canonicalHeader] = $physicalMap[$actual];
            $resolvedHeaders[$canonicalHeader] = $actual;
        }
        if ($missing !== []) {
            throw SalesLeadSpreadsheetContractException::headersMissing($definition->sheetName, $missing);
        }

        $positions = array_map(fn ($header) => $headerMap[$header], $definition->requiredHeaders);
        $sorted = $positions;
        sort($sorted);
        if ($positions !== $sorted) {
            throw SalesLeadSpreadsheetContractException::headerOrderInvalid($definition->sheetName);
        }

        return [$headerMap, $resolvedHeaders];
    }

    private function validateColumnMetadata(SalesLeadSheetDefinition $definition, array $headerMap, array $metadata): void
    {
        foreach ($definition->validations as $header => $expected) {
            $actual = $metadata[$headerMap[$header]] ?? null;
            if (($actual['type'] ?? null) !== $expected['type']) {
                throw SalesLeadSpreadsheetContractException::validationInvalid($definition->sheetName, $header);
            }
            if (isset($expected['strict']) && ($actual['strict'] ?? false) !== $expected['strict']) {
                throw SalesLeadSpreadsheetContractException::validationInvalid($definition->sheetName, $header);
            }
            if (isset($expected['values'])) {
                $actualValues = array_map(
                    fn (string $value) => $this->normalizeValidationValue($header, $value),
                    array_values($actual['options'] ?? []),
                );
                sort($actualValues);
                $expectedValues = array_map(
                    fn (string $value) => $this->normalizeValidationValue($header, $value),
                    $expected['values'],
                );
                sort($expectedValues);
                if ($actualValues !== $expectedValues) {
                    throw SalesLeadSpreadsheetContractException::validationInvalid($definition->sheetName, $header);
                }
            }
        }
    }

    private function normalizeValidationValue(string $header, string $value): string
    {
        $normalized = mb_strtolower(trim($value));
        if ($header === 'status_lead' && in_array($normalized, ['cek silk', 'cek slik'], true)) {
            return 'cek_slik';
        }

        return $normalized;
    }
}
