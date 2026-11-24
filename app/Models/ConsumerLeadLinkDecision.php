<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsumerLeadLinkDecision extends Model
{
    use HasFactory;

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

    public function salesLead(): BelongsTo
    {
        return $this->belongsTo(SalesLead::class, 'sales_lead_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
