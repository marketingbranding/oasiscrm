<?php

namespace App\Support;

use App\Services\AppSettingResolver;

final class FeatureFlag
{
    public static function enabled(string $key): bool
    {
        $definition = AppSettingCatalog::definition($key);
        if (($definition['feature_flag'] ?? false) !== true) {
            throw new \InvalidArgumentException("Application setting [{$key}] is not a feature flag.");
        }

        return app(AppSettingResolver::class)->bool($key);
    }

    /** @return array<string, bool> */
    public static function all(): array
    {
        return collect(AppSettingCatalog::definitions())
            ->filter(fn (array $definition): bool => ($definition['feature_flag'] ?? false) === true)
            ->keys()
            ->mapWithKeys(fn (string $key): array => [$key => self::enabled($key)])
            ->all();
    }
}
