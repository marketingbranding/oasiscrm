<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Kavling extends Model
{
    protected $fillable = [
        'project_id',
        'kavling_code',
        'name',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(LeadMaster::class, 'project_id');
    }
}
