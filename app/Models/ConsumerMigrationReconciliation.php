<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsumerMigrationReconciliation extends Model
{
    protected $fillable = ['consumer_application_id', 'branch_id', 'project_id', 'source_system', 'source_transaction_id', 'source_customer_ref', 'source_current_kavling', 'source_transaction_status', 'source_bank_status', 'source_kavling_status', 'source_stage_status', 'suggested_decision', 'decision', 'customer_id', 'target_kavling_id', 'reconciliation_status', 'source_payload_hash', 'resolved_by', 'resolved_at'];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ConsumerApplication::class, 'consumer_application_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(LeadMaster::class, 'project_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function targetKavling(): BelongsTo
    {
        return $this->belongsTo(Kavling::class, 'target_kavling_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
