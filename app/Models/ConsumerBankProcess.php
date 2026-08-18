<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsumerBankProcess extends Model
{
    use HasFactory;

    protected $fillable = [
        'consumer_application_id',
        'tanggal_terima_bank',
        'id_berkas',
        'no_sp3k',
        'bank_name',
        'kc_unit',
        'tipe_pemberkasan',
        'request_plafond',
        'request_tenor',
        'approved_plafond',
        'approved_tenor',
        'response_type',
        'status',
        'revision_category',
        'revision_detail',
        'obstacle',
        'notes',
        'submitted_at',
        'verified_at',
        'sp3k_at',
        'rejected_at',
        'rejection_reason',
        'source',
        'source_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'tanggal_terima_bank' => 'date',
            'request_plafond' => 'decimal:2',
            'approved_plafond' => 'decimal:2',
            'request_tenor' => 'integer',
            'approved_tenor' => 'integer',
            'submitted_at' => 'datetime',
            'verified_at' => 'datetime',
            'sp3k_at' => 'datetime',
            'rejected_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ConsumerApplication::class, 'consumer_application_id');
    }
}
