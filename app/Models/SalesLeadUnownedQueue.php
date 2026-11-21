<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesLeadUnownedQueue extends Model
{
    protected $fillable = [
        'sales_lead_id',
        'former_sales_user_id',
        'queue_coordinator_user_id',
        'last_active_coordinator_user_id',
        'status',
        'resolved_at',
        'resolved_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(SalesLead::class, 'sales_lead_id');
    }

    public function formerSales(): BelongsTo
    {
        return $this->belongsTo(User::class, 'former_sales_user_id');
    }

    public function coordinator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'queue_coordinator_user_id');
    }

    public function lastActiveCoordinator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_active_coordinator_user_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }
}
