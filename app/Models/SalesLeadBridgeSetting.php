<?php

namespace App\Models;

use App\Enums\SalesLeadBridgeMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesLeadBridgeSetting extends Model
{
    protected $fillable = [
        'branch_id', 'mode', 'status', 'last_preflight_at', 'last_preflight_hash', 'enabled_by', 'enabled_at',
    ];

    protected function casts(): array
    {
        return [
            'mode' => SalesLeadBridgeMode::class,
            'last_preflight_at' => 'datetime',
            'enabled_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function enabledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enabled_by');
    }
}
