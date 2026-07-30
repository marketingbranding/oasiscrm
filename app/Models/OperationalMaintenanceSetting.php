<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'enabled',
    'title',
    'message',
    'estimated_end_at',
    'enabled_by',
    'disabled_by',
    'enabled_at',
    'disabled_at',
    'lock_version',
])]
class OperationalMaintenanceSetting extends Model
{
    public const GLOBAL_ID = 'global';

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'estimated_end_at' => 'datetime',
            'enabled_at' => 'datetime',
            'disabled_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }

    public function enabledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enabled_by');
    }

    public function disabledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disabled_by');
    }
}
