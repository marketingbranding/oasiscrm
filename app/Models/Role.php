<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'is_superadmin',
        'is_active',
        'authority_level',
    ];

    protected function casts(): array
    {
        return [
            'is_superadmin' => 'boolean',
            'is_active' => 'boolean',
            'authority_level' => 'integer',
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withTimestamps();
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permission')->withTimestamps();
    }

    public function parentReportingRules(): HasMany
    {
        return $this->hasMany(RoleReportingRule::class, 'parent_role_id');
    }

    public function childReportingRules(): HasMany
    {
        return $this->hasMany(RoleReportingRule::class, 'child_role_id');
    }
}
