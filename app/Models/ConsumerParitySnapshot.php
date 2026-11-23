<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsumerParitySnapshot extends Model
{
    protected $fillable = ['branch_id', 'checked_at', 'status', 'summary', 'metrics', 'differences'];

    protected function casts(): array
    {
        return ['checked_at' => 'datetime', 'summary' => 'array', 'metrics' => 'array', 'differences' => 'array'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
