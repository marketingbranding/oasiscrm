<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarisonImportBatch extends Model
{
    protected $fillable = ['public_id', 'uploaded_by', 'source_system', 'source_branch_code', 'source_spreadsheet_id', 'source_version', 'source_exported_at', 'contract_version', 'payload_hash', 'preview_hash', 'preview_version', 'status', 'expires_at', 'confirmed_at', 'counts', 'error_summary'];

    protected function casts(): array
    {
        return ['source_exported_at' => 'datetime', 'expires_at' => 'datetime', 'confirmed_at' => 'datetime', 'counts' => 'array'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(MarisonImportTransaction::class, 'batch_id');
    }

    public function unlinkedRecords(): HasMany
    {
        return $this->hasMany(MarisonUnlinkedRecord::class, 'batch_id');
    }
}
