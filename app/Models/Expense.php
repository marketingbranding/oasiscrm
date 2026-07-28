<?php

namespace App\Models;

use App\Models\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Expense extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = [
        'expense_date',
        'branch_id',
        'project_id',
        'expense_category_id',
        'amount',
        'description',
        'vendor_name',
        'payment_method',
        'reference_number',
        'notes',
        'status',
        'cancellation_reason',
        'cancelled_at',
        'cancelled_by',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'expense_date' => 'date',
            'amount' => 'decimal:2',
            'cancelled_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(LeadMaster::class, 'project_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    protected function activityLabel(): string
    {
        $rawAmount = (float) $this->getRawOriginal('amount');
        $amount = number_format($rawAmount, fmod($rawAmount, 1.0) === 0.0 ? 0 : 2, ',', '.');
        $context = $this->project?->project_name ?? $this->branch?->name ?? 'Tanpa lokasi';

        return "{$this->description} - Rp{$amount} - {$context}";
    }
}
