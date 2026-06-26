<?php

namespace App\Http\Controllers\Crm\Traits;

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
        $branchId = $user->canViewAllBranches()
            ? $request->get('branch_id')
            : $user->branch_id;

        $result = ($this->importClass)::import(
            $request->file('file')->getPathname(),
            $branchId,
            $request->only($this->importPreservedParams)
        );

        $message = $result['imported'] . ' data berhasil diimport.';
        if (!empty($result['errors'])) {
            return redirect()->route($this->importErrorRoute)
                ->with('success', $message)
                ->with('import_errors', $result['errors']);
        }

        return redirect()->route($this->importSuccessRoute)
            ->with('success', $message);
    }
}
