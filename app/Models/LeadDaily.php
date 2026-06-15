<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadDaily extends Model
{
    protected $table = 'lead_daily';

    protected $fillable = [
        'lead_event_id',
        'branch_id',
        'date',
        'day_number',
        'leads_count',
        'cumulative_leads',
        'achievement_pct',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }

    public function leadEvent(): BelongsTo
    {
        return $this->belongsTo(LeadEvent::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
