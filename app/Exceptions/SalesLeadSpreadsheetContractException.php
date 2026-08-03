<?php

namespace App\Exceptions;

use RuntimeException;

class SalesLeadSpreadsheetContractException extends RuntimeException
{
    public static function branchUnavailable(): self
    {
        return new self('Cabang lead tidak tersedia atau sudah tidak aktif.');
    }

    public static function spreadsheetMissing(): self
    {
        return new self('Spreadsheet cabang belum dikonfigurasi.');
    }

    public static function unknownSheet(string $sheetName): self
    {
        return new self("Kontrak tab {$sheetName} tidak terdaftar.");
    }

    public static function sheetMissing(string $sheetName): self
    {
        return new self("Tab {$sheetName} tidak ditemukan pada spreadsheet cabang.");
    }

    /** @param list<string> $headers */
    public static function headersMissing(string $sheetName, array $headers): self
    {
        return new self("Tab {$sheetName} tidak memiliki header wajib: ".implode(', ', $headers).'.');
    }

    public static function headerOrderInvalid(string $sheetName): self
    {
        return new self("Urutan header wajib pada tab {$sheetName} tidak sesuai kontrak.");
    }

    public static function formulaMissing(string $sheetName, string $header): self
    {
        return new self("Formula wajib pada kolom {$header} di tab {$sheetName} tidak ditemukan.");
    }

    public static function validationInvalid(string $sheetName, string $header): self
    {
        return new self("Validasi kolom {$header} di tab {$sheetName} tidak sesuai kontrak.");
    }

    public static function metadataUnsafe(string $sheetName): self
    {
        return new self("Kolom metadata OASIS pada tab {$sheetName} tidak aman untuk diprovisikan.");
    }

    public static function inspectionFailed(): self
    {
        return new self('Kontrak spreadsheet cabang tidak dapat diperiksa.');
    }

    public static function invalidOperationId(): self
    {
        return new self('UUID operasi spreadsheet tidak valid.');
    }

    public static function writeFailed(): self
    {
        return new self('Penulisan ke spreadsheet cabang gagal dan tidak dapat direkonsiliasi.');
    }
}
