<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleScriptService
{
    private ?string $webhookUrl;

    private int $timeout;

    public function __construct()
    {
        $this->webhookUrl = config('services.google_script.webhook_url');
        $this->timeout = config('services.google_script.timeout', 30);
    }

    public function sendData(array $data, string $endpoint = ''): array
    {
        $url = $endpoint
            ? rtrim($this->webhookUrl, '/') . '/' . ltrim($endpoint, '/')
            : $this->webhookUrl;

        if (!$url) {
            Log::warning('Google Script webhook URL not configured.');

            return ['success' => false, 'error' => 'Webhook URL not configured'];
        }

        try {
            $response = $this->client()->post($url, $data);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json(),
                ];
            }

            Log::warning('Google Script request failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return [
                'success' => false,
                'error' => $response->body(),
                'status' => $response->status(),
            ];
        } catch (\Exception $e) {
            Log::error('Google Script connection error: ' . $e->getMessage());

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function fetchData(array $params = []): array
    {
        if (!$this->webhookUrl) {
            return ['success' => false, 'error' => 'Webhook URL not configured'];
        }

        $cacheKey = 'google_script_' . md5(serialize($params));

        return Cache::remember($cacheKey, now()->addMinutes(5), function () use ($params) {
            try {
                $response = $this->client()->get($this->webhookUrl, $params);

                if ($response->successful()) {
                    return [
                        'success' => true,
                        'data' => $response->json(),
                    ];
                }

                return [
                    'success' => false,
                    'error' => $response->body(),
                ];
            } catch (\Exception $e) {
                return [
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
        });
    }

    public function fetchSheetNames(string $sheetId): array
    {
        $cacheKey = 'google_sheet_names_' . $sheetId;

        return Cache::remember($cacheKey, now()->addMinutes(5), function () use ($sheetId) {
            if (!$this->webhookUrl) {
                return ['success' => false, 'error' => 'Webhook URL not configured'];
            }

            try {
                $response = $this->client()->get($this->webhookUrl, [
                    'type' => 'sheets',
                    'sheet_id' => $sheetId,
                ]);

                if ($response->successful()) {
                    $data = $response->json();
                    if (is_array($data) && isset($data[0]['name'], $data[0]['gid'])) {
                        return ['success' => true, 'data' => $data];
                    }
                    return ['success' => false, 'error' => 'Unexpected response format'];
                }

                return ['success' => false, 'error' => $response->body()];
            } catch (\Exception $e) {
                return ['success' => false, 'error' => $e->getMessage()];
            }
        });
    }

    public function fetchSheetCsv(string $sheetId, ?string $sheetName = null): array
    {
        $cacheKey = 'google_sheet_csv_' . $sheetId . '_' . ($sheetName ?? 'default');

        return Cache::remember($cacheKey, now()->addMinutes(5), function () use ($sheetId, $sheetName) {
            if ($sheetName !== null && $this->webhookUrl) {
                try {
                    $response = $this->client()->get($this->webhookUrl, [
                        'type' => 'data',
                        'sheet_id' => $sheetId,
                        'sheet_name' => $sheetName,
                    ]);

                    if ($response->successful() && str_contains($response->header('Content-Type') ?? '', 'csv')) {
                        $body = $response->body();
                        $lines = explode("\n", trim($body));
                        if (count($lines) < 2) {
                            return ['success' => true, 'data' => []];
                        }

                        return [
                            'success' => true,
                            'data' => $this->parseCsvLines($lines),
                        ];
                    }

                    $body = $response->body();
                    return ['success' => false, 'error' => 'Failed: ' . substr($body, 0, 200)];
                } catch (\Exception $e) {
                    return ['success' => false, 'error' => $e->getMessage()];
                }
            }

            $url = "https://docs.google.com/spreadsheets/d/{$sheetId}/export?format=csv";
        

            try {
                $response = $this->client()->withoutVerifying()->get($url);

                if ($response->successful() && $response->header('Content-Type') === 'text/csv') {
                    $body = $response->body();
                    $lines = explode("\n", trim($body));
                    if (count($lines) < 2) {
                        return ['success' => true, 'data' => []];
                    }

                    return [
                        'success' => true,
                        'data' => $this->parseCsvLines($lines),
                    ];
                }

                return ['success' => false, 'error' => 'Failed to fetch CSV'];
            } catch (\Exception $e) {
                return ['success' => false, 'error' => $e->getMessage()];
            }
        });
    }

    private function parseCsvLines(array $lines): array
    {
        $rawHeaders = str_getcsv(array_shift($lines));
        $headerIndices = [];
        $headers = [];
        $counts = [];
        foreach ($rawHeaders as $i => $h) {
            if ($h === '') continue;
            if (!isset($counts[$h])) $counts[$h] = 0;
            $counts[$h]++;
            $header = $counts[$h] > 1 ? $h . '_' . $counts[$h] : $h;
            $headerIndices[] = $i;
            $headers[] = $header;
        }

        $rows = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $cells = str_getcsv($line);
            $row = [];
            foreach ($headerIndices as $j => $idx) {
                $row[$headers[$j]] = $cells[$idx] ?? '';
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private function client(): PendingRequest
    {
        $client = Http::timeout($this->timeout)
            ->withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ]);

        if (!config('services.google_script.verify_ssl')) {
            $client = $client->withoutVerifying();
        }

        return $client;
    }
}
