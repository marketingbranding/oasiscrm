<?php

namespace App\Models;

use App\Models\Traits\LogsActivity;
use Database\Factories\SalesLeadTargetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesLeadTarget extends Model
{
    /** @use HasFactory<SalesLeadTargetFactory> */
    use HasFactory, LogsActivity;

    protected $fillable = [
        'sales_user_id',
        'period_year',
        'period_month',
        'lead_target',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'period_year' => 'integer',
            'period_month' => 'integer',
            'lead_target' => 'integer',
        ];
    }

    public function sales(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sales_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    protected function activityLabel(): string
    {
        return 'Target lead '.$this->period_year.'-'.$this->period_month;
    }
}
