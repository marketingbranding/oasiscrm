<?php

namespace App\Http\Controllers\Crm\Traits;

use Illuminate\Http\Request;

trait BulkOperations
{
    public function bulkDestroy(Request $request)
    {
        $ids = array_filter(explode(',', $request->input('selected_ids', '')));
        if (empty($ids)) {
            return back()->with('error', 'Tidak ada data yang dipilih.');
        }

        $query = $this->applyBranchScope(($this->bulkModel)::whereIn('id', $ids), null);
        $count = $query->delete();

        return redirect()->route($this->bulkRedirectRoute, array_filter($request->only($this->bulkRedirectParams)))
            ->with('success', "$count {$this->bulkLabel} berhasil dihapus.");
    }

    public function bulkUpdate(Request $request)
    {
        $ids = array_filter(explode(',', $request->input('selected_ids', '')));
        if (empty($ids)) {
            return back()->with('error', 'Tidak ada data yang dipilih.');
        }

        $newStatus = $request->input('new_status', $this->bulkDefaultStatus ?? '');
        if (!empty($this->bulkStatusOptions) && !in_array($newStatus, $this->bulkStatusOptions)) {
            $newStatus = $this->bulkDefaultStatus;
        }

        $query = $this->applyBranchScope(($this->bulkModel)::whereIn('id', $ids), null);
        $count = $query->update(['status' => $newStatus]);

        return redirect()->route($this->bulkRedirectRoute, array_filter($request->only($this->bulkRedirectParams)))
            ->with('success', "$count {$this->bulkLabel} berhasil diperbarui.");
    }
}
