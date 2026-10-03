<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoleReportingRule extends Model
{
    protected $fillable = [
        'parent_role_id',
        'child_role_id',
        'is_allowed',
    ];

    protected function casts(): array
    {
        return ['is_allowed' => 'boolean'];
    }

    public function parentRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'parent_role_id');
    }

    public function childRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'child_role_id');
    }
}
