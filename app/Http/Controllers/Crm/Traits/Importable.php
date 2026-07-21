<?php

namespace App\Http\Controllers\Crm\Traits;

use App\Services\WorkspaceAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

trait Importable
{
    public function import()
    {
        return view($this->importView);
    }

    public function importStore(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:xlsx']);

        $user = Auth::user();
        $requestedBranchId = $request->get('branch_id');
        $workspaceAccess = app(WorkspaceAccessService::class);
        $editableBranchIds = $workspaceAccess->accessibleBranches($user)
            ->filter(fn ($candidate) => $workspaceAccess->canEditBranch($user, $candidate))
            ->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        $branch = $requestedBranchId
            ? $workspaceAccess->resolveRequestedBranch($user, $requestedBranchId)
            : null;
        if ($requestedBranchId && ! $branch) {
            abort(403);
        }
        if ($branch && ! $workspaceAccess->canEditBranch($user, $branch)) {
            abort(403);
        }
        abort_if(empty($editableBranchIds), 403);
        $branchId = $branch?->id;

        $result = ($this->importClass)::import(
            $request->file('file')->getPathname(),
            $branchId,
            $request->only($this->importPreservedParams),
            $editableBranchIds,
        );

        $message = $result['imported'].' data berhasil diimport.';
        if (! empty($result['errors'])) {
            return redirect()->route($this->importErrorRoute)
                ->with('success', $message)
                ->with('import_errors', $result['errors']);
        }

        return redirect()->route($this->importSuccessRoute)
            ->with('success', $message);
    }
}
