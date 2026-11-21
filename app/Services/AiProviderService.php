<?php

namespace App\Services;

use App\Exceptions\AiProviderException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class AiProviderService
{
    public function __construct(private readonly AppSettingResolver $settings) {}

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

        $payload = $this->sanitizePayload($payload);

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

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function sanitizePayload(array $payload): array
    {
        $sanitized = $this->sanitizeValue($payload, null);

        return is_array($sanitized) ? $sanitized : [];
    }

    private function sanitizeValue(mixed $value, ?string $key): mixed
    {
        if ($key === 'name' && is_string($value) && in_array($value, [
            'count_by_stage',
            'get_content_schedule',
            'get_dana_talangan_summary',
            'search_customer',
            'get_today_summary',
            'get_branch_info',
            'get_supported_capabilities',
            'ask_clarification',
        ], true)) {
            return $value;
        }

        if ($this->isSensitiveKey($key)) {
            return null;
        }

        if (is_array($value)) {
            $result = [];
            foreach ($value as $childKey => $childValue) {
                $sanitized = $this->sanitizeValue($childValue, (string) $childKey);
                if ($sanitized !== null) {
                    $result[$childKey] = $sanitized;
                }
            }

            return $result;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return json_encode($this->sanitizeValue($decoded, null), JSON_UNESCAPED_UNICODE);
            }

            return $this->sanitizeString($value);
        }

        return $value;
    }

    private function isSensitiveKey(?string $key): bool
    {
        if ($key === null) {
            return false;
        }

        $normalized = strtolower(preg_replace('/[^a-z0-9]/i', '', $key) ?? $key);
        if (preg_match('/^(nik|email|emailaddress|phone|phonenumber|mobile|mobilenumber|telephone|tel|address|alamat|streetaddress|homeaddress)$/', $normalized)) {
            return true;
        }

        return ! $this->settings->bool('ai.send_names')
            && preg_match('/(fullname|customername|employeename|branchname|personname)/', $normalized) === 1;
    }

    private function sanitizeString(string $value): string
    {
        $value = preg_replace('/\\b\\d{16}\\b/', '[disaring]', $value) ?? $value;
        $value = preg_replace('/\\b[\\w.!#$%&\'*+\\/=?^`{|}~-]+@[\\w-]+(?:\\.[\\w-]+)+\\b/i', '[disaring]', $value) ?? $value;
        $value = preg_replace('/(?<!\\d)(?:\\+62|62|0)8\\d{8,12}(?!\\d)/', '[disaring]', $value) ?? $value;
        $value = preg_replace('/\\b(?:alamat|address)\\s*[:=-]?\\s*[^,.!?;]+/iu', '[disaring]', $value) ?? $value;

        if (! $this->settings->bool('ai.send_names')) {
            $value = preg_replace('/\\b[[:upper:]][\\p{L}]+(?:\\s+[[:upper:]][\\p{L}]+)+\\b/u', '[disaring]', $value) ?? $value;
        }

        return $value;
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
