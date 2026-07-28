<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class CommentMention extends Pivot
{
    protected $table = 'comment_mentions';

    protected $fillable = ['comment_id', 'mentioned_user_id'];

    public $incrementing = true;

    public function comment(): BelongsTo
    {
        return $this->belongsTo(Comment::class);
    }

    public function mentionedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'mentioned_user_id');
    }
}
