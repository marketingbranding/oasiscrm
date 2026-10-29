<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesAgendaEvidenceArchive extends Model
{
    protected $table = 'sales_agenda_evidence_archives';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['week_start' => 'date', 'manifest' => 'array', 'verified_at' => 'datetime'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(SalesAgendaEvidence::class, 'archive_id');
    }
}
