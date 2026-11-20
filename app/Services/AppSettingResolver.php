<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Support\AppSettingCatalog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class AppSettingResolver
{
    private const CACHE_PREFIX = 'oasis:app-setting:';

    public function value(string $key): mixed
    {
        $definition = AppSettingCatalog::definition($key);

        try {
            $stored = AppSetting::query()->where('key', $key)->first();
            $value = $stored === null
                ? $definition['default']
                : $this->normalize($stored->value, $definition['type'], $definition['default']);

            Cache::forever($this->cacheKey($key), $value);

            return $value;
        } catch (Throwable $exception) {
            Log::warning('Application setting read failed; using catalog default.', [
                'setting_key' => $key,
                'exception' => $exception::class,
            ]);

            return $definition['default'];
        }
    }

    public function int(string $key): int
    {
        $value = $this->value($key);

        if (! is_int($value)) {
            throw new InvalidArgumentException("Application setting [{$key}] is not an integer.");
        }

        return $value;
    }

    public function bool(string $key): bool
    {
        $value = $this->value($key);

        if (! is_bool($value)) {
            return false;
        }

        return $value;
    }

    public function string(string $key): string
    {
        $value = $this->value($key);

        if (! is_string($value)) {
            throw new InvalidArgumentException("Application setting [{$key}] is not a string.");
        }

        return $value;
    }

    /** @return list<mixed> */
    public function list(string $key): array
    {
        $value = $this->value($key);

        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException("Application setting [{$key}] is not a list.");
        }

        return $value;
    }

    public function forget(string $key): void
    {
        AppSettingCatalog::definition($key);
        Cache::forget($this->cacheKey($key));
    }

    private function cacheKey(string $key): string
    {
        return self::CACHE_PREFIX.$key;
    }

    private function normalize(mixed $value, string $type, mixed $default): mixed
    {
        return match ($type) {
            'integer' => is_int($value) ? $value : $default,
            'boolean' => is_bool($value) ? $value : false,
            'string' => is_string($value) ? $value : $default,
            'list' => is_array($value) && array_is_list($value) ? $value : $default,
            default => $default,
        };
    }
}
