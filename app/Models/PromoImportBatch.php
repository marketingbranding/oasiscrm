<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PromoImportBatch extends Model
{
    protected $fillable = ['public_id', 'uploaded_by', 'branch_id', 'status', 'expires_at', 'confirmed_at', 'total_rows', 'valid_rows', 'invalid_rows', 'created_rows', 'updated_rows', 'skipped_rows'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'confirmed_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function rows(): HasMany
    {
        return $this->hasMany(PromoImportRow::class, 'batch_id');
    }
}
