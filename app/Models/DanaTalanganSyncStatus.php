<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DanaTalanganSyncStatus extends Model
{
    protected $fillable = [
        'spreadsheet_id',
        'status',
        'message',
        'summary',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
