<?php

namespace App\Services;

final class OrganizationGraphShadowService
{
    /** @var array<string, int> */
    private array $counters = [];

    public function record(string $dimension, bool $equivalent): void
    {
        $key = $equivalent ? "{$dimension}.equivalent" : "{$dimension}.mismatch";
        $this->counters[$key] = ($this->counters[$key] ?? 0) + 1;
    }

    public function recordFailure(string $dimension): void
    {
        $key = "{$dimension}.failure";
        $this->counters[$key] = ($this->counters[$key] ?? 0) + 1;
    }

    /** @return array<string, int> */
    public function snapshot(): array
    {
        ksort($this->counters);

        return $this->counters;
    }

    public function reset(): void
    {
        $this->counters = [];
    }
}
