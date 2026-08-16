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
use App\Services\SalesAgendaEvidenceImageService;
use App\Support\SalesAgendaEvidenceRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class SalesAgendaEvidenceController extends Controller
{
    public function store(StoreSalesAgendaEvidenceRequest $request, ContentItem $agenda, SalesAgendaEvidenceImageService $images, SalesAgendaEvidenceAuthorizationService $access)
    {
        abort_unless($access->canMutate($request->user(), $agenda), 403);

        return DB::transaction(function () use ($request, $agenda, $images, $access) {
            $agenda = ContentItem::query()->lockForUpdate()->findOrFail($agenda->id);
            abort_unless($access->canMutate($request->user(), $agenda), 403);
            if ($agenda->evidence()->count() >= SalesAgendaEvidenceRules::MAX_FILES) {
                throw ValidationException::withMessages(['photo' => 'Maksimal 2 foto.']);
            }
            $evidence = $agenda->evidence()->create($images->store($request->file('photo')) + ['uploaded_by_user_id' => $request->user()->id]);
            ActivityLog::create(['causer_id' => $request->user()->id, 'subject_type' => SalesAgendaEvidence::class, 'subject_id' => $evidence->id, 'event' => 'agenda_evidence_uploaded', 'description' => 'Bukti foto Agenda Sales diunggah.', 'properties' => ['agenda_id' => $agenda->id, 'evidence_id' => $evidence->id]]);

            return back()->with('success', 'Bukti foto berhasil ditambahkan.');
        });
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
        abort_unless($access->canMutate($request->user(), $agenda), 403);
        abort_unless($evidence->content_item_id === $agenda->id, 404);
        if ($evidence->storage_path && Storage::disk('agenda_evidence')->exists($evidence->storage_path) && ! Storage::disk('agenda_evidence')->delete($evidence->storage_path)) {
            abort(500, 'Bukti foto gagal dihapus.');
        }
        $evidence->delete();
        ActivityLog::create(['causer_id' => $request->user()->id, 'subject_type' => SalesAgendaEvidence::class, 'subject_id' => $evidence->id, 'event' => 'agenda_evidence_deleted_before_done', 'description' => 'Bukti foto Agenda Sales dihapus sebelum Agenda selesai.', 'properties' => ['agenda_id' => $agenda->id, 'evidence_id' => $evidence->id]]);

        return back()->with('success', 'Bukti foto dihapus.');
    }

    public function cleanup(CleanupSalesAgendaEvidenceRequest $request, ContentItem $agenda, SalesAgendaEvidenceCleanupService $cleanup)
    {
        $cleanup->cleanup($request, $agenda, $request->validated('reason'));

        return back()->with('success', 'Agenda Sales dan bukti lokal dibersihkan.');
    }
}
