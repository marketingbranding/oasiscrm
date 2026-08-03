<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesLeadLifecycleReconciliationItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'resolved_at' => 'datetime'];
    }
}
