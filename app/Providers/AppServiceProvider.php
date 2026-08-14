<?php

namespace App\Providers;

use App\Auth\ActiveUserProvider;
use App\Models\Comment;
use App\Models\DanaTalangan;
use App\Models\Permission;
use App\Models\User;
use App\Policies\CommentPolicy;
use App\Policies\DanaTalanganPolicy;
use App\Policies\UserPolicy;
use App\Services\ImpersonationService;
use App\Services\ModuleMaintenanceService;
use App\Services\NavigationService;
use App\Services\WorkPlannerReminderService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Auth::provider('active-eloquent', fn ($app, array $config) => new ActiveUserProvider($app['hash'], $config['model']));

        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Comment::class, CommentPolicy::class);
        Gate::policy(DanaTalangan::class, DanaTalanganPolicy::class);
        Gate::define('viewDesignSystem', fn (User $user): bool => $user->isSuperadmin());
        Gate::before(function (User $user, string $ability): ?bool {
            return Permission::isRegistered($ability) ? $user->hasPermission($ability) : null;
        });

        View::composer('layouts.crm', function ($view) {
            $user = Auth::user();
            $impersonationBanner = null;
            $impersonation = app(ImpersonationService::class);
            if ($user && $impersonation->isActive(request())) {
                $target = $impersonation->targetUser(request());
                if ($target?->is($user)) {
                    $target->loadMissing(['role', 'branch']);
                    $impersonationBanner = [
                        'name' => $target->name,
                        'role' => $target->role?->name ?? '-',
                        'branch' => $target->branch?->name ?? '-',
                        'started_at' => request()->session()->get('impersonation.started_at'),
                        'stop_route' => route('impersonation.stop'),
                    ];
                }
            }

            $view->with('impersonationBanner', $impersonationBanner);
            $view->with('moduleMaintenanceContext', request()->attributes->get('module_maintenance_context'));
            if (! $user) {
                $view->with('navigation', [])->with('overdueItems', collect())->with('todayItems', collect())->with('tomorrowItems', collect())->with('needsConfirmation', collect())->with('totalCount', 0);

                return;
            }

            $moduleMaintenance = app(ModuleMaintenanceService::class)->enabledMap();

            if ($user->isSales() && request()->boolean('reminder_dismiss_failed')) {
                session()->flash('warning', 'Pengingat belum dapat disembunyikan untuk hari ini. Pengingat mungkin muncul kembali.');
            }

            if (request()->routeIs('admin.design-system')) {
                $view->with('navigation', app(NavigationService::class)->forUser($user, moduleMaintenance: $moduleMaintenance))
                    ->with('overdueItems', collect())->with('todayItems', collect())->with('tomorrowItems', collect())
                    ->with('needsConfirmation', collect())->with('totalCount', 0);

                return;
            }

            $branchScope = fn ($q) => $q->when(! $user->canViewAllBranches() && $user->branch_id, fn ($q2) => $q2->where('branch_id', $user->branch_id));

            $plannerReminders = app(WorkPlannerReminderService::class)->forUser($user);
            $overdueItems = $plannerReminders['overdue'];
            $todayItems = $plannerReminders['today'];
            $tomorrowItems = $plannerReminders['tomorrow'];

            $needsConfirmation = $user->isSales()
                ? collect()
                : DanaTalangan::where('status', '!=', 'lunas')
                    ->where('konfirmasi_keuangan', false)
                    ->tap($branchScope)
                    ->orderBy('tanggal')
                    ->take(10)
                    ->get();

            $overdueEvents = collect();

            $totalCount = $overdueItems->count() + $todayItems->count() + $tomorrowItems->count() + $needsConfirmation->count();
            $navigation = app(NavigationService::class)->forUser($user, moduleMaintenance: $moduleMaintenance);

            $view->with(compact('navigation', 'overdueItems', 'todayItems', 'tomorrowItems', 'needsConfirmation', 'totalCount'));
        });
    }
}
