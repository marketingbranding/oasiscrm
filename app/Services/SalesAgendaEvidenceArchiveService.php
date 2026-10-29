<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\SalesAgendaEvidence;
use App\Models\SalesAgendaEvidenceArchive;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

class SalesAgendaEvidenceArchiveService
{
    public function build(Branch $branch, string $date, ?int $builderId = null): SalesAgendaEvidenceArchive
    {
        $week = CarbonImmutable::parse($date)->startOfWeek();
        $existing = SalesAgendaEvidenceArchive::where('branch_id', $branch->id)->whereDate('week_start', $week)->first();
        if ($existing?->evidence()->whereNotNull('purged_at')->exists()) {
            return $existing;
        }
        $items = $this->weekItems($branch->id, $week, $existing?->id);
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
                foreach ($manifest['files'] as $file) {
                    SalesAgendaEvidence::whereKey($file['evidence_id'])->update([
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
        $count = 0;
        SalesAgendaEvidenceArchive::where('status', 'ready')->whereNotNull('verified_at')->get()->each(function ($archive) use ($cutoff, &$count) {
            $items = SalesAgendaEvidence::query()->where('archive_id', $archive->id)->get();
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
            foreach ($items->where('created_at', '<=', $cutoff)->filter(fn ($item) => $item->purged_at === null) as $item) {
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
        return SalesAgendaEvidence::with('agenda')
            ->when($archiveId, fn ($query) => $query->where(fn ($nested) => $nested->whereNull('archive_id')->orWhere('archive_id', $archiveId)), fn ($query) => $query->whereNull('archive_id'))
            ->whereNull('purged_at')
            ->whereHas('agenda', fn ($q) => $q->where('branch_id', $branchId)->whereBetween('scheduled_date', [$week, $week->endOfWeek()]))
            ->orderBy('id')->get();
    }

    private function manifest(int $branchId, CarbonImmutable $week, $items): array
    {
        return ['archive_id' => null, 'branch_id' => $branchId, 'branch_name' => Branch::find($branchId)?->name, 'period_start' => $week->toDateString(), 'period_end' => $week->endOfWeek()->toDateString(), 'generated_at' => now()->toIso8601String(), 'file_count' => $items->count(), 'files' => $items->map(fn ($item) => ['evidence_id' => $item->id, 'agenda_id' => $item->content_item_id, 'sales_user_id' => $item->agenda?->owner_user_id, 'sales_name' => $item->agenda?->owner?->name, 'project_id' => $item->agenda?->sales_project_id, 'project_name' => $item->agenda?->project_name, 'agenda_date' => $item->agenda?->scheduled_date?->toDateString(), 'agenda_category' => $item->agenda?->sales_activity_category, 'original_name' => $item->original_name, 'name' => sprintf('evidence/%d-%s.webp', $item->id, $item->sha256), 'size' => $item->size_bytes, 'sha256' => $item->sha256])->values()->all()];
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
                $item = $items->firstWhere('id', $file['evidence_id']);
                $bytes = Storage::disk('agenda_evidence')->get($item->storage_path);
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
