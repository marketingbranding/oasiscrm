<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesAgendaEvidence extends Model
{
    protected $table = 'sales_agenda_evidences';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['purged_at' => 'datetime'];
    }

    public function agenda()
    {
        return $this->belongsTo(ContentItem::class, 'content_item_id');
    }

    public function archive()
    {
        return $this->belongsTo(SalesAgendaEvidenceArchive::class);
    }
}
