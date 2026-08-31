<?php

namespace App\Models;

use App\Enums\DanaTalanganBridgeMode;
use App\Enums\DanaTalanganBridgeStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DanaTalanganBridgeSetting extends Model
{
    protected $fillable = [
        'spreadsheet_id', 'mode', 'status', 'preflight_at', 'preflight_hash', 'enabled_by', 'enabled_at',
    ];

    protected function casts(): array
    {
        return [
            'mode' => DanaTalanganBridgeMode::class,
            'status' => DanaTalanganBridgeStatus::class,
            'preflight_at' => 'datetime',
            'enabled_at' => 'datetime',
        ];
    }

    public function enabledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enabled_by');
    }
}
