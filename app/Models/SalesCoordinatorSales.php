<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesCoordinatorSales extends Model
{
    protected $table = 'sales_coordinator_sales';

    protected $fillable = [
        'coordinator_user_id',
        'sales_user_id',
        'is_active',
        'started_at',
        'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'started_at' => 'date',
            'ended_at' => 'date',
        ];
    }

    public function coordinator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coordinator_user_id');
    }

    public function sales(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sales_user_id');
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->where(fn (Builder $query) => $query->whereNull('started_at')->orWhereDate('started_at', '<=', today()))
            ->where(fn (Builder $query) => $query->whereNull('ended_at')->orWhereDate('ended_at', '>=', today()));
    }

    public function scopeWithValidRoles(Builder $query): Builder
    {
        return $query
            ->whereHas('coordinator.role', fn (Builder $query) => $query->where('slug', 'sales_coordinator'))
            ->whereHas('sales.role', fn (Builder $query) => $query->where('slug', 'sales'));
    }
}
