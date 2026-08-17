<?php

namespace App\Models;

use App\Models\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function consumerAssignments(): HasMany
    {
        return $this->hasMany(ConsumerKavlingAssignment::class);
    }

    public function activeConsumerAssignment(): HasMany
    {
        return $this->hasMany(ConsumerKavlingAssignment::class)->where('assignment_status', 'active')->whereNull('released_at');
    }

    protected function activityLabel(): string
    {
        return ($this->kavling_code ?? '').' (Kavling)';
    }
}
