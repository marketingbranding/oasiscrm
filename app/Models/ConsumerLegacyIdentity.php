<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsumerLegacyIdentity extends Model
{
    protected $fillable = [
        'application_id',
        'id_kons',
        'id_psjb',
        'id_berkas',
        'no_sp3k',
        'id_ppjb_dev',
        'no_ppjb_akad',
        'no_bast',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(ConsumerApplication::class, 'application_id');
    }
}
