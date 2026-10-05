<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Schema;

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

    protected static function booted(): void
    {
        static::saved(function (Branch $branch): void {
            if (! Schema::hasTable('organization_units')) {
                return;
            }

            $central = OrganizationUnit::query()->where('code', 'pusat')->first();
            if ($central === null) {
                return;
            }

            OrganizationUnit::query()->updateOrCreate(
                ['branch_id' => $branch->id],
                [
                    'parent_id' => $central->id,
                    'code' => 'branch-'.$branch->id,
                    'name' => $branch->name,
                    'unit_type' => 'branch',
                    'is_active' => $branch->is_active ?? true,
                ],
            );
        });
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

    public function organizationUnit(): HasOne
    {
        return $this->hasOne(OrganizationUnit::class);
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

    public function bridgeSetting(): HasOne
    {
        return $this->hasOne(SalesLeadBridgeSetting::class);
    }
}
