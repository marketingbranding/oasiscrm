<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\Kavling;
use App\Models\LeadMaster;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class KavlingController extends Controller
{
    public function index(LeadMaster $project)
    {
        if (!Auth::user()->isSuperadmin()) {
            abort(403);
        }

        $kavlings = $project->kavlings()->orderBy('kavling_code')->get();

        return view('crm.kavlings.index', compact('project', 'kavlings'));
    }

    public function bulkImport(LeadMaster $project)
    {
        if (!Auth::user()->isSuperadmin()) {
            abort(403);
        }

        return view('crm.kavlings.bulk-import', compact('project'));
    }

    public function bulkStore(Request $request, LeadMaster $project)
    {
        if (!Auth::user()->isSuperadmin()) {
            abort(403);
        }

        $request->validate([
            'list' => 'required|string',
        ]);

        $lines = explode("\n", $request->input('list'));
        $count = 0;
        $errors = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            $lastDash = strrpos($line, '-');
            if ($lastDash === false) {
                $errors[] = "Format salah: {$line}";
                continue;
            }

            $code = substr($line, $lastDash + 1);
            if (empty($code)) {
                $errors[] = "Kode kavling tidak ditemukan: {$line}";
                continue;
            }

            Kavling::create([
                'project_id' => $project->id,
                'kavling_code' => $code,
                'name' => $line,
            ]);

            $count++;
        }

        $message = "Berhasil mengimpor {$count} kavling.";
        if (!empty($errors)) {
            $message .= ' ' . count($errors) . ' baris dilewati karena format salah.';
        }

        return redirect()->route('kavlings.index', ['project' => $project->id])
            ->with('success', $message);
    }

    public function bulkDestroy(Request $request)
    {
        if (!Auth::user()->isSuperadmin()) {
            abort(403);
        }

        $ids = array_filter(explode(',', $request->input('selected_ids', '')));
        if (empty($ids)) {
            return back()->with('error', 'Tidak ada data yang dipilih.');
        }

        $count = Kavling::whereIn('id', $ids)->delete();

        return back()->with('success', "$count kavling berhasil dihapus.");
    }

    public function destroy(Kavling $kavling)
    {
        if (!Auth::user()->isSuperadmin()) {
            abort(403);
        }

        $projectId = $kavling->project_id;
        $kavling->delete();

        return redirect()->route('kavlings.index', ['project' => $projectId])
            ->with('success', 'Kavling berhasil dihapus.');
    }
}
