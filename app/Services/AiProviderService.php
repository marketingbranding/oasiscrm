<?php

namespace App\Services;

use App\Exceptions\AiProviderException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class AiProviderService
{
    public function chat(array $messages, array $tools = []): array
    {
        if (! config('ai.enabled')) {
            throw new AiProviderException('AI chat belum diaktifkan.');
        }

        $providers = array_filter([
            config('ai.primary'),
            config('ai.fallback'),
        ], fn ($provider) => filled($provider['base_url'] ?? null) && filled($provider['model'] ?? null));

        $lastError = null;
        foreach ($providers as $provider) {
            try {
                return $this->send($provider, $messages, $tools);
            } catch (Throwable $e) {
                $lastError = $e;
                $this->logProviderFailure($provider, $e);
            }
        }

        throw new AiProviderException($lastError?->getMessage() ?: 'Tidak ada provider AI yang bisa dipakai.');
    }

    private function send(array $provider, array $messages, array $tools): array
    {
        if (($provider['provider'] ?? null) !== 'ollama' && blank($provider['api_key'] ?? null)) {
            throw new AiProviderException(($provider['provider'] ?? 'AI provider').': API key belum dikonfigurasi.');
        }

        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        if (filled($provider['api_key'] ?? null)) {
            $headers['Authorization'] = 'Bearer '.$provider['api_key'];
        }

        if (($provider['provider'] ?? null) === 'openrouter') {
            $headers['HTTP-Referer'] = config('app.url');
            $headers['X-Title'] = 'Oasis CRM';
        }

        $payload = [
            'model' => $provider['model'],
            'messages' => $messages,
            'temperature' => 0.2,
        ];

        if ($tools !== []) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
        }

        $response = Http::withHeaders($headers)
            ->timeout($this->bounded((int) config('ai.timeout', 15), 3, 60))
            ->connectTimeout($this->bounded((int) config('ai.connect_timeout', 5), 1, 15))
            ->post(rtrim($provider['base_url'], '/').'/chat/completions', $payload);

        if (! $response->successful()) {
            throw new AiProviderException('Provider '.$provider['provider'].' gagal: '.$response->body());
        }

        $json = $response->json();
        $message = $json['choices'][0]['message'] ?? null;

        if (! is_array($message)) {
            throw new AiProviderException('Response provider AI tidak valid.');
        }

        return [
            'message' => $message,
            'provider' => $provider['provider'] ?? 'custom',
            'model' => $provider['model'],
        ];
    }

    private function bounded(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }

    private function logProviderFailure(array $provider, Throwable $e): void
    {
        Log::warning('AI provider fallback used', [
            'provider' => $provider['provider'] ?? 'custom',
            'model' => $provider['model'] ?? null,
            'exception' => $e::class,
            'category' => str_contains(strtolower($e->getMessage()), 'api key') ? 'configuration' : 'provider_error',
        ]);
    }
}
