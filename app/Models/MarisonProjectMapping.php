<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarisonProjectMapping extends Model
{
    protected $fillable = ['source_system', 'branch_code', 'source_project_id', 'oasis_project_id'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(LeadMaster::class, 'oasis_project_id');
    }
}
