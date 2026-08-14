<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

class ModuleMaintenance extends Model
{
    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget('oasis.module_maintenance.all'));
        static::deleted(fn () => Cache::forget('oasis.module_maintenance.all'));
    }

    protected $fillable = [
        'module_key',
        'is_enabled',
        'message',
        'estimated_end_at',
        'started_at',
        'ended_at',
        'started_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'estimated_end_at' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
