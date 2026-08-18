<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsumerStageEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'consumer_application_id',
        'stage',
        'source_id',
        'source',
        'event_date',
        'status',
        'notes',
        'reason',
        'occurred_at',
        'completed_at',
        'actor_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'event_date' => 'date',
            'occurred_at' => 'datetime',
            'completed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ConsumerApplication::class, 'consumer_application_id');
    }
}
