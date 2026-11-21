<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsumerStageEvent extends Model
{
    public const array TRANSACTION_EVENT_TYPES = [
        'PINDAH_KAVLING', 'GANTI_NAMA', 'GANTI_BANK', 'KEMBALI_KE_NUP', 'KOREKSI_DATA', 'MUNDUR',
    ];

    use HasFactory;

    protected $fillable = [
        'consumer_application_id',
        'stage',
        'event_type',
        'source_id',
        'source',
        'event_date',
        'status',
        'decision',
        'notes',
        'reason',
        'occurred_at',
        'completed_at',
        'actor_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'event_date' => 'date',
            'occurred_at' => 'datetime',
            'completed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ConsumerApplication::class, 'consumer_application_id');
    }
}
