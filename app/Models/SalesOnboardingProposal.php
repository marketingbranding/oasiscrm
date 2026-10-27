<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SalesOnboardingProposal extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'name', 'email', 'phone', 'role_id', 'branch_id', 'project_id', 'coordinator_user_id',
        'submitted_by', 'submitted_scope', 'submitted_at', 'status', 'decided_by', 'decided_at',
        'decision_reason', 'decision_token',
    ];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
    ];

    protected static function booted(): void
    {
        static::creating(function (SalesOnboardingProposal $proposal): void {
            $proposal->decision_token ??= Str::uuid()->toString();
        });
    }

    protected function casts(): array
    {
        return [
            'submitted_scope' => 'array',
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(LeadMaster::class, 'project_id');
    }

    public function coordinator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coordinator_user_id');
    }
}
