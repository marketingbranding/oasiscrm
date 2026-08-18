<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HistoricalProcessImportRow extends Model
{
    protected $fillable = [
        'batch_id',
        'line_number',
        'sheet_type',
        'raw_data',
        'normalized_data',
        'nik',
        'status',
        'errors',
    ];

    protected function casts(): array
    {
        return [
            'raw_data' => 'array',
            'normalized_data' => 'array',
            'nik' => 'encrypted',
            'errors' => 'array',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(HistoricalProcessImportBatch::class, 'batch_id');
    }
}
