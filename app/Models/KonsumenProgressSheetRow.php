<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KonsumenProgressSheetRow extends Model
{
    protected $fillable = [
        'branch_id',
        'sheet_id',
        'sheet_name',
        'row_hash',
        'row_data',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'row_data' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
