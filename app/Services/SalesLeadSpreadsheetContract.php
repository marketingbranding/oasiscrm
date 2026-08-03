<?php

namespace App\Services;

use App\Exceptions\SalesLeadSpreadsheetContractException;
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
                ['id_lead', 'id_promo', 'tanggal_lead', 'sumber', 'platform', 'campaign', 'nama_konsumen', 'no_hp', 'proyek', 'sales_pic', 'status_lead', 'keterangan'],
                ['id_lead'],
                [
                    'id_promo' => ['type' => 'select', 'strict' => true],
                    'tanggal_lead' => ['type' => 'date'],
                    'sumber' => ['type' => 'select', 'strict' => true, 'values' => ['Canvasing', 'Event', 'Freelance', 'Lead Cabang', 'Online', 'Pameran', 'Refferal']],
                    'sales_pic' => ['type' => 'select', 'strict' => true],
                    'status_lead' => ['type' => 'select', 'strict' => true, 'values' => ['No Respon', 'Diskusi', 'UTJ', 'Tidak Lolos BI Checking', 'Akad', 'Cek Lokasi', 'Cek Silk', 'Jadi Freelance']],
                ],
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
        $definition = $this->definitions()[$sheetName] ?? throw SalesLeadSpreadsheetContractException::unknownSheet($sheetName);
        $branch = $lead->branch()->first();

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
            $this->validateHeaders($definition, $headers);

            $headerMap = array_flip($headers);
            $formulaOwnedHeaders = [];
            foreach ($headers as $index => $header) {
                if (str_starts_with(trim((string) ($formulas[1][$index] ?? '')), '=')) {
                    $formulaOwnedHeaders[] = $header;
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
            $this->validateColumnMetadata($definition, $headerMap, $metadata[$sheetName] ?? []);

            return new ResolvedSalesLeadSpreadsheetContract(
                $spreadsheetId,
                $definition,
                (int) $sheetIds[$sheetName],
                $headers,
                $headerMap,
                $formulaOwnedHeaders,
                2,
            );
        } catch (SalesLeadSpreadsheetContractException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw SalesLeadSpreadsheetContractException::inspectionFailed();
        }
    }

    public function normalizeReadStatus(string $value): string
    {
        return strcasecmp(trim($value), 'Cek Silk') === 0 ? 'Cek SLIK' : trim($value);
    }

    private function validateHeaders(SalesLeadSheetDefinition $definition, array $headers): void
    {
        $missing = array_values(array_diff($definition->requiredHeaders, $headers));
        if ($missing !== []) {
            throw SalesLeadSpreadsheetContractException::headersMissing($definition->sheetName, $missing);
        }

        $positions = array_map(fn ($header) => array_search($header, $headers, true), $definition->requiredHeaders);
        $sorted = $positions;
        sort($sorted);
        if ($positions !== $sorted) {
            throw SalesLeadSpreadsheetContractException::headerOrderInvalid($definition->sheetName);
        }
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
                $actualValues = array_values($actual['options'] ?? []);
                sort($actualValues);
                $expectedValues = $expected['values'];
                sort($expectedValues);
                if ($actualValues !== $expectedValues) {
                    throw SalesLeadSpreadsheetContractException::validationInvalid($definition->sheetName, $header);
                }
            }
        }
    }
}
