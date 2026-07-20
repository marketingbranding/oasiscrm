<?php

namespace App\Models;

use App\Models\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ContentItem extends Model
{
    use LogsActivity;

    public const TYPES = ['task', 'agenda', 'content'];

    public const STATUSES = [
        'task' => ['todo', 'in_progress', 'completed', 'lost_track'],
        'agenda' => ['planned', 'confirmed', 'done', 'cancelled'],
        'content' => ['idea', 'content_in_progress', 'done_editing', 'uploaded'],
    ];

    protected $fillable = [
        'branch_id',
        'project_name',
        'item_type',
        'visibility',
        'title',
        'task_detail',
        'platform',
        'agenda_type',
        'location',
        'start_date',
        'start_time',
        'deadline_date',
        'end_time',
        'content_format',
        'tujuan_konten',
        'asset_url',
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

    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->canViewAllBranches()) {
            return $query;
        }

        return $query->where('branch_id', $user->branch_id)
            ->where(function (Builder $query) use ($user) {
                $query->where('visibility', 'team')
                    ->orWhere('created_by', $user->id)
                    ->orWhereHas('assignees', fn (Builder $assigned) => $assigned->where('users.id', $user->id));
            });
    }

    public function isFinished(): bool
    {
        return in_array($this->status, match ($this->item_type) {
            'agenda' => ['done', 'cancelled'],
            'content' => ['uploaded'],
            default => ['completed'],
        }, true);
    }

    protected function activityLabel(): string
    {
        return $this->title.' ('.ucfirst($this->item_type ?? 'task').')';
    }
}
