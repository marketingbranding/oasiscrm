<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsumerPpjbDeveloper extends Model
{
    protected $fillable = ['consumer_application_id', 'consumer_stage_event_id', 'tanggal_sp3k', 'tanggal_ttd_ppjb', 'notes', 'source_system', 'source_branch_code', 'source_id', 'status', 'metadata'];

    protected function casts(): array
    {
        return ['tanggal_sp3k' => 'date', 'tanggal_ttd_ppjb' => 'date', 'metadata' => 'array'];
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
