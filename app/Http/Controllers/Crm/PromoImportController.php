<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Promo;
use App\Models\PromoImportBatch;
use App\Services\PromoAccessService;
use App\Services\PromoTsvParser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PromoImportController extends Controller
{
    public function __construct(private PromoAccessService $access, private PromoTsvParser $parser) {}

    public function create(Request $request): View
    {
        $this->authorize('create', Promo::class);

        $branches = $this->access->allowedBranches($request->user());

        return view('crm.promos.import', ['branches' => $branches, 'branchLocked' => ! $request->user()->isSuperadmin(), 'selectedBranchId' => $request->user()->isSuperadmin() ? null : $branches->first()?->id]);
    }

    public function preview(Request $request): RedirectResponse
    {
        $this->authorize('create', Promo::class);
        $data = $request->validate(['branch_id' => ['required', 'integer'], 'tsv' => ['required', 'string', 'max:262144']]);
        abort_unless($this->access->canManageBranch($request->user(), (int) $data['branch_id']), 403);
        $existing = Promo::query()->where('branch_id', $data['branch_id'])->get(['id', 'code'])
            ->mapWithKeys(fn (Promo $promo) => [mb_strtoupper($promo->code) => $promo->id])->all();
        try {
            $rows = $this->parser->parse($data['tsv'], $existing);
        } catch (\InvalidArgumentException $exception) {
            return back()->withInput()->withErrors(['tsv' => $exception->getMessage()]);
        }
        if ($rows === []) {
            return back()->withInput()->withErrors(['tsv' => 'Data TSV tidak memiliki baris promo.']);
        }
        $valid = collect($rows)->whereIn('status', ['Baru', 'Update', 'Perlu Diperiksa'])->count();
        $batch = DB::transaction(function () use ($request, $data, $rows, $valid) {
            $batch = PromoImportBatch::create(['public_id' => (string) Str::uuid(), 'uploaded_by' => $request->user()->id, 'branch_id' => $data['branch_id'], 'status' => 'preview_ready', 'expires_at' => now()->addHour(), 'total_rows' => count($rows), 'valid_rows' => $valid, 'invalid_rows' => count($rows) - $valid]);
            $batch->rows()->createMany($rows);

            return $batch;
        });

        return redirect()->route('promos.import.show', $batch);
    }

    public function show(PromoImportBatch $promo_import_batch): View
    {
        $this->authorize('view', $promo_import_batch);

        return view('crm.promos.import-preview', ['batch' => $promo_import_batch->load(['branch', 'rows'])]);
    }

    public function confirm(Request $request, PromoImportBatch $promo_import_batch): RedirectResponse
    {
        $this->authorize('confirm', $promo_import_batch);
        $data = $request->validate(['expected_updated_at' => ['required', 'date']]);
        $result = DB::transaction(function () use ($request, $promo_import_batch, $data) {
            $batch = PromoImportBatch::query()->whereKey($promo_import_batch->id)->lockForUpdate()->firstOrFail();
            abort_unless(($batch->uploaded_by === $request->user()->id || $request->user()->isSuperadmin()) && $this->access->canManageBranch($request->user(), $batch->branch_id), 403);
            abort_if($batch->status !== 'preview_ready' || $batch->expires_at->isPast(), 409, 'Preview impor tidak lagi dapat dikonfirmasi.');
            abort_if(! $batch->updated_at->equalTo($data['expected_updated_at']), 409, 'Preview impor telah berubah.');
            $storedRows = $batch->rows()->lockForUpdate()->orderBy('line_number')->get();
            $existing = Promo::query()->where('branch_id', $batch->branch_id)->lockForUpdate()->get()->keyBy(fn (Promo $promo) => mb_strtoupper($promo->code));
            $tsv = $storedRows->map(fn ($row) => $this->tsvLine(array_values($row->raw_data)))->implode("\n");
            $rows = $this->parser->parse($tsv, $existing->map->id->all());
            $created = $updated = $skipped = 0;
            foreach ($rows as $row) {
                if (! in_array($row['status'], ['Baru', 'Update', 'Perlu Diperiksa'], true)) {
                    $skipped++;

                    continue;
                }
                $values = $row['normalized_data'];
                $promo = $existing->get($values['code']);
                if ($promo) {
                    $promo->update($values + ['updated_by' => $request->user()->id]);
                    $updated++;
                } else {
                    $promo = Promo::create($values + ['branch_id' => $batch->branch_id, 'is_active' => true, 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);
                    $existing->put($values['code'], $promo);
                    $created++;
                }
            }
            $batch->update(['status' => 'completed', 'confirmed_at' => now(), 'created_rows' => $created, 'updated_rows' => $updated, 'skipped_rows' => $skipped]);
            ActivityLog::create(['causer_id' => $request->user()->id, 'subject_type' => PromoImportBatch::class, 'subject_id' => $batch->id, 'event' => 'promo_imported', 'description' => 'Impor promo selesai.', 'properties' => ['branch_id' => $batch->branch_id, 'total_rows' => $batch->total_rows, 'created_count' => $created, 'updated_count' => $updated, 'invalid_count' => $skipped, 'actor_id' => $request->user()->id]]);

            return compact('created', 'updated', 'skipped', 'batch');
        });

        return redirect()->route('promos.index', ['branch_id' => $result['batch']->branch_id])->with('success', "Impor selesai: {$result['created']} baru, {$result['updated']} diperbarui, {$result['skipped']} dilewati.");
    }

    private function tsvLine(array $values): string
    {
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, $values, "\t", '"', '\\');
        rewind($stream);
        $line = rtrim((string) stream_get_contents($stream), "\r\n");
        fclose($stream);

        return $line;
    }
}
