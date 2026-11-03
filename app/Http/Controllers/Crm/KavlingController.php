<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\Kavling;
use App\Models\LeadMaster;
use App\Models\User;
use App\Services\SystemAdministrationAuthorizationService;
use Illuminate\Http\Request;

class KavlingController extends Controller
{
    public function __construct(private readonly SystemAdministrationAuthorizationService $systemAdministration) {}

    public function index(LeadMaster $project)
    {
        $this->authorizeSystemAdministration();

        $search = request()->get('search');
        $kavlingsQuery = $project->kavlings();

        if ($search) {
            $kavlingsQuery->where(function ($q) use ($search) {
                $q->where('kavling_code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            });
        }

        $perPage = request()->get('per_page', '15');
        if ($perPage === 'all') {
            $kavlings = $kavlingsQuery->orderBy('kavling_code')->get();
        } else {
            $kavlings = $kavlingsQuery->orderBy('kavling_code')->paginate((int) $perPage)->withQueryString();
        }

        return view('crm.kavlings.index', compact('project', 'kavlings', 'perPage'));
    }

    public function bulkImport(LeadMaster $project)
    {
        $this->authorizeSystemAdministration();

        return view('crm.kavlings.bulk-import', compact('project'));
    }

    public function bulkStore(Request $request, LeadMaster $project)
    {
        $this->authorizeSystemAdministration();

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
        if (! empty($errors)) {
            $message .= ' '.count($errors).' baris dilewati karena format salah.';
        }

        return redirect()->route('kavlings.index', ['project' => $project->id])
            ->with('success', $message);
    }

    public function bulkDestroy(Request $request)
    {
        $this->authorizeSystemAdministration();

        $ids = array_filter(explode(',', $request->input('selected_ids', '')));
        if (empty($ids)) {
            return back()->with('error', 'Tidak ada data yang dipilih.');
        }

        $count = Kavling::whereIn('id', $ids)->delete();

        return back()->with('success', "$count kavling berhasil dihapus.");
    }

    public function destroy(Kavling $kavling)
    {
        $this->authorizeSystemAdministration();

        $projectId = $kavling->project_id;
        $kavling->delete();

        return redirect()->route('kavlings.index', ['project' => $projectId])
            ->with('success', 'Kavling berhasil dihapus.');
    }

    private function authorizeSystemAdministration(): void
    {
        $actor = request()->user();
        abort_unless($actor instanceof User, 403);
        $this->systemAdministration->assertCanManage($actor, SystemAdministrationAuthorizationService::KAVLINGS);
    }
}
