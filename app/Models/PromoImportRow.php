<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromoImportRow extends Model
{
    protected $fillable = ['batch_id', 'line_number', 'raw_data', 'normalized_data', 'status', 'errors'];

    protected function casts(): array
    {
        return ['raw_data' => 'array', 'normalized_data' => 'array', 'errors' => 'array'];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(PromoImportBatch::class, 'batch_id');
    }
}
