<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectTransferBatch extends Model
{
    protected $fillable = [
        'source_branch_id',
        'target_branch_id',
        'created_by',
        'project_ids',
        'user_mapping',
        'status',
        'executed_at',
        'rolled_back_at',
        'impact_summary',
    ];

    protected function casts(): array
    {
        return [
            'project_ids' => 'array',
            'user_mapping' => 'array',
            'impact_summary' => 'array',
            'executed_at' => 'datetime',
            'rolled_back_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProjectTransferItem::class, 'batch_id');
    }

    public function sourceBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'source_branch_id');
    }

    public function targetBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'target_branch_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
