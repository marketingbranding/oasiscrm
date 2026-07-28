<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

#[Fillable([
    'name',
    'email',
    'password',
    'role_id',
    'branch_id',
    'phone',
    'is_active',
    'password_changed_at',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected static function booted(): void
    {
        static::saved(function (User $user) {
            if (! $user->branch_id || ! ($user->wasRecentlyCreated || $user->wasChanged('branch_id')) || ! Schema::hasTable('branch_user')) {
                return;
            }

            DB::table('branch_user')->insertOrIgnore([
                'user_id' => $user->id,
                'branch_id' => $user->branch_id,
                'can_view' => true,
                'can_edit' => true,
                'can_sync' => true,
                'can_manage_members' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function assignedPlannerItems(): BelongsToMany
    {
        return $this->belongsToMany(ContentItem::class)->withTimestamps();
    }

    public function assignedProjects(): BelongsToMany
    {
        return $this->belongsToMany(LeadMaster::class, 'project_user', 'user_id', 'project_id')
            ->using(ProjectUser::class)
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    public function primaryAssignedProject(): BelongsToMany
    {
        return $this->assignedProjects()->wherePivot('is_primary', true);
    }

    public function roles(): BelongsToMany
    {
        // Supplemental/legacy roles; role_id remains the primary application role.
        return $this->belongsToMany(Role::class)
            ->withTimestamps();
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class)
            ->withPivot(['membership_role', 'can_view', 'can_edit', 'can_sync', 'can_manage_members'])
            ->withTimestamps();
    }

    public function hasRole(string|array $roles): bool
    {
        $roles = is_array($roles) ? $roles : [$roles];

        if ($this->role && in_array($this->role->slug, $roles)) {
            return true;
        }

        return $this->roles()->whereIn('slug', $roles)->exists();
    }

    public function hasBranch(string|array $branches): bool
    {
        $branches = is_array($branches) ? $branches : [$branches];

        if ($this->branch && in_array($this->branch->code, $branches)) {
            return true;
        }

        return $this->branches()->whereIn('code', $branches)->exists();
    }

    public function isSuperadmin(): bool
    {
        return $this->role && $this->role->is_superadmin;
    }

    public function isSales(): bool
    {
        return $this->hasPrimaryRole('sales');
    }

    public function hasPrimaryRole(string|array $roles): bool
    {
        return in_array($this->role?->slug, (array) $roles, true);
    }

    public function landingRouteName(): string
    {
        return $this->isSales() ? 'sales-pocketbook.index' : 'dashboard';
    }

    public function canViewAllBranches(): bool
    {
        return $this->isSuperadmin() || $this->hasRole('pusat');
    }

    public function contentItems(): HasMany
    {
        return $this->hasMany(ContentItem::class, 'created_by');
    }

    public function ownedPlannerItems(): HasMany
    {
        return $this->hasMany(ContentItem::class, 'owner_user_id');
    }

    public function collaborationNotifications(): HasMany
    {
        return $this->hasMany(UserNotification::class);
    }

    public function dailyReminderDismissals(): HasMany
    {
        return $this->hasMany(UserDailyReminderDismissal::class);
    }

    public function salesLeads(): HasMany
    {
        return $this->hasMany(SalesLead::class, 'sales_user_id');
    }

    public function createdExpenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'created_by');
    }

    public function updatedExpenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'updated_by');
    }

    public function cancelledExpenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'cancelled_by');
    }

    public function createdExpenseCategories(): HasMany
    {
        return $this->hasMany(ExpenseCategory::class, 'created_by');
    }

    public function updatedExpenseCategories(): HasMany
    {
        return $this->hasMany(ExpenseCategory::class, 'updated_by');
    }
}
