<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsumerLeadLinkDecision extends Model
{
    use HasFactory;

    public const SUBJECT_APPLICATION = 'application';

    public const SUBJECT_NUP = 'nup';

    public const SUBJECT_SHEET = 'sheet';

    public const STATUS_LINKED = 'linked';

    public const STATUS_WITHOUT_LEAD = 'without_lead';

    public const STATUS_LEGACY = 'legacy';

    public const STATUSES = [
        self::STATUS_LINKED,
        self::STATUS_WITHOUT_LEAD,
        self::STATUS_LEGACY,
    ];

    protected $fillable = [
        'consumer_application_id',
        'consumer_nup_id',
        'branch_id',
        'legacy_consumer_key',
        'legacy_sheet_row_id',
        'subject_type',
        'subject_key',
        'status',
        'sales_lead_id',
        'decision_source',
        'suggestions',
        'reason',
        'decided_by',
        'decided_at',
        'is_active',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $decision): void {
            if ($decision->subject_type === null && $decision->consumer_application_id !== null) {
                $decision->subject_type = self::SUBJECT_APPLICATION;
            }

            if ($decision->branch_id === null && $decision->consumerApplication !== null) {
                $decision->branch_id = $decision->consumerApplication->branch_id;
            }

            if ($decision->subject_key === null && $decision->subject_type === self::SUBJECT_APPLICATION && $decision->consumer_application_id !== null) {
                $decision->subject_key = 'application:'.$decision->consumer_application_id;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'suggestions' => 'array',
            'decided_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function consumerApplication(): BelongsTo
    {
        return $this->belongsTo(ConsumerApplication::class, 'consumer_application_id');
    }

    public function consumerNup(): BelongsTo
    {
        return $this->belongsTo(ConsumerNup::class, 'consumer_nup_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function legacySheetRow(): BelongsTo
    {
        return $this->belongsTo(KonsumenProgressSheetRow::class, 'legacy_sheet_row_id');
    }

    public function salesLead(): BelongsTo
    {
        return $this->belongsTo(SalesLead::class, 'sales_lead_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
