<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\ContentItem;
use App\Models\SalesAgendaEvidence;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class SalesAgendaEvidenceUploadService
{
    public function __construct(private readonly SalesAgendaEvidenceImageService $images) {}

    public function prepare(array $files, string $errorPrefix = 'photo'): array
    {
        $prepared = [];

        try {
            foreach (array_values($files) as $index => $file) {
                try {
                    $prepared[] = $this->images->store($file);
                } catch (ValidationException $exception) {
                    $key = $errorPrefix === 'photo' ? 'photo' : $errorPrefix.'.'.$index;
                    throw ValidationException::withMessages([$key => collect($exception->errors())->flatten()->all()]);
                }
            }
        } catch (\Throwable $exception) {
            $this->cleanup($prepared);
            throw $exception;
        }

        return $prepared;
    }

    public function persist(ContentItem $agenda, array $prepared, User $actor): array
    {
        $evidence = [];

        try {
            foreach ($prepared as $metadata) {
                $item = $agenda->evidence()->create($metadata + ['uploaded_by_user_id' => $actor->id]);
                ActivityLog::create([
                    'causer_id' => $actor->id,
                    'subject_type' => SalesAgendaEvidence::class,
                    'subject_id' => $item->id,
                    'event' => 'agenda_evidence_uploaded',
                    'description' => 'Bukti foto Agenda Sales diunggah.',
                    'properties' => ['agenda_id' => $agenda->id, 'evidence_id' => $item->id],
                ]);
                $evidence[] = $item;
            }
        } catch (\Throwable $exception) {
            $this->cleanup($prepared);
            throw $exception;
        }

        return $evidence;
    }

    public function cleanup(array $prepared): void
    {
        $paths = array_filter(array_column($prepared, 'storage_path'));
        if ($paths !== [] && ! Storage::disk('agenda_evidence')->delete($paths)) {
            Log::error('Pembersihan file bukti Agenda Sales gagal.', ['paths' => $paths]);
        }
    }
}
