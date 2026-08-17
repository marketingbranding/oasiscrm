<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsumerKavlingAssignment extends Model
{
    protected $fillable = [
        'consumer_application_id',
        'kavling_id',
        'assigned_at',
        'released_at',
        'release_reason',
        'assignment_status',
    ];

    protected function casts(): array
    {
        return ['assigned_at' => 'datetime', 'released_at' => 'datetime'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ConsumerApplication::class, 'consumer_application_id');
    }

    public function kavling(): BelongsTo
    {
        return $this->belongsTo(Kavling::class);
    }
}
