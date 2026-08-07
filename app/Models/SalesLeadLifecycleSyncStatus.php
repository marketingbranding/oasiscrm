<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesLeadLifecycleSyncStatus extends Model
{
    protected $guarded = ['id'];

    protected $fillable = ['branch_id', 'scope', 'status', 'operation_uuid', 'message', 'summary', 'started_at', 'finished_at', 'last_successful_at', 'initiated_by', 'duration_ms'];

    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'last_successful_at' => 'datetime',
            'duration_ms' => 'integer',
        ];
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }
}
