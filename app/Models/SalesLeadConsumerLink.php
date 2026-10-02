<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesLeadConsumerLink extends Model
{
    protected $guarded = ['id'];

    public function consumerApplication(): BelongsTo
    {
        return $this->belongsTo(ConsumerApplication::class, 'consumer_application_id');
    }

    protected function casts(): array
    {
        return ['converted_at' => 'datetime', 'payload' => 'array', 'metadata' => 'array'];
    }
}
