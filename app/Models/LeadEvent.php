<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeadEvent extends Model
{
    protected $fillable = [
        'branch_id',
        'event_id',
        'project_name',
        'lead_source',
        'start_date',
        'end_date',
        'total_budget',
        'daily_target',
        'status',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'total_budget' => 'decimal:0',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function dailyLogs(): HasMany
    {
        return $this->hasMany(LeadDaily::class, 'lead_event_id');
    }

    public function totalLeads(): int
    {
        return $this->dailyLogs()->sum('leads_count');
    }

    public function costPerLead(): ?float
    {
        $total = $this->totalLeads();
        if ($total === 0 || !$this->total_budget) {
            return null;
        }
        return $this->total_budget / $total;
    }
}
