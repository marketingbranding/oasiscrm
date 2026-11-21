<?php

namespace App\Support;

use App\Services\AppSettingResolver;

final class FeatureFlag
{
    public static function enabledForBranch(string $key, ?int $branchId): bool
    {
        $definition = AppSettingCatalog::definition($key);
        if (($definition['feature_flag'] ?? false) !== true) {
            throw new \InvalidArgumentException("Application setting [{$key}] is not a feature flag.");
        }

        $value = app(AppSettingResolver::class)->value($key);
        if (is_bool($value)) {
            return $value;
        }

        if (! is_array($value) || $branchId === null) {
            return false;
        }

        return $value['mode'] === 'all'
            || ($value['mode'] === 'branches' && in_array($branchId, $value['branch_ids'], true));
    }

    public static function enabled(string $key): bool
    {
        $definition = AppSettingCatalog::definition($key);
        if (($definition['feature_flag'] ?? false) !== true) {
            throw new \InvalidArgumentException("Application setting [{$key}] is not a feature flag.");
        }
        if (($definition['type'] ?? null) === 'scoped_feature_flag') {
            throw new \InvalidArgumentException("Feature flag [{$key}] requires branch scope.");
        }

        return app(AppSettingResolver::class)->bool($key);
    }

    /** @return 'all'|'none'|list<int> */
    public static function branchScope(string $key): string|array
    {
        $definition = AppSettingCatalog::definition($key);
        if (($definition['type'] ?? null) !== 'scoped_feature_flag') {
            throw new \InvalidArgumentException("Feature flag [{$key}] is not branch-scoped.");
        }

        $value = app(AppSettingResolver::class)->value($key);
        if (is_bool($value)) {
            return $value ? 'all' : 'none';
        }
        if (! is_array($value) || ($value['mode'] ?? null) === 'off') {
            return 'none';
        }
        if (($value['mode'] ?? null) === 'all') {
            return 'all';
        }

        return $value['branch_ids'] ?? [];
    }

    /** @return array<string, bool> */
    public static function all(): array
    {
        return collect(AppSettingCatalog::definitions())
            ->filter(fn (array $definition): bool => ($definition['feature_flag'] ?? false) === true)
            ->keys()
            ->mapWithKeys(fn (string $key): array => [
                $key => ($definition = AppSettingCatalog::definition($key))['type'] === 'scoped_feature_flag'
                    ? self::branchScope($key) === 'all'
                    : self::enabled($key),
            ])
            ->all();
    }
}
