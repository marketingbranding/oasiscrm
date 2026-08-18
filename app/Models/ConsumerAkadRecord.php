<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsumerAkadRecord extends Model
{
    protected $fillable = ['consumer_application_id', 'consumer_stage_event_id', 'tanggal_akad', 'kualitas_akad', 'status_bangunan', 'status_dp_konsumen', 'status_utilitas', 'status_konsumen', 'keterangan_terlambat'];

    protected function casts(): array
    {
        return ['tanggal_akad' => 'date'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ConsumerApplication::class, 'consumer_application_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(ConsumerStageEvent::class, 'consumer_stage_event_id');
    }
}
