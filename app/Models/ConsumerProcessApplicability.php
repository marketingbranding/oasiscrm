<?php

namespace App\Models;

use Database\Factories\ConsumerProcessApplicabilityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsumerProcessApplicability extends Model
{
    /** @use HasFactory<ConsumerProcessApplicabilityFactory> */
    use HasFactory;

    protected $fillable = ['consumer_application_id', 'process_key', 'applicability', 'reason', 'source', 'source_id', 'metadata'];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ConsumerApplication::class, 'consumer_application_id');
    }
}
