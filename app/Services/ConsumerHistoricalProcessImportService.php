<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\ConsumerApplication;
use App\Models\ConsumerBankProcess;
use App\Models\ConsumerImportBatch;
use App\Models\ConsumerLegacyIdentity;
use App\Models\ConsumerStageEvent;
use App\Models\LeadMaster;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class ConsumerHistoricalProcessImportService
{
    public const SOURCE = 'historical_process_paste';

    public const PROCESS_TYPES = [
        'bi_checking' => 'BI Checking',
        'psjb' => 'PSJB',
        'pemberkasan' => 'Pemberkasan',
        'proses_bank' => 'Proses Bank',
        'ppjb_developer' => 'PPJB Developer',
        'akad' => 'Akad',
        'bast' => 'BAST',
    ];

    private const STAGE_KEYS = [
        'bi_checking' => 'bi_checking',
        'psjb' => 'PSJB',
        'pemberkasan' => 'pemberkasan',
        'ppjb_developer' => 'ppjb_dev',
        'akad' => 'akad',
        'bast' => 'bast',
    ];

    private const HEADER_ALIASES = [
        'id kavling' => 'kavling', 'id_kavling' => 'kavling', 'kavling' => 'kavling', 'kav' => 'kavling',
        'nama konsumen' => 'name', 'nama_konsumen' => 'name', 'nama' => 'name',
        'no hp' => 'phone', 'no_hp' => 'phone', 'nomor hp' => 'phone', 'phone' => 'phone',
        'external id' => 'external_id', 'external_id' => 'external_id', 'id lead' => 'external_id', 'id_lead' => 'external_id',
        'external sync id' => 'external_id', 'external_sync_id' => 'external_id', 'oasis sync id' => 'external_id', 'oasis_sync_id' => 'external_id',
        'tanggal' => 'date', 'tanggal proses' => 'date', 'tanggal proses bank' => 'date',
        'tanggal bi checking' => 'date', 'tgl bi checking' => 'date', 'tanggal slik' => 'date', 'tgl slik' => 'date',
        'tanggal psjb' => 'date', 'tgl psjb' => 'date',
        'tanggal pemberkasan' => 'date', 'tgl pemberkasan' => 'date',
        'tanggal proses bank' => 'date', 'tgl proses bank' => 'date',
        'tanggal ppjb dev' => 'date', 'tgl ppjb dev' => 'date', 'tanggal ppjb' => 'date',
        'tanggal akad' => 'date', 'tgl akad' => 'date',
        'tanggal bast' => 'date', 'tgl bast' => 'date',
        'status' => 'status', 'hasil' => 'status', 'hasil proses' => 'status', 'hasil proses bank' => 'status',
        'hasil bi checking' => 'status', 'hasil slik' => 'status',
        'status bank' => 'bank_status', 'bank status' => 'bank_status', 'status proses bank' => 'bank_status',
        'bank' => 'bank_name', 'nama bank' => 'bank_name', 'bank_name' => 'bank_name',
        'keterangan' => 'notes', 'catatan' => 'notes', 'note' => 'notes', 'notes' => 'notes',
    ];

    private const PROCESS_HEADER_MAP = [
        'bi_checking' => ['kavling', 'date', 'status', 'notes'],
        'psjb' => ['kavling', 'date', 'status', 'notes'],
        'pemberkasan' => ['kavling', 'date', 'status', 'notes'],
        'proses_bank' => ['kavling', 'bank_name', 'bank_status', 'date', 'notes'],
        'ppjb_developer' => ['kavling', 'date', 'status', 'notes'],
        'akad' => ['kavling', 'date', 'status', 'notes'],
        'bast' => ['kavling', 'date', 'status', 'notes'],
    ];

    public function preview(string $input, Branch $branch, LeadMaster $project, string $processType): array
    {
        $this->validateProcessType($processType);
        $rows = $this->parse($input, $processType);
        $existing = $this->existingFingerprints($branch, $project, $processType);

        return array_map(function (array $row) use ($branch, $project, $existing, $processType): array {
            $row['normalized_data']['branch_id'] = $branch->id;
            $row['normalized_data']['project_id'] = $project->id;
            $kavlingKey = strtolower(trim((string) $row['normalized_data']['kavling']));

            if ($kavlingKey === '') {
                $row['errors'][] = 'ID Kavling wajib diisi.';
                $row['status'] = 'INVALID';

                return $row;
            }

            $resolved = $this->resolveApplication($row['normalized_data'], $branch, $project);

            if ($resolved['status'] === 'UNRESOLVED_APPLICATION') {
                $row['status'] = 'UNRESOLVED_APPLICATION';
                $row['errors'][] = 'Tidak ada identitas provenance konsumen lokal yang cocok.';

                return $row;
            }

            if ($resolved['status'] === 'AMBIGUOUS_APPLICATION') {
                $row['status'] = 'AMBIGUOUS_APPLICATION';
                $row['errors'][] = 'Identitas provenance terhubung ke beberapa aplikasi lokal.';

                return $row;
            }

            if ($resolved['status'] === 'IDENTITY_CONFLICT') {
                $row['status'] = 'IDENTITY_CONFLICT';
                $row['errors'][] = 'Kavling pada baris bertentangan dengan kavling aplikasi hasil identitas.';

                return $row;
            }

            $row['normalized_data']['consumer_application_id'] = $resolved['application_id'];
            $row['normalized_data']['identity_key'] = $resolved['identity_key'];
            $fingerprint = $this->fingerprint($resolved['application_id'], $processType, $row['normalized_data']);
            $row['normalized_data']['fingerprint'] = $fingerprint;
            $row['status'] = isset($existing[$fingerprint]) ? 'ALREADY_IMPORTED' : ($row['errors'] !== [] ? 'INVALID' : 'READY');

            return $row;
        }, $rows);
    }

    public function createBatch(User $actor, Branch $branch, LeadMaster $project, string $input, string $processType): ConsumerImportBatch
    {
        $this->validateProcessType($processType);
        $rows = $this->preview($input, $branch, $project, $processType);
        $counts = $this->counts($rows);

        return DB::transaction(function () use ($actor, $branch, $project, $rows, $counts, $processType): ConsumerImportBatch {
            $batch = ConsumerImportBatch::create([
                'public_id' => (string) Str::uuid(),
                'uploaded_by' => $actor->id,
                'branch_id' => $branch->id,
                'project_id' => $project->id,
                'source' => self::SOURCE,
                'status' => 'preview_ready',
                'expires_at' => now()->addHour(),
                'total_rows' => count($rows),
                'parsed_rows' => count($rows),
                'ready_rows' => $counts['READY'],
                'already_imported_rows' => $counts['ALREADY_IMPORTED'],
                'warning_rows' => $counts['WARNING'],
                'review_rows' => $counts['NEEDS_REVIEW'] + $counts['UNRESOLVED_APPLICATION'] + $counts['AMBIGUOUS_APPLICATION'] + $counts['IDENTITY_CONFLICT'],
                'invalid_rows' => $counts['INVALID'],
            ]);
            foreach ($rows as $row) {
                $normalized = $row['normalized_data'];
                $normalized['process_type'] = $processType;
                $batch->rows()->create([
                    'line_number' => $row['line_number'],
                    'normalized_data' => $normalized,
                    'status' => $row['status'],
                    'warnings' => $row['warnings'],
                    'errors' => $row['errors'],
                ]);
            }

            return $batch;
        });
    }

    public function import(ConsumerImportBatch $batch, User $actor): array
    {
        return DB::transaction(function () use ($batch, $actor): array {
            $locked = ConsumerImportBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'preview_ready' && ! $locked->expires_at->isPast(), 409, 'Preview impor tidak lagi dapat dikonfirmasi.');
            abort_unless($locked->uploaded_by === $actor->id, 403);
            Branch::query()->whereKey($locked->branch_id)->lockForUpdate()->firstOrFail();
            $rows = $locked->rows()->lockForUpdate()->orderBy('line_number')->get();
            $createdEvents = $createdBankProcesses = $skipped = $review = $invalid = 0;

            foreach ($rows as $row) {
                if ($row->status === 'ALREADY_IMPORTED') {
                    $skipped++;

                    continue;
                }
                if (in_array($row->status, ['INVALID', 'NEEDS_REVIEW', 'UNRESOLVED_APPLICATION', 'AMBIGUOUS_APPLICATION', 'IDENTITY_CONFLICT'], true)) {
                    $originalStatus = $row->status;
                    $row->update(['status' => 'SKIPPED', 'skip_reason' => $originalStatus]);
                    $originalStatus === 'INVALID' ? $invalid++ : $review++;

                    continue;
                }
                try {
                    $result = DB::transaction(fn () => $this->importRow($row->normalized_data, $locked, $actor));
                    $createdEvents += $result['stage_events_created'] ?? 0;
                    $createdBankProcesses += $result['bank_processes_created'] ?? 0;
                    $row->update(['status' => 'IMPORTED']);
                } catch (Throwable $exception) {
                    $row->update(['status' => 'INVALID', 'errors' => array_merge($row->errors ?? [], ['Import gagal: '.$exception->getMessage()])]);
                    $invalid++;
                }
            }
            $locked->update([
                'status' => 'completed', 'confirmed_at' => now(),
                'created_customers' => 0, 'created_applications' => 0,
                'reused_rows' => $createdEvents + $createdBankProcesses,
                'skipped_rows' => $skipped, 'warning_rows' => 0,
                'review_rows' => $review, 'invalid_rows' => $invalid,
            ]);
            ActivityLog::create([
                'causer_id' => $actor->id,
                'subject_type' => ConsumerImportBatch::class,
                'subject_id' => $locked->id,
                'event' => 'consumer_historical_process_import',
                'description' => 'Impor proses historis konsumen selesai.',
                'properties' => [
                    'actor_id' => $actor->id,
                    'branch_id' => $locked->branch_id,
                    'project_id' => $locked->project_id,
                    'process_type' => $locked->rows->first()->normalized_data['process_type'] ?? null,
                    'total' => $locked->total_rows,
                    'stage_events' => $createdEvents,
                    'bank_processes' => $createdBankProcesses,
                    'skipped' => $skipped,
                    'invalid' => $invalid,
                ],
            ]);

            return [
                'batch' => $locked,
                'created_events' => $createdEvents,
                'created_bank_processes' => $createdBankProcesses,
                'skipped' => $skipped,
                'review' => $review,
                'invalid' => $invalid,
            ];
        });
    }

    private function importRow(array $data, ConsumerImportBatch $batch, User $actor): array
    {
        $applicationId = $data['consumer_application_id'];
        $processType = $data['process_type'];
        $fingerprint = $data['fingerprint'] ?? null;

        if ($processType === 'proses_bank') {
            return $this->importBankProcess($applicationId, $data, $fingerprint, $batch->id, $actor);
        }

        return $this->importStageEvent($applicationId, $processType, $data, $fingerprint, $batch->id, $actor);
    }

    private function importStageEvent(int $applicationId, string $processType, array $data, ?string $fingerprint, int $batchId, User $actor): array
    {
        $stageKey = self::STAGE_KEYS[$processType] ?? $processType;
        if ($fingerprint) {
            $existing = ConsumerStageEvent::query()
                ->where('consumer_application_id', $applicationId)
                ->where('stage', $stageKey)
                ->where('source_id', $fingerprint)
                ->exists();
            if ($existing) {
                return ['stage_events_created' => 0, 'bank_processes_created' => 0];
            }
        }
        ConsumerStageEvent::create([
            'consumer_application_id' => $applicationId,
            'stage' => $stageKey,
            'status' => $data['status'] ?? 'completed',
            'occurred_at' => $this->parseDate($data['date'] ?? null),
            'source' => self::SOURCE,
            'source_id' => $fingerprint,
            'reason' => $data['notes'] ?? null,
            'metadata' => ['import_batch_id' => $batchId, 'actor_id' => $actor->id, 'process_type' => $processType],
        ]);

        return ['stage_events_created' => 1, 'bank_processes_created' => 0];
    }

    private function importBankProcess(int $applicationId, array $data, ?string $fingerprint, int $batchId, User $actor): array
    {
        if ($fingerprint) {
            $existing = ConsumerBankProcess::query()
                ->where('consumer_application_id', $applicationId)
                ->where('source_id', $fingerprint)
                ->exists();
            if ($existing) {
                return ['stage_events_created' => 0, 'bank_processes_created' => 0];
            }
        }
        $date = $this->parseDate($data['date'] ?? null);
        ConsumerBankProcess::create([
            'consumer_application_id' => $applicationId,
            'bank_name' => $data['bank_name'] ?? null,
            'status' => $data['bank_status'] ?? $data['status'] ?? null,
            'submitted_at' => $date,
            'source' => self::SOURCE,
            'source_id' => $fingerprint,
            'metadata' => ['import_batch_id' => $batchId, 'actor_id' => $actor->id, 'notes' => $data['notes'] ?? null],
        ]);

        return ['stage_events_created' => 0, 'bank_processes_created' => 1];
    }

    private function resolveApplication(array $data, Branch $branch, LeadMaster $project): array
    {
        $key = ConsumerPasteImportService::deterministicIdentityKey($data, $branch->id, $project->id);
        $applicationIds = ConsumerLegacyIdentity::query()
            ->where('legacy_source', ConsumerPasteImportService::SOURCE)
            ->where('spreadsheet_id', 'manual-paste')
            ->where('sheet_name', (string) $project->id)
            ->where('external_key', $key)
            ->whereNotNull('consumer_application_id')
            ->pluck('consumer_application_id')
            ->unique()
            ->values();

        if ($applicationIds->isEmpty()) {
            return ['status' => 'UNRESOLVED_APPLICATION'];
        }

        if ($applicationIds->count() > 1) {
            return ['status' => 'AMBIGUOUS_APPLICATION'];
        }

        $application = ConsumerApplication::query()
            ->with('kavling')
            ->where('branch_id', $branch->id)
            ->where('project_id', $project->id)
            ->whereNull('deleted_at')
            ->find($applicationIds->first());

        if ($application === null) {
            return ['status' => 'UNRESOLVED_APPLICATION'];
        }

        $rowKavling = strtolower(trim((string) ($data['kavling'] ?? '')));
        $appKavling = $application->kavling?->kavling_code ?: $application->kavling?->name;
        if ($rowKavling !== '' && $appKavling !== null && strtolower(trim((string) $appKavling)) !== $rowKavling) {
            return ['status' => 'IDENTITY_CONFLICT', 'application_id' => $application->id, 'identity_key' => $key];
        }

        return ['status' => 'RESOLVED', 'application_id' => $application->id, 'identity_key' => $key];
    }

    private function existingFingerprints(Branch $branch, LeadMaster $project, string $processType): array
    {
        $applicationIds = ConsumerApplication::query()
            ->where('branch_id', $branch->id)
            ->where('project_id', $project->id)
            ->whereNull('deleted_at')
            ->pluck('id')
            ->all();
        if ($applicationIds === []) {
            return [];
        }
        $fingerprints = [];
        if ($processType === 'proses_bank') {
            ConsumerBankProcess::query()
                ->whereIn('consumer_application_id', $applicationIds)
                ->where('source', self::SOURCE)
                ->whereNotNull('source_id')
                ->pluck('source_id')
                ->each(function ($fp) use (&$fingerprints): void {
                    $fingerprints[$fp] = true;
                });
        } else {
            $stageKey = self::STAGE_KEYS[$processType] ?? $processType;
            ConsumerStageEvent::query()
                ->whereIn('consumer_application_id', $applicationIds)
                ->where('stage', $stageKey)
                ->where('source', self::SOURCE)
                ->whereNotNull('source_id')
                ->pluck('source_id')
                ->each(function ($fp) use (&$fingerprints): void {
                    $fingerprints[$fp] = true;
                });
        }

        return $fingerprints;
    }

    private function fingerprint(int $applicationId, string $processType, array $data): string
    {
        return hash('sha256', json_encode([
            'application_id' => $applicationId,
            'process_type' => $processType,
            'date' => $data['date'] ?? null,
            'status' => $data['status'] ?? null,
            'bank_name' => $data['bank_name'] ?? null,
            'bank_status' => $data['bank_status'] ?? null,
            'notes' => $data['notes'] ?? null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function parse(string $input, string $processType): array
    {
        if (strlen($input) > 262144) {
            throw new InvalidArgumentException('Data TSV maksimal 256KB.');
        }
        $records = $this->parseRecords(preg_replace('/^\xEF\xBB\xBF/', '', $input));
        while ($records !== [] && count(end($records)) === 1 && trim((string) end($records)[0]) === '') {
            array_pop($records);
        }
        if ($records === []) {
            throw new InvalidArgumentException('Data TSV tidak memiliki baris.');
        }
        $headerCells = array_shift($records);
        $allHeaders = array_map(fn ($value, $index) => $this->headerKey((string) $value, $index), $headerCells, array_keys($headerCells));
        $headerBlankPositions = array_keys(array_filter($allHeaders, fn ($h) => $h === ''));
        $headers = array_values(array_filter($allHeaders, fn ($h) => $h !== ''));
        if ($headers === [] || ! in_array('kavling', $headers, true)) {
            throw new InvalidArgumentException('Header wajib memuat ID Kavling.');
        }
        if (count($headers) !== count(array_unique($headers))) {
            throw new InvalidArgumentException('Header TSV tidak boleh duplikat.');
        }
        if (count($records) > 500) {
            throw new InvalidArgumentException('Data TSV maksimal 500 baris.');
        }
        $rows = [];
        foreach ($records as $offset => $values) {
            if (count($values) === 1 && trim((string) $values[0]) === '') {
                continue;
            }
            if ($headerBlankPositions !== []) {
                foreach (array_reverse($headerBlankPositions) as $pos) {
                    array_splice($values, $pos, 1);
                }
            }
            $errors = [];
            if (count($values) !== count($headers)) {
                $errors[] = sprintf('Jumlah kolom tidak sesuai: header %d kolom, baris terbaca %d kolom.', count($headers), count($values));
            }
            $values = array_pad(array_slice($values, 0, count($headers)), count($headers), '');
            $raw = array_combine($headers, array_map(fn ($value) => trim((string) $value), $values));
            $normalized = [
                'kavling' => $raw['kavling'] ?? null,
                'name' => $raw['name'] ?? null,
                'phone' => $raw['phone'] ?? null,
                'external_id' => $raw['external_id'] ?? null,
                'date' => $raw['date'] ?? null,
                'status' => $raw['status'] ?? null,
                'bank_name' => $raw['bank_name'] ?? null,
                'bank_status' => $raw['bank_status'] ?? null,
                'notes' => $raw['notes'] ?? null,
            ];
            $parsedDate = $this->parseDate($normalized['date']);
            if (($raw['date'] ?? '') !== '' && $parsedDate === null) {
                $errors[] = 'Tanggal tidak valid atau ambigu.';
            }
            $rows[] = [
                'line_number' => $offset + 2,
                'raw_data' => $raw,
                'normalized_data' => $normalized,
                'warnings' => [],
                'errors' => $errors,
                'status' => 'READY',
            ];
        }

        return $rows;
    }

    private function parseRecords(string $input): array
    {
        $records = [];
        $row = [];
        $cell = '';
        $quoted = false;
        $length = strlen($input);
        for ($i = 0; $i < $length; $i++) {
            $char = $input[$i];
            if ($char === '"') {
                if ($quoted && ($input[$i + 1] ?? null) === '"') {
                    $cell .= '"';
                    $i++;
                } else {
                    $quoted = ! $quoted;
                }

                continue;
            }
            if (! $quoted && $char === "\t") {
                $row[] = $cell;
                $cell = '';

                continue;
            }
            if (! $quoted && ($char === "\n" || $char === "\r")) {
                if ($char === "\r" && ($input[$i + 1] ?? null) === "\n") {
                    $i++;
                }
                $row[] = $cell;
                $records[] = $row;
                $row = [];
                $cell = '';

                continue;
            }
            $cell .= $char;
        }
        if ($quoted) {
            throw new InvalidArgumentException('TSV memiliki tanda kutip yang belum ditutup.');
        }
        if ($cell !== '' || $row !== []) {
            $row[] = $cell;
            $records[] = $row;
        }

        return $records;
    }

    private function headerKey(string $header, int $index = -1): string
    {
        $key = Str::lower(trim(preg_replace('/\s+/', ' ', $header)));
        if ($key === '') {
            return '';
        }

        return self::HEADER_ALIASES[$key] ?? throw new InvalidArgumentException('Header tidak dikenali: '.$header);
    }

    private function parseDate(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $value, $m)) {
            $date = DateTimeImmutable::createFromFormat('!n/j/Y', "{$m[1]}/{$m[2]}/{$m[3]}");
            $errors = DateTimeImmutable::getLastErrors();
            if ($date && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)) && (int) $date->format('n') === (int) $m[1] && (int) $date->format('j') === (int) $m[2]) {
                return $date->format('Y-m-d');
            }
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value;
        }

        return null;
    }

    private function validateProcessType(string $processType): void
    {
        if (! isset(self::PROCESS_TYPES[$processType])) {
            throw new InvalidArgumentException('Jenis proses tidak valid: '.$processType);
        }
    }

    private function counts(array $rows): array
    {
        return array_replace(array_fill_keys(['READY', 'WARNING', 'ALREADY_IMPORTED', 'NEEDS_REVIEW', 'INVALID', 'UNRESOLVED_APPLICATION', 'AMBIGUOUS_APPLICATION', 'IDENTITY_CONFLICT'], 0), array_count_values(array_column($rows, 'status')));
    }
}
