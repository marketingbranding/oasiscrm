<?php

namespace App\Models;

use App\Models\Traits\LogsActivity;
use Database\Factories\SalesDailyPlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesDailyPlan extends Model
{
    /** @use HasFactory<SalesDailyPlanFactory> */
    use HasFactory, LogsActivity;

    public const STATUSES = ['draft', 'submitted'];

    public const REQUIRED_WORK_MINUTES = 420;

    public const TOTAL_WORK_MINUTES = self::REQUIRED_WORK_MINUTES;

    protected $fillable = [
        'branch_id',
        'sales_user_id',
        'plan_date',
        'work_date',
        'status',
        'submitted_at',
        'submitted_by',
        'last_modified_by',
        'revised_at',
        'revised_by',
        'lock_version',
    ];

    protected function casts(): array
    {
        return [
            'plan_date' => 'date',
            'work_date' => 'date',
            'submitted_at' => 'datetime',
            'revised_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $plan): void {
            if (! $plan->plan_date && $plan->work_date) {
                $plan->plan_date = $plan->work_date;
            }
            if (! $plan->work_date && $plan->plan_date) {
                $plan->work_date = $plan->plan_date;
            }
        });
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function sales(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sales_user_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function submitter(): BelongsTo
    {
        return $this->submittedBy();
    }

    public function lastModifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_modified_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->lastModifiedBy();
    }

    public function reviser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revised_by');
    }

    public function agendas(): HasMany
    {
        return $this->hasMany(ContentItem::class, 'daily_plan_id')
            ->where('item_type', 'agenda')
            ->where('agenda_type', ContentItem::SALES_AGENDA_TYPE)
            ->whereNotIn('status', ['cancelled', 'rescheduled'])
            ->orderBy('sort_order')
            ->orderBy('id');
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

    public function isWeekday(): bool
    {
        return ($this->plan_date ?? $this->work_date)->isWeekday();
    }

    protected function activityLabel(): string
    {
        return 'Daily Plan '.($this->plan_date ?? $this->work_date)?->toDateString();
    }
}
