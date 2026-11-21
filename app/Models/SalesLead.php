<?php

namespace App\Models;

use App\Enums\SalesLeadStatus;
use App\Services\CrmAuthorizationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalesLead extends Model
{
    use SoftDeletes;

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

    public const STAGE_V3_ORDER = [
        'lead_baru',
        'dihubungi',
        'tatap_muka',
        'survey',
        'utj',
        'gugur',
    ];

    public const STAGE_V3_LABELS = [
        'lead_baru' => 'Lead Baru',
        'dihubungi' => 'Dihubungi',
        'tatap_muka' => 'Tatap Muka',
        'survey' => 'Survey',
        'utj' => 'UTJ',
        'gugur' => 'Gugur',
    ];

    protected $fillable = [
        'branch_id', 'project_id', 'sales_user_id', 'lead_source_id', 'lead_date', 'customer_name', 'phone',
        'normalized_phone', 'source_name_snapshot', 'notes', 'linked_consumer_reference',
        'contacted_at', 'met_at', 'surveyed_at', 'utj_at', 'documents_completed_at',
        'akad_at', 'created_by', 'updated_by',
        'external_lead_id', 'external_sync_id', 'remote_target_branch_id', 'sync_status', 'last_synced_at', 'last_synced_payload_hash', 'last_remote_payload_hash', 'last_synced_field_hashes', 'delivery_attempted_at', 'delete_pending_at', 'last_sync_error',
        'id_promo', 'source', 'platform', 'campaign_id', 'campaign_name',
        'current_status', 'current_status_changed_at', 'current_status_source', 'current_status_source_id',
        'lead_stage', 'lead_stage_changed_at', 'gugur_at', 'gugur_reason',
        'completed_at', 'completed_by_user_id',
        'consumer_converted_at', 'freelance_converted_at', 'consumer_external_id',
        'freelance_external_id', 'slik_external_id', 'akad_external_id',
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
            'current_status' => SalesLeadStatus::class,
            'current_status_changed_at' => 'datetime',
            'lead_stage_changed_at' => 'datetime',
            'gugur_at' => 'datetime',
            'completed_at' => 'datetime',
            'consumer_converted_at' => 'datetime',
            'freelance_converted_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'delivery_attempted_at' => 'datetime',
            'delete_pending_at' => 'datetime',
            'last_synced_field_hashes' => 'array',
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

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_user_id');
    }

    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(SalesLeadStatusHistory::class);
    }

    public function stageHistories(): HasMany
    {
        return $this->hasMany(SalesLeadStageHistory::class);
    }

    public function latestStatusHistory(): HasOne
    {
        return $this->hasOne(SalesLeadStatusHistory::class)->ofMany([
            'changed_at' => 'max',
            'id' => 'max',
        ]);
    }

    public function siteVisits(): HasMany
    {
        return $this->hasMany(SalesLeadSiteVisit::class);
    }

    public function latestSiteVisit(): HasOne
    {
        return $this->hasOne(SalesLeadSiteVisit::class)->latestOfMany();
    }

    public function shouldShowSiteVisitResults(): bool
    {
        $status = $this->current_status instanceof SalesLeadStatus
            ? $this->current_status
            : SalesLeadStatus::tryFrom((string) $this->current_status);

        if ($status === SalesLeadStatus::SiteVisit) {
            return true;
        }

        $hasHistory = $this->relationLoaded('statusHistories')
            ? $this->statusHistories->contains(fn (SalesLeadStatusHistory $history) => $history->status === SalesLeadStatus::SiteVisit)
            : $this->statusHistories()->where('status', SalesLeadStatus::SiteVisit->value)->exists();

        if ($hasHistory) {
            return true;
        }

        return $this->relationLoaded('siteVisits')
            ? $this->siteVisits->isNotEmpty()
            : $this->siteVisits()->exists();
    }

    public function consumerLinks(): HasMany
    {
        return $this->hasMany(SalesLeadConsumerLink::class);
    }

    public function slikAttempts(): HasMany
    {
        return $this->hasMany(SalesLeadSlikAttempt::class);
    }

    public function freelanceLinks(): HasMany
    {
        return $this->hasMany(SalesLeadFreelanceLink::class);
    }

    public function akadLinks(): HasMany
    {
        return $this->hasMany(SalesLeadAkadLink::class);
    }

    public function lifecycleReconciliationItems(): HasMany
    {
        return $this->hasMany(SalesLeadLifecycleReconciliationItem::class);
    }

    public function getIsFreelanceAttribute(): bool
    {
        return $this->freelance_converted_at !== null;
    }

    public function getEffectiveSourceAttribute(): ?string
    {
        foreach ([$this->source, $this->source_name_snapshot, $this->leadSource?->name] as $source) {
            if (filled(trim((string) $source))) {
                return trim((string) $source);
            }
        }

        return null;
    }

    public function scopeWhereEffectiveSource(Builder $query, string $source): Builder
    {
        return $query->where(function (Builder $query) use ($source) {
            $query->where(fn (Builder $sourceQuery) => $sourceQuery
                ->whereRaw("TRIM(COALESCE(source, '')) <> ''")
                ->where('source', $source))
                ->orWhere(fn (Builder $snapshotQuery) => $snapshotQuery
                    ->whereRaw("TRIM(COALESCE(source, '')) = ''")
                    ->whereRaw("TRIM(COALESCE(source_name_snapshot, '')) <> ''")
                    ->where('source_name_snapshot', $source))
                ->orWhere(fn (Builder $relationQuery) => $relationQuery
                    ->whereRaw("TRIM(COALESCE(source, '')) = ''")
                    ->whereRaw("TRIM(COALESCE(source_name_snapshot, '')) = ''")
                    ->whereHas('leadSource', fn (Builder $leadSource) => $leadSource->where('name', $source)));
        });
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return app(CrmAuthorizationService::class)->salesLeadQuery($query, $user);
    }

    public function currentStage(): ?string
    {
        return collect(self::STAGE_ORDER)->last(fn (string $stage) => $this->{$stage} !== null);
    }

    public function resolveStageV3(): string
    {
        if (filled($this->lead_stage)) {
            return (string) $this->lead_stage;
        }

        if ($this->utj_at !== null || ($this->current_status?->precedence() ?? -1) >= SalesLeadStatus::Utj->precedence()) {
            return 'utj';
        }

        if ($this->surveyed_at !== null) {
            return 'survey';
        }

        if ($this->met_at !== null) {
            return 'tatap_muka';
        }

        if ($this->contacted_at !== null) {
            return 'dihubungi';
        }

        return 'lead_baru';
    }

    public static function stageV3FromLegacy(string|SalesLeadStatus|null $status): string
    {
        if ($status === null) {
            return 'lead_baru';
        }

        $resolved = $status instanceof SalesLeadStatus ? $status : SalesLeadStatus::fromInput($status);

        return match ($resolved) {
            SalesLeadStatus::Discussion => 'dihubungi',
            SalesLeadStatus::FaceToFace => 'tatap_muka',
            SalesLeadStatus::SiteVisit => 'survey',
            SalesLeadStatus::Utj,
            SalesLeadStatus::SlikCheck,
            SalesLeadStatus::SlikRejected,
            SalesLeadStatus::Akad => 'utj',
            default => 'lead_baru',
        };
    }

    public function stageV3Label(): string
    {
        return self::STAGE_V3_LABELS[$this->resolveStageV3()] ?? 'Lead Baru';
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
                'completed' => 'Lead Buku Saku ditandai selesai',
                'deleted' => 'Lead Buku Saku dihapus',
                default => 'Lead Buku Saku diperbarui',
            },
            // Deliberately limited to identifiers and stage metadata; never persist lead PII here.
            'properties' => array_intersect_key($properties, array_flip(['branch_id', 'project_id', 'sales_user_id', 'stage', 'stage_label', 'changed_fields'])),
        ]);
    }
}
