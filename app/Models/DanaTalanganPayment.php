<?php

namespace App\Models;

use App\Models\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class DanaTalanganPayment extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = [
        'dana_talangan_id', 'paid_at', 'amount', 'note', 'is_verified', 'verified_by', 'verified_at', 'evidence_path', 'created_by',
    ];

    protected function casts(): array
    {
        return ['paid_at' => 'date', 'amount' => 'decimal:2', 'is_verified' => 'boolean', 'verified_at' => 'datetime'];
    }

    public function danaTalangan(): BelongsTo
    {
        return $this->belongsTo(DanaTalangan::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected static function booted(): void
    {
        static::forceDeleted(function (self $payment): void {
            if ($payment->evidence_path !== null) {
                Storage::disk('local')->delete($payment->evidence_path);
            }
        });
    }

    protected function activityLabel(): string
    {
        return 'Pembayaran Dana Talangan #'.$this->id;
    }
}
