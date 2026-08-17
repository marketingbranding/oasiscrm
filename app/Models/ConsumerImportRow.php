<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsumerImportRow extends Model
{
    protected $fillable = ['batch_id', 'line_number', 'normalized_data', 'sensitive_data', 'status', 'skip_reason', 'warnings', 'errors'];

    protected function casts(): array
    {
        return ['normalized_data' => 'array', 'sensitive_data' => 'encrypted:array', 'warnings' => 'array', 'errors' => 'array'];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ConsumerImportBatch::class, 'batch_id');
    }
}
