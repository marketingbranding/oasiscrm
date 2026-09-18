<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarisonImportTransaction extends Model
{
    protected $fillable = ['batch_id', 'source_system', 'branch_code', 'source_transaction_id', 'source_customer_ref', 'source_project_id', 'project_mapping_id', 'resolved_project_id', 'outcome', 'payload_hash', 'payload', 'errors', 'consumer_application_id', 'reconciliation_id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'errors' => 'array'];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(MarisonImportBatch::class, 'batch_id');
    }

    public function projectMapping(): BelongsTo
    {
        return $this->belongsTo(MarisonProjectMapping::class, 'project_mapping_id');
    }

    public function resolvedProject(): BelongsTo
    {
        return $this->belongsTo(LeadMaster::class, 'resolved_project_id');
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ConsumerApplication::class, 'consumer_application_id');
    }

    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(ConsumerMigrationReconciliation::class, 'reconciliation_id');
    }

    public function sourceRecords(): HasMany
    {
        return $this->hasMany(ConsumerMigrationSourceRecord::class, 'import_transaction_id');
    }
}
