<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesLeadSiteVisit extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['visited_at' => 'datetime', 'visit_date' => 'date', 'is_completed' => 'boolean', 'metadata' => 'array'];
    }
}
