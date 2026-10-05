<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationAssignment extends Model
{
    protected $fillable = [
        'user_id',
        'parent_user_id',
        'organization_unit_id',
        'relationship_type',
        'branch_id',
        'project_id',
        'started_at',
        'ended_at',
        'is_active',
        'is_primary',
        'source',
        'changed_by',
        'reason',
        'lock_version',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'date',
            'ended_at' => 'date',
            'is_active' => 'boolean',
            'is_primary' => 'boolean',
            'lock_version' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'parent_user_id');
    }

    public function organizationUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(LeadMaster::class, 'project_id');
    }

    public function scopeReportsTo(Builder $query): Builder
    {
        return $query->where('relationship_type', 'reports_to');
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->reportsTo()
            ->where('is_active', true)
            ->where('is_primary', true)
            ->whereDate('started_at', '<=', today())
            ->where(fn (Builder $date) => $date->whereNull('ended_at')->orWhereDate('ended_at', '>', today()));
    }
}
