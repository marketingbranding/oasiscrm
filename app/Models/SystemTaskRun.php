<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemTaskRun extends Model
{
    protected $fillable = [
        'task_key',
        'started_at',
        'finished_at',
        'status',
        'summary',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'summary' => 'array',
        ];
    }
}
