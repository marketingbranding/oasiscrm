<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiChatConversation extends Model
{
    protected $fillable = [
        'user_id',
        'branch_id',
        'title',
        'messages',
        'provider',
        'model',
    ];

    protected function casts(): array
    {
        return [
            'messages' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
