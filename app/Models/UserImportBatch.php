<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'original_filename', 'uploaded_by', 'status', 'total_rows', 'valid_rows', 'warning_rows',
    'error_rows', 'send_invitations', 'confirmed_at', 'completed_at', 'expires_at',
    'created_rows', 'invitation_sent_rows', 'invitation_failed_rows', 'skipped_rows',
])]
class UserImportBatch extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_VALIDATING = 'validating';

    public const STATUS_READY = 'ready';

    public const STATUS_PREVIEW_READY = 'preview_ready';

    public const STATUS_VALIDATION_FAILED = 'validation_failed';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUSES = [
        self::STATUS_DRAFT, self::STATUS_VALIDATING, self::STATUS_READY,
        self::STATUS_PREVIEW_READY, self::STATUS_VALIDATION_FAILED, self::STATUS_CONFIRMED,
        self::STATUS_PROCESSING, self::STATUS_COMPLETED, self::STATUS_FAILED,
    ];

    protected function casts(): array
    {
        return [
            'send_invitations' => 'boolean',
            'confirmed_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(UserImportRow::class, 'batch_id');
    }
}
