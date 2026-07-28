<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommentRevision extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['comment_id', 'edited_by', 'previous_body', 'previous_mentioned_user_ids'];

    protected function casts(): array
    {
        return ['previous_mentioned_user_ids' => 'array'];
    }

    public function comment(): BelongsTo
    {
        return $this->belongsTo(Comment::class);
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by');
    }
}
