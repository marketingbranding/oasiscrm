<?php

namespace App\Providers;

use App\Models\ContentItem;
use App\Models\DanaTalangan;
use App\Models\Lead;
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
                $view->with('overdueItems', collect())->with('todayItems', collect())->with('needsConfirmation', collect())->with('totalCount', 0);
                return;
            }

            $branchScope = fn($q) => $q->when(!$user->canViewAllBranches() && $user->branch_id, fn($q2) => $q2->where('branch_id', $user->branch_id));

            $overdueItems = ContentItem::whereDate('scheduled_date', '<', today())
                ->where('status', '!=', 'posted')
                ->tap($branchScope)
                ->orderBy('scheduled_date')
                ->take(10)
                ->get();

            $todayItems = ContentItem::whereDate('scheduled_date', today())
                ->where('status', '!=', 'posted')
                ->tap($branchScope)
                ->orderBy('scheduled_date')
                ->take(10)
                ->get();

            $needsConfirmation = DanaTalangan::where('status', 'aktif')
                ->where('konfirmasi_keuangan', false)
                ->tap($branchScope)
                ->orderBy('tanggal')
                ->take(10)
                ->get();

            $overdueEvents = collect();

            $totalCount = $overdueItems->count() + $todayItems->count() + $needsConfirmation->count();

            $view->with(compact('overdueItems', 'todayItems', 'needsConfirmation', 'totalCount'));
        });
    }
}
