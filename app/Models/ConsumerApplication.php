<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class ConsumerApplication extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'customer_id',
        'branch_id',
        'project_id',
        'sales_user_id',
        'kavling_id',
        'promo_id',
        'sales_lead_id',
        'id_kavling',
        'nama_konsumen',
        'nik',
        'application_status',
        'consumer_status',
        'current_stage',
        'source_last_process',
        'source_completeness_status',
        'status_cash',
        'booking_date',
        'akad_date',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'nik' => 'encrypted',
            'status_cash' => 'boolean',
            'booking_date' => 'date',
            'akad_date' => 'date',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(LeadMaster::class, 'project_id');
    }

    public function sales(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sales_user_id');
    }

    public function kavling(): BelongsTo
    {
        return $this->belongsTo(Kavling::class);
    }

    public function promo(): BelongsTo
    {
        return $this->belongsTo(Promo::class);
    }

    public function salesLead(): BelongsTo
    {
        return $this->belongsTo(SalesLead::class);
    }

    public function legacyIdentity(): HasOne
    {
        return $this->hasOne(ConsumerLegacyIdentity::class, 'consumer_application_id');
    }

    public function legacyIdentities(): HasMany
    {
        return $this->hasMany(ConsumerLegacyIdentity::class, 'consumer_application_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ConsumerDocument::class, 'consumer_application_id');
    }

    public function stageEvents(): HasMany
    {
        return $this->hasMany(ConsumerStageEvent::class, 'consumer_application_id');
    }

    public function psjbs(): HasMany
    {
        return $this->hasMany(ConsumerPsjb::class, 'consumer_application_id');
    }

    public function bankProcesses(): HasMany
    {
        return $this->hasMany(ConsumerBankProcess::class, 'consumer_application_id');
    }
}
