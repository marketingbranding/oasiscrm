<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesLeadTarget extends Model
{
    protected $fillable = [
        'sales_user_id',
        'target_month',
        'target_leads',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'target_month' => 'date',
            'target_leads' => 'integer',
        ];
    }

    public function sales(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sales_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
