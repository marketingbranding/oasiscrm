<?php

namespace App\Providers;

use App\Models\ContentItem;
use Illuminate\Support\Facades\Auth;
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
                $view->with('overdueItems', collect())->with('todayItems', collect())->with('totalCount', 0);
                return;
            }

            $overdueItems = ContentItem::whereDate('scheduled_date', '<', today())
                ->where('status', '!=', 'posted')
                ->when(!$user->canViewAllBranches() && $user->branch_id, fn($q) => $q->where('branch_id', $user->branch_id))
                ->orderBy('scheduled_date')
                ->take(10)
                ->get();

            $todayItems = ContentItem::whereDate('scheduled_date', today())
                ->where('status', '!=', 'posted')
                ->when(!$user->canViewAllBranches() && $user->branch_id, fn($q) => $q->where('branch_id', $user->branch_id))
                ->orderBy('scheduled_date')
                ->take(10)
                ->get();

            $totalCount = $overdueItems->count() + $todayItems->count();

            $view->with(compact('overdueItems', 'todayItems', 'totalCount'));
        });
    }
}
