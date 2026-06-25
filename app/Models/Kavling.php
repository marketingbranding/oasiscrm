<?php

namespace App\Models;

use App\Models\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Kavling extends Model
{
    use LogsActivity;
    protected $fillable = [
        'project_id',
        'kavling_code',
        'name',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(LeadMaster::class, 'project_id');
    }

    protected function activityLabel(): string
    {
        return ($this->kavling_code ?? '') . ' (Kavling)';
    }
}
