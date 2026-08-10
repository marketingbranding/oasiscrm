<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesLeadLifecycleReconciliationItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'resolved_at' => 'datetime'];
    }

    public function salesLead(): BelongsTo
    {
        return $this->belongsTo(SalesLead::class, 'sales_lead_id');
    }
}
