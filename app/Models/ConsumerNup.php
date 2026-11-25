<?php

namespace App\Models;

use Database\Factories\ConsumerNupFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsumerNup extends Model
{
    public const array ENTRY_TYPES = ['from_lead', 'returned', 'legacy'];

    /** @use HasFactory<ConsumerNupFactory> */
    use HasFactory;

    protected $fillable = [
        'customer_id', 'branch_id', 'project_id', 'converted_application_id', 'source_application_id', 'issue_id', 'nup_number',
        'registered_at', 'status', 'entry_type', 'source', 'source_id', 'notes', 'metadata',
    ];

    protected function casts(): array
    {
        return ['registered_at' => 'date', 'metadata' => 'array'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(LeadMaster::class, 'project_id');
    }

    public function convertedApplication(): BelongsTo
    {
        return $this->belongsTo(ConsumerApplication::class, 'converted_application_id');
    }

    public function sourceApplication(): BelongsTo
    {
        return $this->belongsTo(ConsumerApplication::class, 'source_application_id');
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(ConsumerIssue::class, 'issue_id');
    }

    public function provenanceType(): string
    {
        return in_array($this->entry_type, self::ENTRY_TYPES, true) ? $this->entry_type : 'legacy';
    }
}
