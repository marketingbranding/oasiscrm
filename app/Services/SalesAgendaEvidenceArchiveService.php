<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\SalesAgendaEvidence;
use App\Models\SalesAgendaEvidenceArchive;
use App\Models\SalesDailyPlanItemEvidence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

class SalesAgendaEvidenceArchiveService
{
    public function __construct(private readonly SyncLockService $locks) {}

    public function build(Branch $branch, string $date, ?int $builderId = null): SalesAgendaEvidenceArchive
    {
        $week = CarbonImmutable::parse($date)->startOfWeek();

        return $this->locks->runOrThrow('sales-agenda-evidence-archive', fn (): SalesAgendaEvidenceArchive => $this->buildLocked($branch, $week, $builderId), 900);
    }

    private function buildLocked(Branch $branch, CarbonImmutable $week, ?int $builderId = null): SalesAgendaEvidenceArchive
    {
        $existing = SalesAgendaEvidenceArchive::where('branch_id', $branch->id)->whereDate('week_start', $week)->first();
        $items = $this->retainedArchiveItems($existing)
            ->concat($this->weekItems($branch->id, $week, $existing?->id))
            ->sortBy(fn (array $item): int => $item['record']->id)
            ->values();
        $finalPath = 'sales-agenda-archives/'.Str::uuid().'.zip';
        $temporary = tempnam(sys_get_temp_dir(), 'oasis-agenda-');
        try {
            $manifest = $this->createZip($temporary, $branch->id, $week, $items);
            $this->verifyFile($temporary, $manifest);
            $bytes = file_get_contents($temporary);
            if (! is_string($bytes) || ! Storage::disk('agenda_evidence_archives')->put($finalPath, $bytes)) {
                throw new RuntimeException('Arsip gagal disimpan.');
            }
            $stored = Storage::disk('agenda_evidence_archives')->get($finalPath);
            if (hash('sha256', $stored) !== hash('sha256', $bytes)) {
                throw new RuntimeException('SHA arsip tersimpan tidak cocok.');
            }
            $oldPath = $existing?->storage_path;
            $archive = DB::transaction(function () use ($existing, $branch, $week, $builderId, $finalPath, $stored, $manifest) {
                $archive = $existing ?: new SalesAgendaEvidenceArchive(['branch_id' => $branch->id, 'week_start' => $week]);
                $archive->fill(['storage_path' => $finalPath, 'archive_name' => sprintf('agenda-evidence-%s-%s.zip', Str::slug($branch->code ?: $branch->name), $week->format('o-\\WW')), 'period_start' => $week->toDateString(), 'period_end' => $week->endOfWeek()->toDateString(), 'sha256' => hash('sha256', $stored), 'size_bytes' => strlen($stored), 'file_count' => count($manifest['files']), 'manifest_checksum' => hash('sha256', json_encode($manifest, JSON_THROW_ON_ERROR)), 'manifest' => $manifest,
                    'status' => 'ready', 'error' => null, 'verified_at' => now(), 'created_by' => $builderId, 'built_by_user_id' => $builderId])->save();
                $this->detachManifestOrphans($existing, $manifest);
                foreach ($manifest['files'] as $file) {
                    $this->recordForManifestFile($file)->update([
                        'archive_id' => $archive->id,
                        'archive_status' => 'archived',
                        'archive_entry_path' => $file['name'],
                        'archived_at' => now(),
                    ]);
                }

                return $archive;
            });
            if ($oldPath && $oldPath !== $finalPath) {
                Storage::disk('agenda_evidence_archives')->delete($oldPath);
            }

            return $archive->fresh();
        } catch (\Throwable $e) {
            Storage::disk('agenda_evidence_archives')->delete($finalPath);
            if ($existing?->status === 'ready') {
                $existing->update(['error' => mb_substr('Rebuild gagal: '.$e->getMessage(), 0, 2000)]);

                return $existing->fresh();
            }

            return SalesAgendaEvidenceArchive::updateOrCreate(['branch_id' => $branch->id, 'week_start' => $week], ['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 2000), 'built_by_user_id' => $builderId]);
        } finally {
            @unlink($temporary);
        }
    }

    public function purge($cutoff): int
    {
        return $this->locks->runOrThrow('sales-agenda-evidence-archive', fn (): int => $this->purgeLocked($cutoff), 900);
    }

    private function purgeLocked($cutoff): int
    {
        $count = 0;
        SalesAgendaEvidenceArchive::where('status', 'ready')->whereNotNull('verified_at')->get()->each(function ($archive) use ($cutoff, &$count) {
            $manifestKeys = collect($archive->manifest['files'] ?? [])->mapWithKeys(fn (array $file): array => [$this->manifestKey($file) => true]);
            $items = $this->archiveItems($archive->id)->filter(fn (array $entry): bool => $manifestKeys->has($this->entryKey($entry)));
            try {
                $bytes = Storage::disk('agenda_evidence_archives')->get($archive->storage_path);
                if (hash('sha256', $bytes) !== $archive->sha256) {
                    throw new RuntimeException('SHA arsip berubah.');
                }
                $temp = tempnam(sys_get_temp_dir(), 'oasis-verify-');
                file_put_contents($temp, $bytes);
                $this->verifyFile($temp, $archive->manifest);
                @unlink($temp);
                $archive->update(['verified_at' => now(), 'error' => null]);
            } catch (\Throwable $e) {
                if (isset($temp)) {
                    @unlink($temp);
                }
                $archive->update(['error' => mb_substr('Verifikasi purge gagal: '.$e->getMessage(), 0, 2000)]);

                return;
            }
            foreach ($items as $entry) {
                $item = $entry['record'];
                if ($item->created_at->gt($cutoff) || $item->purged_at !== null) {
                    continue;
                }
                if (! $item->storage_path || ! Storage::disk('agenda_evidence')->exists($item->storage_path)) {
                    continue;
                }
                $localBytes = Storage::disk('agenda_evidence')->get($item->storage_path);
                if (hash('sha256', $localBytes) !== ($item->checksum ?: $item->sha256)) {
                    continue;
                }
                if (! Storage::disk('agenda_evidence')->delete($item->storage_path)) {
                    continue;
                }
                $item->update(['storage_path' => null, 'file_path' => null, 'archive_status' => 'purged', 'purged_at' => now()]);
                $count++;
            }
        });

        return $count;
    }

    private function weekItems(int $branchId, CarbonImmutable $week, ?int $archiveId = null)
    {
        $agenda = SalesAgendaEvidence::with('agenda.owner')
            ->when($archiveId, fn ($query) => $query->where(fn ($nested) => $nested->whereNull('archive_id')->orWhere('archive_id', $archiveId)), fn ($query) => $query->whereNull('archive_id'))
            ->whereNull('purged_at')
            ->whereHas('agenda', fn ($q) => $q->where('branch_id', $branchId)->whereBetween('scheduled_date', [$week, $week->endOfWeek()]))
            ->orderBy('id')->get()
            ->map(fn (SalesAgendaEvidence $item): array => ['source' => 'agenda', 'record' => $item]);
        $dailyPlan = SalesDailyPlanItemEvidence::with('item.plan.sales')
            ->when($archiveId, fn ($query) => $query->where(fn ($nested) => $nested->whereNull('archive_id')->orWhere('archive_id', $archiveId)), fn ($query) => $query->whereNull('archive_id'))
            ->whereNull('purged_at')
            ->whereHas('item.plan', fn ($q) => $q->where('branch_id', $branchId)->whereBetween('work_date', [$week->toDateString(), $week->endOfWeek()->toDateString()]))
            ->orderBy('id')->get()
            ->map(fn (SalesDailyPlanItemEvidence $item): array => ['source' => 'daily_plan', 'record' => $item]);

        return $agenda->concat($dailyPlan)->sortBy(fn (array $item): int => $item['record']->id)->values();
    }

    private function manifest(int $branchId, CarbonImmutable $week, $items): array
    {
        return ['archive_id' => null, 'branch_id' => $branchId, 'branch_name' => Branch::find($branchId)?->name, 'period_start' => $week->toDateString(), 'period_end' => $week->endOfWeek()->toDateString(), 'generated_at' => now()->toIso8601String(), 'file_count' => $items->count(), 'files' => $items->map(function (array $entry): array {
            if (isset($entry['archive_file'])) {
                $file = $entry['archive_file'];
                $file['source'] = $entry['source'];

                return $file;
            }

            $item = $entry['record'];
            if ($entry['source'] === 'agenda') {
                $agenda = $item->agenda;

                return ['source' => 'agenda', 'evidence_id' => $item->id, 'agenda_id' => $item->content_item_id, 'daily_plan_item_id' => null, 'sales_user_id' => $agenda?->owner_user_id, 'sales_name' => $agenda?->owner?->name, 'project_id' => $agenda?->sales_project_id, 'project_name' => $agenda?->project_name, 'agenda_date' => $agenda?->scheduled_date?->toDateString(), 'agenda_category' => $agenda?->sales_activity_category, 'original_name' => $item->original_name, 'name' => sprintf('evidence/agenda-%d-%s.webp', $item->id, $item->sha256), 'size' => $item->size_bytes, 'sha256' => $item->sha256];
            }

            $planItem = $item->item;
            $plan = $planItem?->plan;

            return ['source' => 'daily_plan', 'evidence_id' => $item->id, 'agenda_id' => null, 'daily_plan_item_id' => $planItem?->id, 'branch_id' => $plan?->branch_id, 'sales_user_id' => $plan?->sales_user_id, 'sales_name' => $plan?->sales?->name, 'project_id' => null, 'project_name' => null, 'agenda_date' => $plan?->work_date?->toDateString(), 'agenda_category' => $planItem?->sales_activity_category, 'activity' => $planItem?->activity, 'original_name' => $item->original_name, 'name' => sprintf('evidence/daily-plan-%d-%s.webp', $item->id, $item->sha256), 'size' => $item->size_bytes, 'sha256' => $item->sha256];
        })->values()->all()];
    }

    private function createZip(string $path, int $branchId, CarbonImmutable $week, $items): array
    {
        $manifest = $this->manifest($branchId, $week, $items);
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('ZIP tidak dapat dibuat.');
        }
        try {
            foreach ($manifest['files'] as $file) {
                $entry = $items->first(fn (array $candidate): bool => $candidate['source'] === $file['source'] && $candidate['record']->id === $file['evidence_id']);
                $bytes = isset($entry['archive_source_path'])
                    ? $this->readArchivedFile($entry)
                    : Storage::disk('agenda_evidence')->get($entry['record']->storage_path);
                if (strlen($bytes) !== $file['size'] || hash('sha256', $bytes) !== $file['sha256'] || ! $zip->addFromString($file['name'], $bytes)) {
                    throw new RuntimeException('File bukti tidak valid.');
                }
            }
            $json = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if (! $zip->addFromString('manifest.json', $json)) {
                throw new RuntimeException('Manifest gagal ditulis.');
            }
        } finally {
            if (! $zip->close()) {
                throw new RuntimeException('ZIP gagal ditutup.');
            }
        }

        return $manifest;
    }

    private function archiveItems(int $archiveId)
    {
        return SalesAgendaEvidence::query()->where('archive_id', $archiveId)->get()->map(fn (SalesAgendaEvidence $item): array => ['source' => 'agenda', 'record' => $item])
            ->concat(SalesDailyPlanItemEvidence::query()->where('archive_id', $archiveId)->get()->map(fn (SalesDailyPlanItemEvidence $item): array => ['source' => 'daily_plan', 'record' => $item]));
    }

    private function retainedArchiveItems(?SalesAgendaEvidenceArchive $archive)
    {
        if (! $archive || ! $archive->storage_path) {
            return collect();
        }

        $files = collect($archive->manifest['files'] ?? []);

        return $this->archiveItems($archive->id)
            ->filter(fn (array $entry): bool => $entry['record']->purged_at !== null)
            ->map(function (array $entry) use ($archive, $files): ?array {
                $file = $files->first(fn (array $candidate): bool => $this->manifestKey($candidate) === $this->entryKey($entry));
                if (! $file) {
                    throw new RuntimeException('Bukti yang sudah dipurge tidak ditemukan dalam manifest arsip.');
                }

                return $entry + [
                    'archive_file' => $file,
                    'archive_source_path' => $archive->storage_path,
                    'archive_entry_name' => $file['name'],
                ];
            })
            ->filter()
            ->values();
    }

    private function detachManifestOrphans(?SalesAgendaEvidenceArchive $archive, array $manifest): void
    {
        if (! $archive) {
            return;
        }

        $manifestKeys = collect($manifest['files'])->mapWithKeys(fn (array $file): array => [$this->manifestKey($file) => true]);
        $this->archiveItems($archive->id)->each(function (array $entry) use ($manifestKeys): void {
            $record = $entry['record'];
            if ($record->purged_at === null && ! $manifestKeys->has($this->entryKey($entry))) {
                $record->update([
                    'archive_id' => null,
                    'archive_entry_path' => null,
                    'archive_status' => 'local_only',
                    'archived_at' => null,
                ]);
            }
        });
    }

    private function manifestKey(array $file): string
    {
        return ($file['source'] ?? 'agenda').':'.$file['evidence_id'];
    }

    private function entryKey(array $entry): string
    {
        return $entry['source'].':'.$entry['record']->id;
    }

    private function readArchivedFile(array $entry): string
    {
        $source = tempnam(sys_get_temp_dir(), 'oasis-archive-source-');
        if (! is_string($source)) {
            throw new RuntimeException('Arsip sumber tidak dapat disiapkan.');
        }

        file_put_contents($source, Storage::disk('agenda_evidence_archives')->get($entry['archive_source_path']));
        $zip = new ZipArchive;
        try {
            if ($zip->open($source, ZipArchive::RDONLY) !== true) {
                throw new RuntimeException('Arsip sumber tidak dapat dibuka.');
            }
            $bytes = $zip->getFromName($entry['archive_entry_name']);
            if (! is_string($bytes)) {
                throw new RuntimeException('Bukti arsip sumber tidak ditemukan.');
            }

            return $bytes;
        } finally {
            $zip->close();
            @unlink($source);
        }
    }

    private function recordForManifestFile(array $file): SalesAgendaEvidence|SalesDailyPlanItemEvidence
    {
        return $file['source'] === 'daily_plan'
            ? SalesDailyPlanItemEvidence::findOrFail($file['evidence_id'])
            : SalesAgendaEvidence::findOrFail($file['evidence_id']);
    }

    private function verifyFile(string $path, array $manifest): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('ZIP tidak dapat diverifikasi.');
        }
        try {
            $json = $zip->getFromName('manifest.json');
            if (! is_string($json) || json_decode($json, true, 512, JSON_THROW_ON_ERROR) !== $manifest || $zip->numFiles !== count($manifest['files']) + 1) {
                throw new RuntimeException('Manifest ZIP tidak cocok.');
            }
            foreach ($manifest['files'] as $file) {
                $bytes = $zip->getFromName($file['name']);
                if (! is_string($bytes) || strlen($bytes) !== $file['size'] || hash('sha256', $bytes) !== $file['sha256']) {
                    throw new RuntimeException('Isi ZIP tidak cocok.');
                }
            }
        } finally {
            $zip->close();
        }
    }
}
