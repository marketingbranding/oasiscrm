<?php

namespace App\Models;

use Database\Factories\ConsumerStageEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsumerStageEvent extends Model
{
    /** @use HasFactory<ConsumerStageEventFactory> */
    use HasFactory;

    protected $fillable = ['consumer_application_id', 'stage', 'status', 'occurred_at', 'completed_at', 'actor_id', 'source', 'source_id', 'reason', 'metadata'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'completed_at' => 'datetime', 'metadata' => 'array'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ConsumerApplication::class, 'consumer_application_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
