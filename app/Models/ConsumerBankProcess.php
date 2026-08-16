<?php

namespace App\Models;

use Database\Factories\ConsumerBankProcessFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsumerBankProcess extends Model
{
    /** @use HasFactory<ConsumerBankProcessFactory> */
    use HasFactory;

    protected $fillable = ['consumer_application_id', 'bank_name', 'status', 'submitted_at', 'verified_at', 'sp3k_at', 'rejected_at', 'rejection_reason', 'source'];

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime', 'verified_at' => 'datetime', 'sp3k_at' => 'datetime', 'rejected_at' => 'datetime'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ConsumerApplication::class, 'consumer_application_id');
    }
}
