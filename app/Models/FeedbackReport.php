<?php

namespace App\Models;

use App\Models\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeedbackReport extends Model
{
    use LogsActivity;

    public const TYPES = ['bug', 'masukan', 'permintaan_fitur'];

    public const STATUSES = ['pending', 'reviewing', 'approved', 'rejected', 'in_progress', 'implemented', 'fixed', 'closed'];

    public const PRIORITIES = ['low', 'medium', 'high', 'critical'];

    protected $fillable = [
        'user_id',
        'branch_id',
        'type',
        'title',
        'description',
        'module',
        'activity',
        'expected_result',
        'actual_result',
        'suggestion',
        'impact',
        'target_users',
        'reproduction_frequency',
        'need_level',
        'additional_notes',
        'page_url',
        'route_name',
        'active_branch_id',
        'app_version',
        'user_agent_summary',
        'screen_size',
        'status',
        'priority',
        'admin_note',
        'reviewed_by',
        'assigned_to',
        'reviewed_at',
        'resolved_at',
        'screenshot_path',
        'screenshot_name',
        'screenshot_mime',
        'screenshot_size',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function activeBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'active_branch_id');
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            'bug' => 'Bug / Error',
            'permintaan_fitur' => 'Permintaan Fitur',
            default => 'Masukan',
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'reviewing' => 'Sedang Ditinjau',
            'approved' => 'Disetujui',
            'rejected' => 'Ditolak',
            'in_progress' => 'Sedang Dikerjakan',
            'implemented' => 'Sudah Diterapkan',
            'fixed' => 'Sudah Diperbaiki',
            'closed' => 'Ditutup',
            default => 'Menunggu',
        };
    }

    protected function activityLabel(): string
    {
        return '#'.$this->id.' '.$this->title.' (Laporan)';
    }

    protected function activityProperties(string $event): array
    {
        return [
            'report_id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'status' => $this->status,
            'branch_id' => $this->branch_id,
        ];
    }
}
