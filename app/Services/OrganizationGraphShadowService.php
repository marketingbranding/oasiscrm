<?php

namespace App\Services;

final class OrganizationGraphShadowService
{
    /** @var array<string, int> */
    private static array $counters = [];

    public function record(string $dimension, bool $equivalent): void
    {
        $key = $equivalent ? "{$dimension}.equivalent" : "{$dimension}.mismatch";
        self::$counters[$key] = (self::$counters[$key] ?? 0) + 1;
    }

    /** @return array<string, int> */
    public function snapshot(): array
    {
        ksort(self::$counters);

        return self::$counters;
    }

    public function reset(): void
    {
        self::$counters = [];
    }
}
