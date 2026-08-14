<?php

namespace App\Services;

use App\Models\Promo;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class PromoOptionService
{
    public const NO_PROMO = 'No Promo';

    public function availableForBranchAndDate(int $branchId, CarbonInterface|string $date, ?string $current = null): Collection
    {
        if (! $date instanceof CarbonInterface) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            $errors = \DateTimeImmutable::getLastErrors();
            if (! $parsed || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $parsed->format('Y-m-d') !== $date) {
                throw new InvalidArgumentException('Tanggal promo harus berformat Y-m-d.');
            }
            $date = $parsed;
        }
        $names = Promo::query()
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('start_date')->orWhereDate('start_date', '<=', $date))
            ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $date))
            ->where('name', '!=', self::NO_PROMO)
            ->orderBy('name')
            ->pluck('name');

        $options = collect([self::NO_PROMO])->merge($names)->unique()->values();

        if (filled($current) && ! $options->containsStrict($current)) {
            $options->push($current);
        }

        return $options;
    }

    public function accepts(int $branchId, CarbonInterface|string $date, ?string $value, ?string $current = null): bool
    {
        return blank($value) || $this->availableForBranchAndDate($branchId, $date, $current)->containsStrict($value);
    }
}
