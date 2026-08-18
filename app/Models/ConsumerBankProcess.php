<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsumerBankProcess extends Model
{
    protected $fillable = [
        'application_id',
        'id_berkas',
        'no_sp3k',
        'bank',
        'kc_unit',
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
    ];

    protected function casts(): array
    {
        return [
            'request_plafond' => 'decimal:2',
            'approved_plafond' => 'decimal:2',
            'request_tenor' => 'integer',
            'approved_tenor' => 'integer',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ConsumerApplication::class, 'application_id');
    }
}
