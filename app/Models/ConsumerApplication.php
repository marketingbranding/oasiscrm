<?php

namespace App\Models;

use Database\Factories\ConsumerApplicationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ConsumerApplication extends Model
{
    /** @use HasFactory<ConsumerApplicationFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'customer_id', 'branch_id', 'project_id', 'sales_user_id', 'kavling_id', 'promo_id', 'sales_lead_id',
        'application_status', 'consumer_status', 'status_cash', 'current_stage', 'source_last_process', 'source_completeness_status', 'booking_date', 'akad_date', 'notes',
    ];

    protected function casts(): array
    {
        return ['booking_date' => 'date', 'akad_date' => 'date', 'status_cash' => 'boolean'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
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

    public function stageEvents(): HasMany
    {
        return $this->hasMany(ConsumerStageEvent::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ConsumerDocument::class);
    }

    public function bankProcesses(): HasMany
    {
        return $this->hasMany(ConsumerBankProcess::class);
    }

    public function legacyIdentities(): HasMany
    {
        return $this->hasMany(ConsumerLegacyIdentity::class);
    }
}
