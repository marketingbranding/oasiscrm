<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommentModeration extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['comment_id', 'moderated_by', 'action', 'reason'];

    public function comment(): BelongsTo
    {
        return $this->belongsTo(Comment::class);
    }

    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderated_by');
    }
}
