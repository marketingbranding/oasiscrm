<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Facades\DB;

class ProjectUser extends Pivot
{
    protected $table = 'project_user';

    protected $fillable = [
        'user_id',
        'project_id',
        'is_primary',
        'assignment_start_date',
        'assignment_end_date',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'assignment_start_date' => 'date',
            'assignment_end_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (ProjectUser $assignment) {
            if ($assignment->is_active === null) {
                $assignment->is_active = true;
            }

            if (! $assignment->is_active) {
                $assignment->is_primary = false;
            }

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
