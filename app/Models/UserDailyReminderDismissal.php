<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'reminder_key', 'dismissed_for_date', 'dismissed_at'])]
class UserDailyReminderDismissal extends Model
{
    protected function casts(): array
    {
        return [
            'dismissed_for_date' => 'date',
            'dismissed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
