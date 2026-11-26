<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectTransferItem extends Model
{
    protected $fillable = [
        'batch_id',
        'table_name',
        'row_id',
        'old_values',
        'old_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'old_updated_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProjectTransferBatch::class, 'batch_id');
    }
}
