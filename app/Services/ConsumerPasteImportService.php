<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\ConsumerApplication;
use App\Models\ConsumerImportBatch;
use App\Models\ConsumerLegacyIdentity;
use App\Models\ConsumerStageEvent;
use App\Models\Customer;
use App\Models\Kavling;
use App\Models\LeadMaster;
use App\Models\Promo;
use App\Models\SalesLead;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class ConsumerPasteImportService
{
    public const SOURCE = 'manual_spreadsheet_paste';

    private const HEADERS = [
        'Nama Konsumen', 'No HP', 'Proyek', 'Sales', 'Kavling', 'Promo', 'Status', 'Tahap',
        'Tanggal Booking', 'Tanggal Akad', 'Bank', 'Status Bank', 'External ID',
    ];

    private const HEADER_ALIASES = [
        'nama konsumen' => 'name', 'nama_konsumen' => 'name', 'nama' => 'name',
        'no hp' => 'phone', 'no_hp' => 'phone', 'nomor hp' => 'phone', 'phone' => 'phone',
        'proyek' => 'project', 'project' => 'project', 'project name' => 'project', 'project_name' => 'project',
        'sales' => 'sales', 'sales pic' => 'sales', 'sales_pic' => 'sales',
        'kavling' => 'kavling', 'id kavling' => 'kavling', 'id_kavling' => 'kavling', 'kav' => 'kavling',
        'promo' => 'promo', 'status' => 'status', 'status lead' => 'status', 'status_lead' => 'status',
        'tahap' => 'stage', 'stage' => 'stage', 'current stage' => 'stage', 'current_stage' => 'stage',
        'tanggal booking' => 'booking_date', 'booking date' => 'booking_date',
        'tanggal akad' => 'akad_date', 'akad date' => 'akad_date',
        'bank' => 'bank', 'bank name' => 'bank', 'status bank' => 'bank_status', 'bank status' => 'bank_status',
        'external id' => 'external_id', 'external_id' => 'external_id', 'id lead' => 'external_id', 'id_lead' => 'external_id',
        'external sync id' => 'external_id', 'external_sync_id' => 'external_id', 'oasis sync id' => 'external_id', 'oasis_sync_id' => 'external_id',
    ];

    private const STAGE_ALIASES = [
        'bi checking' => 'bi_checking', 'bi_checking' => 'bi_checking', 'psjb' => 'PSJB',
        'pemberkasan' => 'pemberkasan', 'proses bank' => 'proses_bank', 'proses_bank' => 'proses_bank',
        'ppjb dev' => 'ppjb_dev', 'ppjb_dev' => 'ppjb_dev', 'akad' => 'akad', 'akad kredit' => 'akad', 'bast' => 'bast',
    ];

    public function preview(string $input, Branch $branch, LeadMaster $project): array
    {
        $rows = $this->parse($input);
        $existing = $this->existingIdentityMap($branch, $project);
        $seen = [];

        return array_map(function (array $row) use ($branch, $project, $existing, &$seen): array {
            $row['normalized_data']['branch_id'] = $branch->id;
            $row['normalized_data']['project_id'] = $project->id;
            $row['normalized_data']['project_name'] = $project->project_name;
            $key = $this->identityKey($row['normalized_data'], $branch, $project);
            $row['normalized_data']['external_key'] = $key;
            $row['normalized_data']['identity_stable'] = $this->identityStable($row['normalized_data']);
            if (! $row['normalized_data']['identity_stable']) {
                $row['errors'][] = 'Tidak ada identitas stabil; baris perlu diperiksa sebelum import.';
            }
            $row['errors'] = array_merge($row['errors'], $this->mapRow($row['normalized_data'], $branch, $project));
            if (! empty($row['normalized_data']['promo_warning'])) {
                $row['warnings'][] = $row['normalized_data']['promo_warning'];
            }
            if (isset($existing[$key])) {
                $row['status'] = 'ALREADY_IMPORTED';
                $row['normalized_data']['existing_application_id'] = $existing[$key];
            } elseif (isset($seen[$key])) {
                $row['status'] = 'NEEDS_REVIEW';
                $row['errors'][] = 'Identitas stabil duplikat dalam paste.';
            } elseif ($row['errors'] !== []) {
                $row['status'] = $this->reviewStatus($row['errors']);
            } elseif ($row['warnings'] !== []) {
                $row['status'] = collect($row['warnings'])->contains(fn ($warning) => str_contains($warning, 'Tahap tidak dikenal')) ? 'NEEDS_REVIEW' : 'WARNING';
            } else {
                $row['status'] = 'READY';
            }
            $seen[$key] = true;

            return $row;
        }, $rows);
    }

    public function createBatch(User $actor, Branch $branch, LeadMaster $project, string $input): ConsumerImportBatch
    {
        $rows = $this->preview($input, $branch, $project);
        $counts = $this->counts($rows);

        return DB::transaction(function () use ($actor, $branch, $project, $rows, $counts): ConsumerImportBatch {
            $batch = ConsumerImportBatch::create([
                'public_id' => (string) Str::uuid(), 'uploaded_by' => $actor->id, 'branch_id' => $branch->id,
                'project_id' => $project->id, 'source' => self::SOURCE, 'status' => 'preview_ready',
                'expires_at' => now()->addHour(), 'total_rows' => count($rows), 'parsed_rows' => count($rows),
                'ready_rows' => $counts['READY'], 'already_imported_rows' => $counts['ALREADY_IMPORTED'],
                'warning_rows' => $counts['WARNING'], 'review_rows' => $counts['NEEDS_REVIEW'], 'invalid_rows' => $counts['INVALID'],
            ]);
            foreach ($rows as $row) {
                $batch->rows()->create([
                    'line_number' => $row['line_number'], 'normalized_data' => $row['normalized_data'],
                    'status' => $row['status'], 'warnings' => $row['warnings'], 'errors' => $row['errors'],
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
            $createdCustomers = $createdApplications = $reused = $skipped = 0;
            $warnings = $review = $invalid = 0;

            foreach ($rows as $row) {
                if ($row->status === 'ALREADY_IMPORTED') {
                    $skipped++;

                    continue;
                }
                if (in_array($row->status, ['INVALID', 'NEEDS_REVIEW'], true)) {
                    $originalStatus = $row->status;
                    $row->update(['status' => 'SKIPPED']);
                    $originalStatus === 'INVALID' ? $invalid++ : $review++;

                    continue;
                }
                try {
                    $result = DB::transaction(fn () => $this->importRow($row->normalized_data, $locked, $actor));
                    $createdCustomers += $result['customer_created'] ? 1 : 0;
                    $createdApplications += $result['application_created'] ? 1 : 0;
                    $reused += $result['reused'] ? 1 : 0;
                    $warnings += $row->status === 'WARNING' ? 1 : 0;
                    $row->update(['status' => 'IMPORTED']);
                } catch (Throwable $exception) {
                    $row->update(['status' => 'INVALID', 'errors' => array_merge($row->errors ?? [], ['Import gagal: '.$exception->getMessage()])]);
                    $invalid++;
                }
            }
            $locked->update(['status' => 'completed', 'confirmed_at' => now(), 'created_customers' => $createdCustomers, 'created_applications' => $createdApplications, 'reused_rows' => $reused, 'skipped_rows' => $skipped, 'warning_rows' => $warnings, 'review_rows' => $review, 'invalid_rows' => $invalid]);
            ActivityLog::create(['causer_id' => $actor->id, 'subject_type' => ConsumerImportBatch::class, 'subject_id' => $locked->id, 'event' => 'consumer_legacy_paste_import', 'description' => 'Impor paste data konsumen lokal selesai.', 'properties' => ['actor_id' => $actor->id, 'branch_id' => $locked->branch_id, 'project_id' => $locked->project_id, 'total' => $locked->total_rows, 'imported' => $createdApplications, 'skipped' => $skipped, 'warning' => $warnings, 'invalid' => $invalid]]);

            return ['batch' => $locked, 'created_customers' => $createdCustomers, 'created_applications' => $createdApplications, 'reused' => $reused, 'skipped' => $skipped, 'warning' => $warnings, 'review' => $review, 'invalid' => $invalid];
        });
    }

    private function importRow(array $data, ConsumerImportBatch $batch, User $actor): array
    {
        $identity = ConsumerLegacyIdentity::query()->where('legacy_source', self::SOURCE)->where('spreadsheet_id', 'manual-paste')->where('sheet_name', (string) $batch->project_id)->where('external_key', $data['external_key'])->lockForUpdate()->first();
        if ($identity?->consumer_application_id) {
            return ['customer_created' => false, 'application_created' => false, 'reused' => true];
        }
        $customer = $this->resolveCustomer($data);
        $customerCreated = $customer->wasRecentlyCreated;
        $application = ConsumerApplication::create(['customer_id' => $customer->id, 'branch_id' => $batch->branch_id, 'project_id' => $batch->project_id, 'sales_user_id' => $data['sales_user_id'] ?? null, 'kavling_id' => $data['kavling_id'] ?? null, 'promo_id' => $data['promo_id'] ?? null, 'application_status' => $data['application_status'] ?? 'draft', 'current_stage' => $data['current_stage'] ?? null, 'booking_date' => $data['booking_date'] ?? null, 'akad_date' => $data['akad_date'] ?? null, 'sales_lead_id' => $data['sales_lead_id'] ?? null]);
        ConsumerLegacyIdentity::create(['consumer_application_id' => $application->id, 'customer_id' => $customer->id, 'legacy_source' => self::SOURCE, 'spreadsheet_id' => 'manual-paste', 'sheet_name' => (string) $batch->project_id, 'external_key' => $data['external_key'], 'source_payload_hash' => hash('sha256', json_encode($data)), 'first_seen_at' => now(), 'last_seen_at' => now(), 'mapping_status' => 'imported']);
        if (! empty($data['current_stage'])) {
            ConsumerStageEvent::create(['consumer_application_id' => $application->id, 'stage' => $data['current_stage'], 'status' => 'current', 'source' => self::SOURCE, 'source_id' => $data['external_key']]);
        }
        if (! empty($data['bank']) || ! empty($data['bank_status'])) {
            $application->bankProcesses()->create(['bank_name' => $data['bank'] ?? null, 'status' => $data['bank_status'] ?? null, 'source' => self::SOURCE]);
        }

        return ['customer_created' => $customerCreated, 'application_created' => true, 'reused' => false];
    }

    private function resolveCustomer(array $data): Customer
    {
        $phone = $this->normalizePhone($data['phone'] ?? null);
        if ($phone !== '') {
            $matches = Customer::query()->where('phone', $phone)->get();
            if ($matches->count() === 1) {
                return $matches->first();
            }
        }

        return Customer::create(['name' => $data['name'], 'phone' => $phone ?: ($data['phone'] ?? null)]);
    }

    private function parse(string $input): array
    {
        if (strlen($input) > 262144) {
            throw new InvalidArgumentException('Data TSV maksimal 256KB.');
        }
        $input = preg_replace('/^\xEF\xBB\xBF/', '', str_replace(["\r\n", "\r"], "\n", $input));
        $lines = explode("\n", $input);
        while ($lines !== [] && trim((string) end($lines)) === '') {
            array_pop($lines);
        }
        if ($lines === []) {
            throw new InvalidArgumentException('Data TSV tidak memiliki baris.');
        }
        $headerCells = str_getcsv(array_shift($lines), "\t");
        $headers = array_map(fn ($value) => $this->headerKey((string) $value), $headerCells);
        if ($headers === [] || ! in_array('name', $headers, true)) {
            throw new InvalidArgumentException('Header wajib memuat Nama Konsumen.');
        }
        if (count($headers) !== count(array_unique($headers))) {
            throw new InvalidArgumentException('Header TSV tidak boleh duplikat.');
        }
        if (count($lines) > 500) {
            throw new InvalidArgumentException('Data TSV maksimal 500 baris.');
        }
        $rows = [];
        foreach ($lines as $offset => $line) {
            if (trim($line) === '') {
                continue;
            }
            $values = str_getcsv($line, "\t");
            $errors = [];
            if (count($values) !== count($headers)) {
                $errors[] = 'Jumlah kolom baris tidak cocok dengan header.';
            }
            $values = array_pad(array_slice($values, 0, count($headers)), count($headers), '');
            $raw = array_combine($headers, array_map(fn ($value) => trim((string) $value), $values));
            if ($raw['name'] === '') {
                $errors[] = 'Nama Konsumen wajib diisi.';
            }
            $normalized = ['name' => $raw['name'], 'phone' => $raw['phone'] ?? null, 'project' => $raw['project'] ?? null, 'sales' => $raw['sales'] ?? null, 'kavling' => $raw['kavling'] ?? null, 'promo' => $raw['promo'] ?? null, 'application_status' => $raw['status'] ?? null, 'current_stage' => $this->stage($raw['stage'] ?? null), 'booking_date' => $this->date($raw['booking_date'] ?? null), 'akad_date' => $this->date($raw['akad_date'] ?? null), 'bank' => $raw['bank'] ?? null, 'bank_status' => $raw['bank_status'] ?? null, 'external_id' => $raw['external_id'] ?? null];
            if (($raw['booking_date'] ?? '') !== '' && $normalized['booking_date'] === null) {
                $errors[] = 'Tanggal Booking tidak valid atau ambigu.';
            }
            if (($raw['akad_date'] ?? '') !== '' && $normalized['akad_date'] === null) {
                $errors[] = 'Tanggal Akad tidak valid atau ambigu.';
            }
            $rows[] = ['line_number' => $offset + 2, 'raw_data' => $raw, 'normalized_data' => $normalized, 'warnings' => $normalized['current_stage'] === null && ($raw['stage'] ?? '') !== '' ? ['Tahap tidak dikenal; baris perlu diperiksa.'] : [], 'errors' => $errors, 'status' => 'READY'];
        }

        return $rows;
    }

    private function mapRow(array &$data, Branch $branch, LeadMaster $project): array
    {
        $errors = [];
        if ($data['project'] !== null && $this->norm($data['project']) !== $this->norm($project->project_name)) {
            $errors[] = 'Proyek pasted tidak cocok dengan proyek terpilih.';
        }
        if (($data['project'] ?? '') !== '') {
            $data['project'] = $project->id;
        } else {
            $data['project'] = $project->id;
        }
        if ($data['sales']) {
            $sales = User::query()->where('branch_id', $branch->id)->whereRaw('LOWER(name) = ?', [$this->norm($data['sales'])])->where('is_active', true)->whereHas('assignedProjects', fn ($q) => $q->whereKey($project->id)->wherePivot('is_active', true))->get();
            if ($sales->count() === 1) {
                $data['sales_user_id'] = $sales->first()->id;
            } else {
                $errors[] = $sales->isEmpty() ? 'Sales tidak ditemukan dalam cabang/proyek terpilih.' : 'Sales ambigu dalam cabang/proyek terpilih.';
            }
        }
        if ($data['kavling']) {
            $kavlings = Kavling::query()->where('project_id', $project->id)->where(fn ($q) => $q->whereRaw('LOWER(kavling_code) = ?', [$this->norm($data['kavling'])])->orWhereRaw('LOWER(name) = ?', [$this->norm($data['kavling'])]))->get();
            if ($kavlings->count() === 1) {
                $data['kavling_id'] = $kavlings->first()->id;
                if (ConsumerApplication::where('kavling_id', $data['kavling_id'])->whereNull('deleted_at')->whereNotIn('application_status', ['cancelled', 'rejected'])->exists()) {
                    $errors[] = 'Kavling sudah terhubung ke aplikasi aktif.';
                }
            } else {
                $errors[] = $kavlings->isEmpty() ? 'Kavling tidak ditemukan dalam proyek terpilih.' : 'Kavling ambigu dalam proyek terpilih.';
            }
        }
        if ($data['promo']) {
            $promo = Promo::query()->where('branch_id', $branch->id)->where(fn ($q) => $q->whereRaw('LOWER(code) = ?', [$this->norm($data['promo'])])->orWhereRaw('LOWER(name) = ?', [$this->norm($data['promo'])]))->first();
            if ($promo) {
                $data['promo_id'] = $promo->id;
            } else {
                $data['promo_warning'] = 'Promo tidak ditemukan; diabaikan.';
            }
        }
        if ($data['external_id']) {
            $data['external_id'] = trim($data['external_id']);
        }
        $data['sales_lead_id'] = $this->salesLeadId($data, $branch, $project);
        $data['phone'] = $this->normalizePhone($data['phone']);
        if ($data['application_status'] === null || $data['application_status'] === '') {
            $data['application_status'] = 'draft';
        }

        return $errors;
    }

    private function salesLeadId(array $data, Branch $branch, LeadMaster $project): ?int
    {
        if (! $data['external_id']) {
            return null;
        }

        return SalesLead::query()->where('branch_id', $branch->id)->where('project_id', $project->id)->where(fn ($q) => $q->where('external_sync_id', $data['external_id'])->orWhere('external_lead_id', $data['external_id']))->value('id');
    }

    private function existingIdentityMap(Branch $branch, LeadMaster $project): array
    {
        return ConsumerLegacyIdentity::query()->where('legacy_source', self::SOURCE)->where('spreadsheet_id', 'manual-paste')->where('sheet_name', (string) $project->id)->pluck('consumer_application_id', 'external_key')->all();
    }

    private function identityKey(array $data, Branch $branch, LeadMaster $project): string
    {
        $stable = trim((string) ($data['external_id'] ?? ''));
        if ($stable !== '') {
            return 'external:'.mb_strtolower($stable);
        }

        return 'row:'.hash('sha256', implode('|', [$branch->id, $project->id, $this->normalizePhone($data['phone'] ?? null), $this->norm($data['kavling'] ?? ''), $this->norm($data['name'] ?? '')]));
    }

    private function identityStable(array $data): bool
    {
        return trim((string) ($data['external_id'] ?? '')) !== '' || trim((string) ($data['phone'] ?? '')) !== '' || trim((string) ($data['kavling'] ?? '')) !== '';
    }

    private function counts(array $rows): array
    {
        return array_replace(array_fill_keys(['READY', 'WARNING', 'ALREADY_IMPORTED', 'NEEDS_REVIEW', 'INVALID'], 0), array_count_values(array_column($rows, 'status')));
    }

    private function reviewStatus(array $errors): string
    {
        return collect($errors)->contains(fn ($error) => str_contains($error, 'tidak ditemukan') || str_contains($error, 'ambigu') || str_contains($error, 'Kavling sudah') || str_contains($error, 'identitas stabil')) ? 'NEEDS_REVIEW' : 'INVALID';
    }

    private function headerKey(string $header): string
    {
        $key = Str::lower(trim(preg_replace('/\s+/', ' ', $header)));

        return self::HEADER_ALIASES[$key] ?? throw new InvalidArgumentException('Header tidak dikenali: '.$header);
    }

    private function norm(?string $value): string
    {
        return Str::lower(trim(preg_replace('/\s+/', ' ', (string) $value)));
    }

    private function normalizePhone(?string $value): string
    {
        $phone = preg_replace('/[^0-9+]/', '', (string) $value);
        if (str_starts_with($phone, '+62')) {
            return '0'.substr($phone, 3);
        } if (str_starts_with($phone, '62')) {
            return '0'.substr($phone, 2);
        }

        return $phone;
    }

    private function stage(?string $value): ?string
    {
        return self::STAGE_ALIASES[Str::lower(trim((string) $value))] ?? null;
    }

    private function date(?string $value): ?string
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
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            $errors = DateTimeImmutable::getLastErrors();
            if ($date && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)) && $date->format('Y-m-d') === $value) {
                return $value;
            }
        }

        return null;
    }
}
