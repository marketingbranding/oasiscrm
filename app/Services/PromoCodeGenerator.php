<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Promo;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class PromoCodeGenerator
{
    public function create(int $branchId, array $attributes, User $actor): Promo
    {
        $attributes = collect($attributes)->except(['branch_id', 'code'])->all();

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                return DB::transaction(function () use ($branchId, $attributes, $actor) {
                    Branch::query()->whereKey($branchId)->where('is_active', true)->lockForUpdate()->firstOrFail();

                    $prefix = $this->prefix($attributes['name'], $attributes['start_date']);
                    $max = Promo::query()->where('branch_id', $branchId)->get(['code'])->reduce(function (int $max, Promo $promo) use ($prefix): int {
                        return preg_match('/^'.preg_quote($prefix, '/').'([0-9]+)$/i', $promo->code, $matches)
                            ? max($max, (int) $matches[1])
                            : $max;
                    }, 0);

                    return Promo::create($attributes + [
                        'branch_id' => $branchId,
                        'code' => $prefix.str_pad((string) ($max + 1), 2, '0', STR_PAD_LEFT),
                        'created_by' => $actor->id,
                        'updated_by' => $actor->id,
                    ]);
                });
            } catch (UniqueConstraintViolationException $exception) {
                if ($attempt === 3) {
                    throw $exception;
                }
            }
        }
    }

    private function prefix(string $name, string $startDate): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $startDate);
        $errors = DateTimeImmutable::getLastErrors();
        if (! $date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('Y-m-d') !== $startDate) {
            throw new InvalidArgumentException('Tanggal mulai promo harus berformat Y-m-d.');
        }

        $words = preg_split('/\s+/u', Str::upper(Str::squish($name)), -1, PREG_SPLIT_NO_EMPTY);
        $clean = array_values(array_filter(array_map(fn (string $word): string => preg_replace('/[^A-Z0-9]/', '', $word), $words)));
        $token = count($clean) >= 2
            ? $clean[0][0].$clean[1][0]
            : substr($clean[0] ?? '', 0, 2);

        return $date->format('ymd').'-'.($token ?: 'PR').'-';
    }
}
