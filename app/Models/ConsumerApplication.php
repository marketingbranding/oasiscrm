<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ConsumerApplication extends Model
{
    protected $fillable = [
        'branch_id',
        'id_kavling',
        'nama_konsumen',
        'nik',
    ];

    protected function casts(): array
    {
        return [
            'nik' => 'encrypted',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function legacyIdentity(): HasOne
    {
        return $this->hasOne(ConsumerLegacyIdentity::class, 'application_id');
    }

    public function stageEvents(): HasMany
    {
        return $this->hasMany(ConsumerStageEvent::class, 'application_id');
    }

    public function bankProcesses(): HasMany
    {
        return $this->hasMany(ConsumerBankProcess::class, 'application_id');
    }
}
