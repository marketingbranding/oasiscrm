<?php

namespace App\Models;

use Database\Factories\ConsumerDocumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsumerDocument extends Model
{
    /** @use HasFactory<ConsumerDocumentFactory> */
    use HasFactory;

    protected $fillable = ['consumer_application_id', 'document_type', 'status', 'received_at', 'verified_at', 'verified_by', 'source', 'notes'];

    protected function casts(): array
    {
        return ['received_at' => 'datetime', 'verified_at' => 'datetime'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ConsumerApplication::class, 'consumer_application_id');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
