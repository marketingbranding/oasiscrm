<?php

namespace App\Models;

use App\Models\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentItem extends Model
{
    use LogsActivity;
    protected $fillable = [
        'branch_id',
        'project_name',
        'title',
        'task_detail',
        'platform',
        'start_date',
        'deadline_date',
        'priority',
        'pic_names',
        'scheduled_date',
        'status',
        'completed_at',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_date' => 'date',
            'start_date' => 'date',
            'deadline_date' => 'date',
            'pic_names' => 'array',
            'completed_at' => 'datetime',
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

    protected function activityLabel(): string
    {
        return $this->title . ' (Task)';
    }
}
