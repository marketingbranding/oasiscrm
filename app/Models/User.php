<?php

namespace App\Models;

use App\Enums\AccountStatus;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
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
    'account_status',
    'supervisor_user_id',
    'invited_at',
    'activated_at',
    'suspended_at',
    'deactivated_at',
    'anonymized_at',
    'last_login_at',
    'last_login_ip',
    'last_login_user_agent',
    'created_by',
    'updated_by',
    'password_changed_at',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected static function booted(): void
    {
        static::saving(function (User $user) {
            if ($user->isDirty('account_status')) {
                $status = $user->account_status instanceof AccountStatus
                    ? $user->account_status
                    : AccountStatus::from($user->account_status);
                $user->is_active = $status === AccountStatus::Active;
            } elseif ($user->isDirty('is_active')) {
                $user->account_status = $user->is_active
                    ? AccountStatus::Active
                    : AccountStatus::Inactive;
            }
        });

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
            'account_status' => AccountStatus::class,
            'invited_at' => 'datetime',
            'activated_at' => 'datetime',
            'suspended_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'anonymized_at' => 'datetime',
            'last_login_at' => 'datetime',
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

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supervisor_user_id');
    }

    public function directReports(): HasMany
    {
        return $this->hasMany(self::class, 'supervisor_user_id');
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(UserInvitation::class);
    }

    public function userImportBatches(): HasMany
    {
        return $this->hasMany(UserImportBatch::class, 'uploaded_by');
    }

    public function latestInvitation(): HasOne
    {
        return $this->hasOne(UserInvitation::class)->latestOfMany();
    }

    public function activityLogs(): MorphMany
    {
        return $this->morphMany(ActivityLog::class, 'subject')->latest();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'updated_by');
    }

    public function isAccountActive(): bool
    {
        return $this->account_status === AccountStatus::Active;
    }

    public function assignedPlannerItems(): BelongsToMany
    {
        return $this->belongsToMany(ContentItem::class)->withTimestamps();
    }

    public function assignedProjects(): BelongsToMany
    {
        return $this->belongsToMany(LeadMaster::class, 'project_user', 'user_id', 'project_id')
            ->using(ProjectUser::class)
            ->withPivot(['is_primary', 'assignment_start_date', 'assignment_end_date', 'is_active'])
            ->withTimestamps();
    }

    public function salesSheetIdentities(): HasMany
    {
        return $this->hasMany(SalesSheetIdentity::class);
    }

    public function coordinatorSalesAssignments(): HasMany
    {
        return $this->hasMany(SalesCoordinatorSales::class, 'coordinator_user_id');
    }

    public function salesCoordinatorAssignments(): HasMany
    {
        return $this->hasMany(SalesCoordinatorSales::class, 'sales_user_id');
    }

    public function currentCoordinatorSales(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'sales_coordinator_sales', 'coordinator_user_id', 'sales_user_id')
            ->wherePivot('is_active', true)
            ->where(fn ($query) => $query->whereNull('sales_coordinator_sales.started_at')->orWhereDate('sales_coordinator_sales.started_at', '<=', today()))
            ->where(fn ($query) => $query->whereNull('sales_coordinator_sales.ended_at')->orWhereDate('sales_coordinator_sales.ended_at', '>=', today()))
            ->withPivot(['id', 'is_active', 'started_at', 'ended_at'])
            ->withTimestamps();
    }

    public function currentSalesCoordinators(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'sales_coordinator_sales', 'sales_user_id', 'coordinator_user_id')
            ->wherePivot('is_active', true)
            ->where(fn ($query) => $query->whereNull('sales_coordinator_sales.started_at')->orWhereDate('sales_coordinator_sales.started_at', '<=', today()))
            ->where(fn ($query) => $query->whereNull('sales_coordinator_sales.ended_at')->orWhereDate('sales_coordinator_sales.ended_at', '>=', today()))
            ->withPivot(['id', 'is_active', 'started_at', 'ended_at'])
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

    public function hasPermission(string $permission): bool
    {
        if (! Permission::isRegistered($permission)) {
            return false;
        }

        if ($this->isSuperadmin()) {
            return true;
        }

        // V1 intentionally has no per-user overrides or supplemental-role grants.
        return $this->role?->permissions->contains('slug', $permission) ?? false;
    }

    public function hasAnyPermission(array $permissions): bool
    {
        return collect($permissions)->contains(fn (string $permission) => $this->hasPermission($permission));
    }

    public function hasAllPermissions(array $permissions): bool
    {
        return collect($permissions)->every(fn (string $permission) => $this->hasPermission($permission));
    }

    public function hasScopedPermission(string $module, string $action = 'view'): bool
    {
        return $this->hasAnyPermission(collect(['own', 'team', 'assigned', 'branch', 'all'])
            ->map(fn (string $scope) => "{$module}.{$action}_{$scope}")
            ->all());
    }

    public function landingRouteName(): string
    {
        return $this->hasPrimaryRole(['sales', 'sales_coordinator', 'supervisor']) ? 'sales-pocketbook.index' : 'dashboard';
    }

    public function canViewAllBranches(): bool
    {
        if ($this->isSuperadmin()) {
            return true;
        }

        $allScopePermissions = collect([
            'sales_pocketbook', 'work_planner', 'database', 'consumer_progress', 'bridge_fund', 'expenses',
        ])->map(fn (string $module) => "{$module}.view_all")->all();

        return $this->role !== null && $this->hasAnyPermission($allScopePermissions);
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
