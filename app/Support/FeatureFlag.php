<?php

namespace App\Support;

use App\Services\AppSettingResolver;

final class FeatureFlag
{
    /** @var list<string> */
    private const KEYS = [
        'org_hierarchical_monitoring',
        'lead_stages_v3',
        'work_hours_v3',
        'transaction_events_v3',
    ];

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
        return collect(self::KEYS)
            ->mapWithKeys(fn (string $key): array => [$key => self::enabled($key)])
            ->all();
    }
}
