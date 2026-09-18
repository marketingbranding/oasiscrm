<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarisonUnlinkedRecord extends Model
{
    protected $fillable = ['batch_id', 'source_sheet', 'source_row', 'source_id', 'reason', 'payload', 'payload_hash'];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(MarisonImportBatch::class, 'batch_id');
    }
}
