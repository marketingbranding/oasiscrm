<?php

namespace App\Services;

use DateTimeImmutable;

class PromoTsvParser
{
    public const HEADERS = ['id_promo', 'nama_promo', 'tanggal_mulai', 'tanggal_selesai', 'keterangan'];

    public function parse(string $input, array $existingCodes = []): array
    {
        $existingCodes = array_change_key_case($existingCodes, CASE_UPPER);

        if (strlen($input) > 262144) {
            throw new \InvalidArgumentException('Data TSV maksimal 256KB.');
        }

        $input = preg_replace('/^\xEF\xBB\xBF/', '', str_replace(["\r\n", "\r"], "\n", $input));
        $lines = explode("\n", $input);
        while ($lines !== [] && trim(end($lines)) === '') {
            array_pop($lines);
        }

        $first = array_map('trim', str_getcsv($lines[0] ?? '', "\t"));
        $hasHeader = $first === self::HEADERS;
        if (! $hasHeader && array_intersect($first, self::HEADERS) !== []) {
            throw new \InvalidArgumentException('Header harus tepat: '.implode("\t", self::HEADERS));
        }
        if ($hasHeader) {
            array_shift($lines);
        }
        if (count($lines) > 500) {
            throw new \InvalidArgumentException('Data TSV maksimal 500 baris.');
        }

        $parsed = [];
        foreach ($lines as $offset => $line) {
            $values = array_map('trim', str_getcsv($line, "\t"));
            $errors = [];
            if (count($values) !== 5) {
                $errors[] = 'Jumlah kolom harus 5.';
            }
            $values = array_pad(array_slice($values, 0, 5), 5, '');
            [$code, $name, $start, $end, $description] = $values;
            $code = mb_strtoupper($code);
            if ($code === '' || mb_strlen($code) > 100 || ! preg_match('/^[A-Z0-9][A-Z0-9._-]*$/', $code)) {
                $errors[] = 'ID promo wajib diisi dengan huruf, angka, titik, garis bawah, atau tanda hubung; maksimal 100 karakter.';
            }
            if ($name === '' || mb_strlen($name) > 255) {
                $errors[] = 'Nama promo wajib diisi, maksimal 255 karakter.';
            }
            if (mb_strlen($description) > 5000) {
                $errors[] = 'Keterangan maksimal 5000 karakter.';
            }
            $startDate = $this->date($start);
            $endDate = $this->date($end);
            if ($start !== '' && $startDate === null) {
                $errors[] = 'Tanggal mulai tidak valid.';
            }
            if ($end !== '' && $endDate === null) {
                $errors[] = 'Tanggal selesai tidak valid.';
            }
            if ($startDate && $endDate && $startDate > $endDate) {
                $errors[] = 'Tanggal selesai harus sama atau setelah tanggal mulai.';
            }
            $parsed[] = [
                'line_number' => $offset + ($hasHeader ? 2 : 1),
                'raw_data' => array_combine(self::HEADERS, $values),
                'normalized_data' => ['code' => $code, 'name' => $name, 'start_date' => $startDate, 'end_date' => $endDate, 'description' => $description ?: null],
                'status' => $errors === [] ? (array_key_exists($code, $existingCodes) ? 'Update' : 'Baru') : 'Tidak Valid',
                'errors' => $errors,
            ];
        }

        $counts = array_count_values(array_column(array_column($parsed, 'normalized_data'), 'code'));
        foreach ($parsed as &$row) {
            if ($row['normalized_data']['code'] !== '' && ($counts[$row['normalized_data']['code']] ?? 0) > 1) {
                $row['status'] = 'Duplikat Input';
                $row['errors'][] = 'ID promo duplikat dalam input.';
            }
        }

        return $parsed;
    }

    private function date(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
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
}
