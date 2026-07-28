<?php

namespace App\Services;

use App\Exports\UserImportTemplateExport;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Throwable;
use ZipArchive;

class UserImportParser
{
    public const FIELDS = [
        'name', 'email', 'role', 'primary_branch', 'additional_branches',
        'primary_project', 'additional_projects', 'supervisor_email', 'status',
    ];

    /** @return array<int, array{row_number:int, raw_data:array<string, string>, parser_errors:array<int, string>}> */
    public function parse(UploadedFile $file): array
    {
        $path = $file->getRealPath();
        if ($path === false) {
            $this->invalid('File XLSX tidak dapat dibaca. Silakan unggah ulang file yang valid.');
        }

        $spreadsheet = null;
        try {
            $this->assertSafeXlsxContainer($path);
            if (IOFactory::identify($path) !== IOFactory::READER_XLSX) {
                $this->invalid('File harus berupa workbook XLSX yang valid. CSV tidak didukung.');
            }

            $reader = IOFactory::createReader(IOFactory::READER_XLSX);
            $sheetNames = $reader->listWorksheetNames($path);
            if (! in_array('IMPORT USER', $sheetNames, true)) {
                $this->invalid('Sheet IMPORT USER tidak ditemukan. Gunakan template resmi Oasis.');
            }

            $sheetInfo = collect($reader->listWorksheetInfo($path))->firstWhere('worksheetName', 'IMPORT USER');
            if (($sheetInfo['totalRows'] ?? 0) > 502) {
                $this->invalid('Sheet IMPORT USER melebihi batas 502 baris fisik. Hapus baris berlebih lalu unggah kembali.');
            }

            $reader->setReadDataOnly(false);
            $reader->setLoadSheetsOnly(['IMPORT USER']);
            $reader->setReadFilter(new UserImportReadFilter);
            $reader->setIncludeCharts(false);
            $spreadsheet = $reader->load($path);
            $sheet = $spreadsheet->getSheetByName('IMPORT USER');
            if ($sheet === null) {
                $this->invalid('Sheet IMPORT USER tidak dapat dibaca. Gunakan template resmi Oasis.');
            }

            $actualHeaders = [];
            foreach (range(1, 9) as $column) {
                $actualHeaders[] = $this->normalizeHeader((string) $sheet->getCell(Coordinate::stringFromColumnIndex($column).'1')->getValue());
            }
            $expectedHeaders = array_map($this->normalizeHeader(...), UserImportTemplateExport::HEADERS);
            if ($actualHeaders !== $expectedHeaders) {
                $this->invalid('Header sheet IMPORT USER tidak sesuai template. Gunakan tepat: '.implode(', ', UserImportTemplateExport::HEADERS).'.');
            }

            $rows = [];
            for ($rowNumber = 2; $rowNumber <= min(502, max(2, $sheet->getHighestDataRow())); $rowNumber++) {
                $values = [];
                $errors = [];
                foreach (range(1, 9) as $column) {
                    $coordinate = Coordinate::stringFromColumnIndex($column).$rowNumber;
                    $cell = $sheet->getCell($coordinate);
                    $value = $cell->getValue();
                    $text = is_scalar($value) ? (string) $value : '';
                    $values[] = $text;

                    if ($cell->getDataType() === DataType::TYPE_FORMULA || preg_match('/^[=+\-@]/u', ltrim($text)) === 1) {
                        $errors[] = 'Kolom '.Coordinate::stringFromColumnIndex($column).' mengandung formula atau nilai berawalan karakter yang tidak aman.';
                    }
                }

                if (collect($values)->every(fn (string $value) => trim($value) === '')) {
                    continue;
                }
                if ($values[0] === UserImportTemplateExport::EXAMPLE_MARKER) {
                    continue;
                }

                $rows[] = [
                    'row_number' => $rowNumber,
                    'raw_data' => array_combine(self::FIELDS, $values),
                    'parser_errors' => array_values(array_unique($errors)),
                ];
            }

            if (count($rows) > 500) {
                $this->invalid('Maksimal 500 baris data pengguna dapat diproses dalam satu file.');
            }

            return $rows;
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable) {
            $this->invalid('Workbook XLSX rusak, terenkripsi, atau dilindungi kata sandi sehingga tidak dapat dibaca.');
        } finally {
            if ($spreadsheet instanceof Spreadsheet) {
                $spreadsheet->disconnectWorksheets();
            }
        }
    }

    private function normalizeHeader(string $value): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($value)) ?? '');
    }

    private function assertSafeXlsxContainer(string $path): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            $this->invalid('File harus berupa workbook XLSX yang valid. CSV tidak didukung.');
        }

        try {
            if ($zip->numFiles > 2000 || $zip->locateName('[Content_Types].xml') === false || $zip->locateName('xl/workbook.xml') === false) {
                $this->invalid('Struktur workbook XLSX tidak valid atau terlalu kompleks untuk diproses.');
            }

            $uncompressedBytes = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                $name = str_replace('\\', '/', (string) ($stat['name'] ?? ''));
                if ($name === '' || str_starts_with($name, '/') || preg_match('#(^|/)\.\.(/|$)#', $name) === 1) {
                    $this->invalid('Struktur workbook XLSX mengandung path yang tidak aman.');
                }
                $uncompressedBytes += (int) ($stat['size'] ?? 0);
                if ($uncompressedBytes > 50 * 1024 * 1024) {
                    $this->invalid('Isi workbook XLSX terlalu besar untuk diproses dengan aman.');
                }
            }
        } finally {
            $zip->close();
        }
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
