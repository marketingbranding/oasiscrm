<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeadMaster extends Model
{
    protected $table = 'lead_master';

    protected $fillable = [
        'branch_id',
        'project_name',
        'sheet_project_name',
        'lead_source',
        'category',
        'is_active',
        'is_nup_eligible',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_nup_eligible' => 'boolean',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function kavlings(): HasMany
    {
        return $this->hasMany(Kavling::class, 'project_id');
    }

    public function assignedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_user', 'project_id', 'user_id')
            ->using(ProjectUser::class)
            ->withPivot(['is_primary', 'assignment_start_date', 'assignment_end_date', 'is_active'])
            ->withTimestamps();
    }

    public function salesLeads(): HasMany
    {
        return $this->hasMany(SalesLead::class, 'project_id');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'project_id');
    }
}
