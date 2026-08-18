<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsumerLegacyIdentity extends Model
{
    use HasFactory;

    protected $fillable = [
        'consumer_application_id',
        'customer_id',
        'legacy_source',
        'external_key',
        'spreadsheet_id',
        'sheet_name',
        'source_payload_hash',
        'first_seen_at',
        'last_seen_at',
        'mapping_status',
        'id_kons',
        'id_psjb',
        'id_berkas',
        'no_sp3k',
        'id_ppjb_dev',
        'no_ppjb_akad',
        'no_bast',
    ];

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ConsumerApplication::class, 'consumer_application_id');
    }
}
