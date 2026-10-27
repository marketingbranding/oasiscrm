<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesDailyPlanItemEvidence extends Model
{
    protected $table = 'sales_daily_plan_item_evidences';

    protected $fillable = [
        'sales_daily_plan_item_id',
        'uploaded_by_user_id',
        'storage_path',
        'file_path',
        'original_name',
        'mime_type',
        'width',
        'height',
        'size_bytes',
        'sha256',
        'checksum',
        'archive_id',
        'archive_entry_path',
        'archive_status',
        'archived_at',
        'purged_at',
    ];

    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
            'purged_at' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(SalesDailyPlanItem::class, 'sales_daily_plan_item_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function archive(): BelongsTo
    {
        return $this->belongsTo(SalesAgendaEvidenceArchive::class, 'archive_id');
    }
}
