<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesDailyPlanRevision extends Model
{
    protected $fillable = [
        'sales_daily_plan_id',
        'actor_user_id',
        'action',
        'before_snapshot',
        'after_snapshot',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'before_snapshot' => 'array',
            'after_snapshot' => 'array',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SalesDailyPlan::class, 'sales_daily_plan_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
