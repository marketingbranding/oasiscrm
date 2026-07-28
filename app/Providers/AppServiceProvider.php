<?php

namespace App\Providers;

use App\Models\DanaTalangan;
use App\Models\Permission;
use App\Models\User;
use App\Policies\UserPolicy;
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
        Gate::policy(User::class, UserPolicy::class);
        Gate::before(function (User $user, string $ability): ?bool {
            return Permission::isRegistered($ability) ? $user->hasPermission($ability) : null;
        });

        View::composer('layouts.crm', function ($view) {
            $user = Auth::user();
            if (! $user) {
                $view->with('overdueItems', collect())->with('todayItems', collect())->with('tomorrowItems', collect())->with('needsConfirmation', collect())->with('totalCount', 0);

                return;
            }

            if ($user->isSales() && request()->boolean('reminder_dismiss_failed')) {
                session()->flash('warning', 'Pengingat belum dapat disembunyikan untuk hari ini. Pengingat mungkin muncul kembali.');
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

            $view->with(compact('overdueItems', 'todayItems', 'tomorrowItems', 'needsConfirmation', 'totalCount'));
        });
    }
}
