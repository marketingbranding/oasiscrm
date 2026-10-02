<?php

namespace App\Models;

use Database\Factories\ConsumerIssueFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsumerIssue extends Model
{
    /** @use HasFactory<ConsumerIssueFactory> */
    use HasFactory;

    protected $fillable = [
        'consumer_application_id', 'process_key', 'category', 'description', 'opened_at', 'pic_user_id',
        'status', 'resolution', 'resolved_at', 'resolved_by', 'source', 'source_id', 'metadata',
    ];

    protected function casts(): array
    {
        return ['opened_at' => 'datetime', 'resolved_at' => 'datetime', 'metadata' => 'array'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ConsumerApplication::class, 'consumer_application_id');
    }

    public function pic(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pic_user_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
