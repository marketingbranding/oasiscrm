<?php

namespace App\Services;

use Google\Client as GoogleClient;
use Google\Service\Sheets;
use GuzzleHttp\Client as GuzzleClient;
use RuntimeException;

class GoogleSheetsApiService
{
    private Sheets $sheets;

    public function __construct()
    {
        $credentialsPath = config('services.google_sheets.credentials_path');
        if (!$credentialsPath || !file_exists($credentialsPath)) {
            throw new RuntimeException('Google Sheets credentials file tidak ditemukan: ' . $credentialsPath);
        }

        $client = new GoogleClient();
        $client->setApplicationName('Oasis CRM');
        $client->setAuthConfig($credentialsPath);
        $client->setScopes([Sheets::SPREADSHEETS_READONLY]);

        if (!config('services.google_sheets.verify_ssl')) {
            $client->setHttpClient(new GuzzleClient(['verify' => false]));
        }

        $this->sheets = new Sheets($client);
    }

    public function batchGet(string $spreadsheetId, array $ranges): array
    {
        $response = $this->sheets->spreadsheets_values->batchGet($spreadsheetId, [
            'ranges' => $ranges,
            'majorDimension' => 'ROWS',
        ]);

        $result = [];
        foreach ($response->getValueRanges() as $valueRange) {
            $range = $valueRange->getRange();
            $sheetName = $this->sheetNameFromRange($range);
            $result[$sheetName] = $this->valuesToRows($valueRange->getValues() ?? []);
        }

        return $result;
    }

    private function sheetNameFromRange(string $range): string
    {
        $sheet = explode('!', $range, 2)[0];

        return trim($sheet, "'");
    }

    private function valuesToRows(array $values): array
    {
        if (count($values) < 2) {
            return [];
        }

        $rawHeaders = array_shift($values);
        $headers = [];
        $counts = [];
        foreach ($rawHeaders as $header) {
            $header = trim((string) $header);
            if ($header === '') {
                $headers[] = null;
                continue;
            }
            if (!isset($counts[$header])) {
                $counts[$header] = 0;
            }
            $counts[$header]++;
            $headers[] = $counts[$header] > 1 ? $header . '_' . $counts[$header] : $header;
        }

        $rows = [];
        foreach ($values as $cells) {
            $row = [];
            foreach ($headers as $index => $header) {
                if ($header === null) continue;
                $row[$header] = trim((string) ($cells[$index] ?? ''));
            }
            if (count(array_filter($row, fn ($value) => $value !== '')) > 0) {
                $rows[] = $row;
            }
        }

        return $rows;
    }
}
