<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['date', 'name', 'branch_id'])]
class Holiday extends Model
{
    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }

    #[Scope]
    protected function forDate(Builder $query, string $date, ?int $branchId = null): void
    {
        $query->whereDate('date', $date)
            ->where(function (Builder $query) use ($branchId): void {
                $query->whereNull('branch_id');

                if ($branchId !== null) {
                    $query->orWhere('branch_id', $branchId);
                }
            });
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
