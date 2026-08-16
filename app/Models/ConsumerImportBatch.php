<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConsumerImportBatch extends Model
{
    protected $fillable = [
        'public_id', 'uploaded_by', 'branch_id', 'project_id', 'source', 'status', 'expires_at', 'confirmed_at',
        'total_rows', 'parsed_rows', 'ready_rows', 'already_imported_rows', 'warning_rows', 'review_rows', 'invalid_rows',
        'created_customers', 'created_applications', 'reused_rows', 'skipped_rows',
    ];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'confirmed_at' => 'datetime'];
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

    public function project(): BelongsTo
    {
        return $this->belongsTo(LeadMaster::class, 'project_id');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(ConsumerImportRow::class, 'batch_id');
    }
}
