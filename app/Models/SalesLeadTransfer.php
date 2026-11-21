<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class SalesLeadTransfer extends Model
{
    protected $fillable = [
        'sales_lead_id',
        'from_sales_user_id',
        'to_sales_user_id',
        'actor_user_id',
        'reason',
        'notes',
    ];

    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new LogicException('Riwayat alih lead tidak dapat dihapus.');
        });
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(SalesLead::class, 'sales_lead_id');
    }

    public function fromSales(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_sales_user_id');
    }

    public function toSales(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_sales_user_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
