<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\ContentItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        $selectedBranchId = $request->get('branch_id');

        if ($user->canViewAllBranches()) {
            $branches = Branch::where('is_active', true)->get();

            if ($selectedBranchId) {
                $branch = Branch::findOrFail($selectedBranchId);
            } elseif ($user->hasRole('pusat') && $user->branch_id) {
                $selectedBranchId = $user->branch_id;
                $branch = Branch::findOrFail($selectedBranchId);
            } else {
                $branch = null;
            }

            $totalContent = $selectedBranchId
                ? ContentItem::where('branch_id', $selectedBranchId)->count()
                : ContentItem::count();

            $upcomingContent = $selectedBranchId
                ? ContentItem::where('branch_id', $selectedBranchId)->where('scheduled_date', '>=', now()->today())->orderBy('scheduled_date')->take(5)->get()
                : ContentItem::where('scheduled_date', '>=', now()->today())->orderBy('scheduled_date')->take(5)->get();

            $branchStatuses = Branch::withCount(['contentItems'])->where('is_active', true)->get()->map(function ($b) {
                $b->posted_count = ContentItem::where('branch_id', $b->id)->where('status', 'posted')->count();
                return $b;
            });

            $recentUpdates = ContentItem::with(['branch', 'creator'])->latest()->take(5)->get();

            return view('crm.dashboard', compact('branches', 'branch', 'selectedBranchId', 'totalContent', 'upcomingContent', 'branchStatuses', 'recentUpdates'));
        }

        $branch = $user->branch;
        if (!$branch) {
            $branches = Branch::where('is_active', true)->get();
            return view('crm.dashboard', compact('branches', 'branch', 'selectedBranchId'))->with('error', 'Anda belum memiliki cabang.');
        }

        $totalContent = ContentItem::where('branch_id', $branch->id)->count();
        $upcomingContent = ContentItem::where('branch_id', $branch->id)->where('scheduled_date', '>=', now()->today())->orderBy('scheduled_date')->take(5)->get();
        $recentUpdates = ContentItem::where('branch_id', $branch->id)->with('creator')->latest()->take(5)->get();
        $totalPosted = ContentItem::where('branch_id', $branch->id)->where('status', 'posted')->count();

        return view('crm.dashboard', compact('branch', 'totalContent', 'upcomingContent', 'recentUpdates', 'totalPosted'));
    }
}
