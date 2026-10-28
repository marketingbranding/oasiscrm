<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesAgendaTask extends Model
{
    protected $fillable = [
        'content_item_id',
        'title',
        'is_completed',
        'completed_at',
        'sort_order',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_completed' => 'boolean',
            'completed_at' => 'datetime',
            'sort_order' => 'integer',
        ];
    }

    public function agenda(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class, 'content_item_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
