<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesDailyPlanItemReschedule extends Model
{
    protected $fillable = [
        'source_item_id',
        'target_item_id',
        'actor_user_id',
        'source_work_date',
        'target_work_date',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'source_work_date' => 'date',
            'target_work_date' => 'date',
        ];
    }

    public function sourceItem(): BelongsTo
    {
        return $this->belongsTo(SalesDailyPlanItem::class, 'source_item_id');
    }

    public function targetItem(): BelongsTo
    {
        return $this->belongsTo(SalesDailyPlanItem::class, 'target_item_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
