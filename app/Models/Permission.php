<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    protected $fillable = ['name', 'slug', 'description', 'group_name'];

    private static ?array $registeredSlugs = null;

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permission')->withTimestamps();
    }

    public static function isRegistered(string $slug): bool
    {
        self::$registeredSlugs ??= self::query()->pluck('slug')->flip()->all();

        return isset(self::$registeredSlugs[$slug]);
    }
}
