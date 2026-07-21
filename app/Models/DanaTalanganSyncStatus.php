<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DanaTalanganSyncStatus extends Model
{
    protected $fillable = [
        'spreadsheet_id',
        'status',
        'message',
        'summary',
        'started_at',
        'finished_at',
        'initiated_by',
        'last_successful_at',
        'duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'last_successful_at' => 'datetime',
        ];
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }
}
