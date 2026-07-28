<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Branch extends Model
{
    protected $fillable = [
        'name',
        'code',
        'sheet_id',
        'address',
        'phone',
        'email',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function scopeForDropdown(Builder $query): Builder
    {
        return $query
            ->orderByRaw('CASE WHEN LOWER(name) = ? THEN 0 WHEN LOWER(name) LIKE ? THEN 1 ELSE 2 END', ['kantor pusat', '%pusat%'])
            ->orderBy('name');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot(['membership_role', 'can_view', 'can_edit', 'can_sync', 'can_manage_members'])
            ->withTimestamps();
    }

    public function primaryUsers(): HasMany
    {
        return $this->hasMany(User::class, 'branch_id');
    }

    public function contentItems(): HasMany
    {
        return $this->hasMany(ContentItem::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(LeadMaster::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }
}
