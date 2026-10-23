<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesDailyPlan extends Model
{
    protected $fillable = [
        'sales_user_id',
        'work_date',
        'status',
        'submitted_at',
        'submitted_by',
        'revised_at',
        'revised_by',
        'lock_version',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'submitted_at' => 'datetime',
            'revised_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }

    public function sales(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sales_user_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revised_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesDailyPlanItem::class)->orderBy('position');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(SalesDailyPlanRevision::class);
    }

    public function totalDurationMinutes(): int
    {
        return (int) $this->items()->sum('duration_minutes');
    }
}
