<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesLeadSiteVisit extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['visited_at' => 'datetime', 'visit_date' => 'date', 'is_completed' => 'boolean', 'metadata' => 'array'];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(SalesLead::class, 'sales_lead_id');
    }
}
