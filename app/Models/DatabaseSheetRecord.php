<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DatabaseSheetRecord extends Model
{
    protected $fillable = [
        'branch_id',
        'sheet_id',
        'sheet_name',
        'row_number',
        'oasis_sync_id',
        'headers',
        'row_data',
        'formula_columns',
        'column_metadata',
        'sync_status',
        'last_sync_error',
        'last_synced_at',
        'oasis_deleted_at',
        'oasis_deleted_by',
    ];

    protected function casts(): array
    {
        return [
            'headers' => 'array',
            'row_data' => 'array',
            'formula_columns' => 'array',
            'column_metadata' => 'array',
            'last_synced_at' => 'datetime',
            'oasis_deleted_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
