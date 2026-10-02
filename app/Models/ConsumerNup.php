<?php

namespace App\Models;

use Database\Factories\ConsumerNupFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsumerNup extends Model
{
    /** @use HasFactory<ConsumerNupFactory> */
    use HasFactory;

    protected $fillable = [
        'customer_id', 'branch_id', 'project_id', 'converted_application_id', 'nup_number',
        'registered_at', 'status', 'source', 'source_id', 'notes', 'metadata',
    ];

    protected function casts(): array
    {
        return ['registered_at' => 'date', 'metadata' => 'array'];
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

    public function convertedApplication(): BelongsTo
    {
        return $this->belongsTo(ConsumerApplication::class, 'converted_application_id');
    }
}
