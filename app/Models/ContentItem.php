<?php

namespace App\Models;

use App\Models\Traits\LogsActivity;
use App\Services\OrganizationScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class ContentItem extends Model
{
    use LogsActivity;

    public const TYPES = ['task', 'agenda', 'content'];

    public const SALES_AGENDA_TYPE = 'buku_saku_sales';

    public const SALES_ACTIVITY_CATEGORIES = [
        'Canvassing',
        'Follow-up',
        'Telepon/WhatsApp',
        'Tatap Muka Konsumen',
        'Cek Lokasi',
        'TikTok Live',
        'Pembuatan Konten',
        'Event/Pameran',
        'Administrasi',
        'Rapat',
        'Lainnya',
    ];

    public const STATUSES = [
        'task' => ['todo', 'in_progress', 'completed', 'lost_track'],
        'agenda' => ['planned', 'confirmed', 'done', 'cancelled', 'rescheduled'],
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
        'activity_result',
        'duration_minutes',
        'sales_activity_category',
        'owner_user_id',
        'sales_project_id',
        'rescheduled_from_id',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_date' => 'date',
            'start_date' => 'date',
            'deadline_date' => 'date',
            'pic_names' => 'array',
            'completed_at' => 'datetime',
            'duration_minutes' => 'integer',
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

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function salesProject(): BelongsTo
    {
        return $this->belongsTo(LeadMaster::class, 'sales_project_id');
    }

    public function rescheduledFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rescheduled_from_id');
    }

    public function rescheduledItems(): HasMany
    {
        return $this->hasMany(self::class, 'rescheduled_from_id');
    }

    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(SalesAgendaEvidence::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->hasPermission('work_planner.view_all')) {
            return $query;
        }

        $branchIds = app(OrganizationScopeService::class)->branchIds($user, 'work_planner');

        return $query->whereIn('branch_id', $branchIds)
            ->where(function (Builder $query) use ($user) {
                $query->where('visibility', 'team')
                    ->orWhere('created_by', $user->id)
                    ->orWhereHas('assignees', fn (Builder $assigned) => $assigned->where('users.id', $user->id));
            });
    }

    public function isFinished(): bool
    {
        return in_array($this->status, match ($this->item_type) {
            'agenda' => ['done', 'cancelled', 'rescheduled'],
            'content' => ['uploaded'],
            default => ['completed'],
        }, true);
    }

    protected function activityLabel(): string
    {
        return $this->title.' ('.ucfirst($this->item_type ?? 'task').')';
    }
}
