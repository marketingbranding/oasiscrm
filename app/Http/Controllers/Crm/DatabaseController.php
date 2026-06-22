<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DatabaseController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        $selectedBranchId = $request->get('branch_id');

        if ($user->canViewAllBranches()) {
            $branches = Branch::where('is_active', true)->get();
            if ($selectedBranchId) {
                // use selected
            } elseif ($user->hasRole('pusat') && $user->branch_id) {
                $selectedBranchId = $user->branch_id;
            } elseif ($branches->isNotEmpty()) {
                $selectedBranchId = $branches->first()->id;
            }
        } else {
            $branches = collect();
            $selectedBranchId = $user->branch_id;
        }

        $selectedBranch = $selectedBranchId ? Branch::find($selectedBranchId) : null;
        $branchCode = $selectedBranch?->code;

        return view('crm.database.index', compact('branches', 'selectedBranch', 'selectedBranchId', 'branchCode'));
    }
}
