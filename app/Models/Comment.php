<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Comment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'commentable_type', 'commentable_id', 'user_id', 'parent_id', 'body', 'body_plain', 'edited_at', 'lock_version',
    ];

    protected function casts(): array
    {
        return [
            'edited_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }

    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function mentionRecords(): HasMany
    {
        return $this->hasMany(CommentMention::class);
    }

    public function mentions(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'comment_mentions', 'comment_id', 'mentioned_user_id')
            ->using(CommentMention::class)
            ->withTimestamps();
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(CommentRevision::class)->latest('created_at');
    }

    public function moderations(): HasMany
    {
        return $this->hasMany(CommentModeration::class)->latest('created_at');
    }
}
