<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsumerMigrationSourceRecord extends Model
{
    protected $fillable = ['import_transaction_id', 'consumer_application_id', 'source_system', 'branch_code', 'source_transaction_id', 'source_type', 'source_record_id', 'payload_hash', 'metadata', 'target_type', 'target_id'];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function importTransaction(): BelongsTo
    {
        return $this->belongsTo(MarisonImportTransaction::class, 'import_transaction_id');
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ConsumerApplication::class, 'consumer_application_id');
    }
}
