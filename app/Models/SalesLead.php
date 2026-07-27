<?php

namespace App\Models;

use App\Services\WorkspaceAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesLead extends Model
{
    public const STAGES = [
        'contacted_at' => 'DIHUBUNGI',
        'met_at' => 'TATAP MUKA',
        'surveyed_at' => 'SURVEY',
        'utj_at' => 'UTJ',
        'documents_completed_at' => 'BERKAS AWAL LENGKAP',
        'akad_at' => 'AKAD',
    ];

    public const STAGE_ORDER = [
        'contacted_at',
        'met_at',
        'surveyed_at',
        'utj_at',
        'documents_completed_at',
        'akad_at',
    ];

    public const STAGE_LABELS = self::STAGES;

    protected $fillable = [
        'branch_id', 'project_id', 'sales_user_id', 'lead_source_id', 'lead_date', 'customer_name', 'phone',
        'normalized_phone', 'source_name_snapshot', 'notes', 'linked_consumer_reference',
        'contacted_at', 'met_at', 'surveyed_at', 'utj_at', 'documents_completed_at',
        'akad_at', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'lead_date' => 'date',
            'contacted_at' => 'datetime',
            'met_at' => 'datetime',
            'surveyed_at' => 'datetime',
            'utj_at' => 'datetime',
            'documents_completed_at' => 'datetime',
            'akad_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(LeadMaster::class, 'project_id');
    }

    public function sales(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sales_user_id');
    }

    public function leadSource(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->canViewAllBranches()) {
            return $query;
        }

        if ($user->isSales()) {
            return $query->where('sales_user_id', $user->id)
                ->whereIn('branch_id', app(WorkspaceAccessService::class)->accessibleBranchIds($user));
        }

        if (! $user->hasRole(['manager', 'admin'])) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('branch_id', app(WorkspaceAccessService::class)->accessibleBranchIds($user));
    }

    public function currentStage(): ?string
    {
        return collect(self::STAGE_ORDER)->last(fn (string $stage) => $this->{$stage} !== null);
    }

    public function currentStageLabel(): string
    {
        return self::STAGES[$this->currentStage()] ?? 'LEAD BARU';
    }

    public function getCurrentStageAttribute(): ?string
    {
        return $this->currentStage();
    }

    public function getCurrentStageLabelAttribute(): string
    {
        return $this->currentStageLabel();
    }

    public function lastActivityAt()
    {
        $dates = collect(self::STAGE_ORDER)->map(fn (string $stage) => $this->{$stage})->filter();

        return $dates->sortDesc()->first() ?? $this->lead_date?->startOfDay();
    }

    public function getLastActivityAtAttribute()
    {
        return $this->lastActivityAt();
    }

    public function logSalesActivity(string $event, array $properties = []): void
    {
        ActivityLog::create([
            'causer_id' => auth()->id(),
            'subject_type' => self::class,
            'subject_id' => $this->id,
            'event' => $event,
            'description' => match ($event) {
                'created' => 'Lead Buku Saku dibuat',
                'updated' => 'Lead Buku Saku diperbarui',
                'stage_updated' => 'Progres lead diperbarui',
                'stage_reversed' => 'Progres lead dibatalkan',
                default => 'Lead Buku Saku diperbarui',
            },
            // Deliberately limited to identifiers and stage metadata; never persist lead PII here.
            'properties' => array_intersect_key($properties, array_flip(['branch_id', 'project_id', 'sales_user_id', 'stage', 'stage_label', 'changed_fields'])),
        ]);
    }
}
