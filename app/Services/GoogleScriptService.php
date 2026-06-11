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
