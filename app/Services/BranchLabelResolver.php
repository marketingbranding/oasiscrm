<?php

namespace App\Services;

use App\Models\Branch;
use Illuminate\Support\Collection;

class BranchLabelResolver
{
    private const LEGACY_ALIASES = [
        'malang' => 'KC MALANG',
        'madiun' => 'KC MADIUN',
        'solo' => 'KC SOLO',
        'magelang' => 'KC MAGELANG',
        'purworejo' => 'KC PURWOREJO',
        'jepara' => 'KC JEPARA',
        'pekalongan' => 'KC BATANG',
        'sumedang' => 'KC BANDUNG',
    ];

    public function resolve(string $label, Collection $branches): ?Branch
    {
        $key = $this->key($label);
        $direct = $this->matches($key, $branches);

        if ($direct->isNotEmpty()) {
            return $direct->count() === 1 ? $direct->first() : null;
        }

        $alias = self::LEGACY_ALIASES[$key] ?? null;
        if ($alias === null) {
            return null;
        }

        $matches = $this->matches($this->key($alias), $branches);

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function matches(string $key, Collection $branches): Collection
    {
        return $branches->filter(fn (Branch $branch) => $branch->is_active
            && in_array($key, [$this->key($branch->name), $this->key($branch->code)], true));
    }

    private function key(string $value): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($value)) ?? '');
    }
}
