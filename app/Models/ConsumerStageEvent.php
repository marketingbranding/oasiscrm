<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsumerStageEvent extends Model
{
    protected $fillable = [
        'application_id',
        'stage',
        'source_id',
        'event_date',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'event_date' => 'date',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ConsumerApplication::class, 'application_id');
    }
}
