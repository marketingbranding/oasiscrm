<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\CleanupSalesAgendaEvidenceRequest;
use App\Http\Requests\Crm\StoreSalesAgendaEvidenceRequest;
use App\Models\ActivityLog;
use App\Models\ContentItem;
use App\Models\SalesAgendaEvidence;
use App\Services\SalesAgendaEvidenceAuthorizationService;
use App\Services\SalesAgendaEvidenceCleanupService;
use App\Services\SalesAgendaEvidenceUploadService;
use App\Support\SalesAgendaEvidenceRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class SalesAgendaEvidenceController extends Controller
{
    public function store(StoreSalesAgendaEvidenceRequest $request, ContentItem $agenda, SalesAgendaEvidenceUploadService $uploads, SalesAgendaEvidenceAuthorizationService $access)
    {
        abort_unless($access->canMutate($request->user(), $agenda), 403);
        $prepared = $uploads->prepare([$request->file('photo')]);

        try {
            DB::transaction(function () use ($request, $agenda, $uploads, $access, $prepared) {
                $agenda = ContentItem::query()->lockForUpdate()->findOrFail($agenda->id);
                abort_unless($access->canMutate($request->user(), $agenda), 403);
                if ($agenda->evidence()->count() >= SalesAgendaEvidenceRules::MAX_FILES) {
                    throw ValidationException::withMessages(['photo' => 'Maksimal 2 foto.']);
                }
                $uploads->persist($agenda, $prepared, $request->user());
            });
        } catch (\Throwable $exception) {
            $uploads->cleanup($prepared);
            throw $exception;
        }

        return back()->with('success', 'Bukti foto berhasil ditambahkan.');
    }

    public function show(Request $request, ContentItem $agenda, SalesAgendaEvidence $evidence, SalesAgendaEvidenceAuthorizationService $access)
    {
        abort_unless($access->canView($request->user(), $agenda), 403);
        abort_unless($evidence->content_item_id === $agenda->id, 404);
        abort_if($evidence->purged_at || ! $evidence->storage_path, 410, 'Bukti foto telah dipindahkan ke arsip.');

        return Storage::disk('agenda_evidence')->response($evidence->storage_path, $evidence->original_name, ['Content-Type' => $evidence->mime_type, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function destroy(Request $request, ContentItem $agenda, SalesAgendaEvidence $evidence, SalesAgendaEvidenceAuthorizationService $access)
    {
        $storagePath = DB::transaction(function () use ($request, $agenda, $evidence, $access): string {
            $lockedAgenda = ContentItem::query()->lockForUpdate()->findOrFail($agenda->id);
            abort_unless($access->canMutate($request->user(), $lockedAgenda), 403);
            $lockedEvidence = SalesAgendaEvidence::query()->findOrFail($evidence->id);
            abort_unless($lockedEvidence->content_item_id === $lockedAgenda->id, 404);
            abort_if($lockedEvidence->purged_at || ! $lockedEvidence->storage_path || ! Storage::disk('agenda_evidence')->exists($lockedEvidence->storage_path), 410, 'Bukti foto telah dipindahkan ke arsip atau tidak tersedia.');
            $storagePath = $lockedEvidence->storage_path;
            $lockedEvidence->delete();
            ActivityLog::create(['causer_id' => $request->user()->id, 'subject_type' => SalesAgendaEvidence::class, 'subject_id' => $lockedEvidence->id, 'event' => 'agenda_evidence_deleted_before_done', 'description' => 'Bukti foto Agenda Sales dihapus sebelum Agenda selesai.', 'properties' => ['agenda_id' => $lockedAgenda->id, 'evidence_id' => $lockedEvidence->id]]);

            return $storagePath;
        });

        if (! Storage::disk('agenda_evidence')->delete($storagePath)) {
            abort(500, 'Bukti foto gagal dihapus.');
        }

        return back()->with('success', 'Bukti foto dihapus.');
    }

    public function cleanup(CleanupSalesAgendaEvidenceRequest $request, ContentItem $agenda, SalesAgendaEvidenceCleanupService $cleanup)
    {
        $cleanup->cleanup($request, $agenda, $request->validated('reason'));

        return back()->with('success', 'Agenda Sales dan bukti lokal dibersihkan.');
    }
}
