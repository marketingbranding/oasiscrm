<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesLeadLifecycleSyncStatus extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'last_successful_at' => 'datetime',
        ];
    }
}
