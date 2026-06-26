<?php

namespace App\Providers;

use App\Models\ContentItem;
use App\Models\DanaTalangan;
use App\Models\LeadEvent;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
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
        View::composer('layouts.crm', function ($view) {
            $user = Auth::user();
            if (!$user) {
                $view->with('overdueItems', collect())->with('todayItems', collect())->with('needsConfirmation', collect())->with('overdueEvents', collect())->with('totalCount', 0);
                return;
            }

            $branchScope = fn($q) => $q->when(!$user->canViewAllBranches() && $user->branch_id, fn($q2) => $q2->where('branch_id', $user->branch_id));
            $cacheKey = $user->canViewAllBranches() ? 'all' : 'branch_' . $user->branch_id;

            $overdueItems = Cache::remember('notif.overdue.' . $cacheKey, 60, fn() =>
                ContentItem::whereDate('scheduled_date', '<', today())
                    ->where('status', '!=', 'posted')
                    ->tap($branchScope)
                    ->orderBy('scheduled_date')
                    ->take(10)
                    ->get()
            );

            $todayItems = Cache::remember('notif.today.' . $cacheKey, 60, fn() =>
                ContentItem::whereDate('scheduled_date', today())
                    ->where('status', '!=', 'posted')
                    ->tap($branchScope)
                    ->orderBy('scheduled_date')
                    ->take(10)
                    ->get()
            );

            $needsConfirmation = Cache::remember('notif.confirm.' . $cacheKey, 60, fn() =>
                DanaTalangan::where('status', 'aktif')
                    ->where('konfirmasi_keuangan', false)
                    ->tap($branchScope)
                    ->orderBy('tanggal')
                    ->take(10)
                    ->get()
            );

            $overdueEvents = Cache::remember('notif.overdue-events.' . $cacheKey, 60, fn() =>
                LeadEvent::whereDate('end_date', '<', today())
                    ->where('status', 'berlangsung')
                    ->tap($branchScope)
                    ->orderBy('end_date')
                    ->take(10)
                    ->get()
            );

            $totalCount = $overdueItems->count() + $todayItems->count() + $needsConfirmation->count() + $overdueEvents->count();

            $view->with(compact('overdueItems', 'todayItems', 'needsConfirmation', 'overdueEvents', 'totalCount'));
        });
    }
}
