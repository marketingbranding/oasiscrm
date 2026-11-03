<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesAgendaDaySubmission extends Model
{
    public const TARGET_MINUTES = 480;

    protected $fillable = [
        'owner_user_id',
        'scheduled_date',
        'target_minutes',
        'normal_minutes_at_submission',
        'is_weekend',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_date' => 'date',
            'target_minutes' => 'integer',
            'normal_minutes_at_submission' => 'integer',
            'is_weekend' => 'boolean',
            'submitted_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }
}
