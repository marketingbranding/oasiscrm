<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'batch_id', 'row_number', 'raw_data', 'normalized_data', 'validation_status', 'errors',
    'warnings', 'created_user_id', 'invitation_status', 'creation_status',
])]
class UserImportRow extends Model
{
    public const VALIDATION_VALID = 'valid';

    public const VALIDATION_WARNING = 'warning';

    public const VALIDATION_ERROR = 'error';

    public const VALIDATION_STATUSES = [
        self::VALIDATION_VALID, self::VALIDATION_WARNING, self::VALIDATION_ERROR,
    ];

    protected function casts(): array
    {
        return [
            'raw_data' => 'array',
            'normalized_data' => 'array',
            'errors' => 'array',
            'warnings' => 'array',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(UserImportBatch::class, 'batch_id');
    }

    public function createdUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_user_id');
    }
}
