<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\SalesAgendaEvidenceArchive;
use App\Services\SalesAgendaEvidenceArchiveService;
use App\Services\SalesAgendaEvidenceAuthorizationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SalesAgendaEvidenceArchiveController extends Controller
{
    public function index(Request $request)
    {
        $this->admin($request, app(SalesAgendaEvidenceAuthorizationService::class));
        $query = SalesAgendaEvidenceArchive::with(['evidence', 'branch'])->latest('week_start');
        if (! $this->superadmin($request)) {
            $query->where('branch_id', $request->user()->branch_id);
        }
        if ($request->filled('branch_id') && $this->superadmin($request)) {
            $query->where('branch_id', $request->integer('branch_id'));
        }

        $branches = $this->superadmin($request)
            ? Branch::orderBy('name')->get()
            : Branch::whereKey($request->user()->branch_id)->get();

        return view('crm.sales-pocketbook.evidence-archives', ['archives' => $query->paginate(20), 'branches' => $branches]);
    }

    public function build(Request $request, SalesAgendaEvidenceArchiveService $service)
    {
        $data = $request->validate(['branch_id' => ['required', 'exists:branches,id'], 'week' => ['required', 'date']]);
        $access = app(SalesAgendaEvidenceAuthorizationService::class);
        abort_unless($access->canBuildArchive($request->user(), (int) $data['branch_id']), 403);
        $archive = $service->build(Branch::findOrFail($data['branch_id']), $data['week'], $request->user()->id);

        return back()->with($archive->status === 'ready' ? 'success' : 'error', $archive->status === 'ready' ? 'Arsip berhasil diverifikasi.' : 'Arsip gagal dibuat.');
    }

    public function download(Request $request, SalesAgendaEvidenceArchive $archive)
    {
        $access = app(SalesAgendaEvidenceAuthorizationService::class);
        $this->admin($request, $access);
        abort_unless($access->canDownloadArchive($request->user(), $archive), 403);
        abort_unless($archive->status === 'ready' && $archive->storage_path, 404);

        $archive->update(['downloaded_at' => now()]);

        return Storage::disk('agenda_evidence_archives')->download($archive->storage_path);
    }

    public function purge(Request $request, SalesAgendaEvidenceArchiveService $service)
    {
        abort_unless($this->superadmin($request), 403);
        $data = $request->validate(['days' => ['required', 'integer', 'min:60']]);
        $count = $service->purge(now()->subDays($data['days']));
        ActivityLog::create(['causer_id' => $request->user()->id, 'subject_type' => SalesAgendaEvidenceArchive::class, 'subject_id' => 0, 'event' => 'sales_agenda_evidence_purged', 'description' => "Purge bukti agenda Sales: {$count} file.", 'properties' => ['count' => $count, 'days' => $data['days']]]);

        return back()->with('success', "{$count} bukti lokal dipurge; metadata tetap tersimpan.");
    }

    private function admin(Request $request, SalesAgendaEvidenceAuthorizationService $access): void
    {
        abort_unless($access->canManageArchives($request->user()), 403);
    }

    private function superadmin(Request $request): bool
    {
        return $request->user()->hasPrimaryRole('superadmin');
    }
}
