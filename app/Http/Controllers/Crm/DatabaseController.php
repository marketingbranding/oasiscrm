<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\GoogleScriptService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DatabaseController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        $selectedBranchId = $request->get('branch_id');

        if ($user->isSuperadmin()) {
            $branches = Branch::where('is_active', true)->get();
            if (!$selectedBranchId && $branches->isNotEmpty()) {
                $selectedBranchId = $branches->first()->id;
            }
        } else {
            $branches = collect();
            $selectedBranchId = $user->branch_id;
        }

        $selectedBranch = $selectedBranchId ? Branch::find($selectedBranchId) : null;
        $branchCode = $selectedBranch?->code;
        $scriptUrl = config('services.google_script.webhook_url');

        $data = null;
        $error = null;

        if ($selectedBranch) {
            $service = new GoogleScriptService();
            $result = $service->fetchData([
                'sheet_id' => $selectedBranch->sheet_id,
                'branch' => $selectedBranch->code,
                'branch_id' => $selectedBranch->id,
            ]);

            $data = $result['data'] ?? null;
            if (!$result['success']) {
                $error = $result['error'];
            }
        }

        return view('crm.database.index', compact('branches', 'selectedBranch', 'selectedBranchId', 'branchCode', 'scriptUrl', 'data', 'error'));
    }

    public function fetch(Request $request)
    {
        $user = Auth::user();
        $branchId = $request->get('branch_id');

        if (!$user->isSuperadmin()) {
            $branchId = $user->branch_id;
        }

        $branch = Branch::findOrFail($branchId);
        $service = new GoogleScriptService();
        $result = $service->fetchData([
            'sheet_id' => $branch->sheet_id,
            'branch' => $branch->code,
            'branch_id' => $branch->id,
        ]);

        return response()->json($result);
    }
}
