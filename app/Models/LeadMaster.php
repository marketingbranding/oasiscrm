<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadMaster extends Model
{
    protected $table = 'lead_master';

    protected $fillable = [
        'branch_id',
        'project_name',
        'lead_source',
        'category',
        'is_active',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
