<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccessGrant extends Model
{
    /** @return array<int, string> */
    public static function scopeModules(string $grantModule): array
    {
        return match ($grantModule) {
            'sales_leads' => ['sales_pocketbook'],
            'sales_daily_plans' => ['sales_pocketbook', 'work_planner'],
            default => [],
        };
    }

    /** @return array<int, string> */
    public static function grantModulesForScope(string $scopeModule): array
    {
        return match ($scopeModule) {
            'sales_pocketbook' => ['sales_leads'],
            'work_planner' => ['sales_daily_plans'],
            default => in_array($scopeModule, self::MODULES, true) ? [$scopeModule] : [],
        };
    }

    public const MODULES = ['sales_leads', 'sales_daily_plans'];

    protected $fillable = [
        'grantee_user_id', 'subject_user_id', 'include_descendants', 'module',
        'can_view', 'can_edit', 'expires_at', 'reason', 'granted_by',
        'revoked_at', 'revoked_by', 'revocation_reason',
    ];

    protected function casts(): array
    {
        return [
            'include_descendants' => 'boolean',
            'can_view' => 'boolean',
            'can_edit' => 'boolean',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function grantee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'grantee_user_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')->where('expires_at', '>', now());
    }
}
