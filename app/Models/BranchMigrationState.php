<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BranchMigrationState extends Model
{
    public const STATE_SHEET = 'sheet';

    public const STATE_MIGRASI = 'migrasi';

    public const STATE_PARALEL = 'paralel';

    public const STATE_OASIS_FULL = 'oasis_penuh';

    public const STATES = [
        self::STATE_SHEET,
        self::STATE_MIGRASI,
        self::STATE_PARALEL,
        self::STATE_OASIS_FULL,
    ];

    protected $fillable = [
        'branch_id',
        'state',
        'changed_by',
        'changed_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'changed_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
