<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HistoricalProcessImportBatch extends Model
{
    protected $fillable = [
        'public_id',
        'uploaded_by',
        'branch_id',
        'status',
        'total_rows',
        'valid_rows',
        'invalid_rows',
        'created_rows',
        'expires_at',
        'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function rows(): HasMany
    {
        return $this->hasMany(HistoricalProcessImportRow::class, 'batch_id');
    }
}
