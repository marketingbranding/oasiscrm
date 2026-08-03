<?php

namespace App\Models;

use App\Enums\SalesLeadStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesLeadStatusHistory extends Model
{
    protected $fillable = [
        'sales_lead_id', 'branch_id', 'actor_id', 'status', 'source', 'source_id',
        'operation_uuid', 'changed_at', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => SalesLeadStatus::class,
            'changed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function salesLead(): BelongsTo
    {
        return $this->belongsTo(SalesLead::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
