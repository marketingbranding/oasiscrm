<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

class UserImportReadFilter implements IReadFilter
{
    public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
    {
        return $worksheetName === 'IMPORT USER'
            && $row >= 1
            && $row <= 502
            && strlen($columnAddress) === 1
            && $columnAddress >= 'A'
            && $columnAddress <= 'J';
    }
}
