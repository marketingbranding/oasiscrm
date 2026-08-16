<?php

namespace App\Models;

use Database\Factories\ConsumerLegacyIdentityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsumerLegacyIdentity extends Model
{
    /** @use HasFactory<ConsumerLegacyIdentityFactory> */
    use HasFactory;

    protected $fillable = ['consumer_application_id', 'customer_id', 'legacy_source', 'spreadsheet_id', 'sheet_name', 'external_key', 'legacy_row_number', 'source_payload_hash', 'first_seen_at', 'last_seen_at', 'mapping_status'];

    protected function casts(): array
    {
        return ['first_seen_at' => 'datetime', 'last_seen_at' => 'datetime'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ConsumerApplication::class, 'consumer_application_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
