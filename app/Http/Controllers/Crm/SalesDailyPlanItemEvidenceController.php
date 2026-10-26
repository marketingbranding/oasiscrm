<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\StoreSalesDailyPlanItemEvidenceRequest;
use App\Models\SalesDailyPlanItem;
use App\Models\SalesDailyPlanItemEvidence;
use App\Services\SalesAgendaEvidenceUploadService;
use App\Services\SalesDailyPlanService;
use App\Support\SalesAgendaEvidenceRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class SalesDailyPlanItemEvidenceController extends Controller
{
    public function store(StoreSalesDailyPlanItemEvidenceRequest $request, SalesDailyPlanItem $item, SalesAgendaEvidenceUploadService $uploads, SalesDailyPlanService $plans): RedirectResponse
    {
        $prepared = $uploads->prepare([$request->file('photo')]);

        try {
            DB::transaction(function () use ($request, $item, $uploads, $plans, $prepared): void {
                $item = SalesDailyPlanItem::query()->with('plan')->lockForUpdate()->findOrFail($item->id);
                $plans->assertItemWritable($request->user(), $item);
                if ($item->evidence()->count() >= SalesAgendaEvidenceRules::MAX_FILES) {
                    throw ValidationException::withMessages(['photo' => 'Maksimal 2 foto.']);
                }
                $uploads->persistDailyPlanItem($item, $prepared, $request->user());
            });
        } catch (\Throwable $exception) {
            $uploads->cleanup($prepared);
            throw $exception;
        }

        return back()->with('success', 'Bukti foto aktivitas berhasil ditambahkan.');
    }

    public function show(Request $request, SalesDailyPlanItem $item, SalesDailyPlanItemEvidence $evidence, SalesDailyPlanService $plans)
    {
        $plans->assertItemOwner($request->user(), $item);
        abort_unless($evidence->sales_daily_plan_item_id === $item->id, 404);
        abort_if($evidence->purged_at || ! $evidence->storage_path, 410, 'Bukti foto telah dipindahkan ke arsip.');

        return Storage::disk('agenda_evidence')->response($evidence->storage_path, $evidence->original_name, [
            'Content-Type' => $evidence->mime_type,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
