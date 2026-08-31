<?php

namespace App\Models;

use App\Enums\DanaTalanganReconciliationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DanaTalanganReconciliationItem extends Model
{
    protected $fillable = [
        'dana_talangan_id', 'spreadsheet_id', 'remote_sync_id', 'remote_row_number', 'issue_code',
        'field_names', 'safe_metadata', 'status', 'identity_key', 'resolved_by', 'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'field_names' => 'array',
            'safe_metadata' => 'array',
            'status' => DanaTalanganReconciliationStatus::class,
            'resolved_at' => 'datetime',
        ];
    }

    public function danaTalangan(): BelongsTo
    {
        return $this->belongsTo(DanaTalangan::class)->withTrashed();
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
