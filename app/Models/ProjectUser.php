<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Facades\DB;

class ProjectUser extends Pivot
{
    protected $table = 'project_user';

    protected $casts = [
        'is_primary' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (ProjectUser $assignment) {
            if (! $assignment->is_primary || ! $assignment->user_id) {
                return;
            }

            DB::table('project_user')
                ->where('user_id', $assignment->user_id)
                ->when($assignment->exists, fn ($query) => $query->where('id', '!=', $assignment->id))
                ->update(['is_primary' => false, 'updated_at' => now()]);
        });
    }
}
