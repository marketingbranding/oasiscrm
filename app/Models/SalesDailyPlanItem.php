<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SalesDailyPlanItem extends Model
{
    protected $fillable = [
        'sales_daily_plan_id',
        'position',
        'sales_activity_category',
        'activity',
        'location',
        'detail',
        'duration_minutes',
        'start_time',
        'end_time',
        'activity_result',
        'status',
        'completed_at',
        'completed_by',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'duration_minutes' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SalesDailyPlan::class, 'sales_daily_plan_id');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(SalesDailyPlanItemEvidence::class)->orderBy('id');
    }

    public function rescheduleTo(): HasOne
    {
        return $this->hasOne(SalesDailyPlanItemReschedule::class, 'source_item_id');
    }

    public function rescheduleFrom(): HasOne
    {
        return $this->hasOne(SalesDailyPlanItemReschedule::class, 'target_item_id');
    }
}
