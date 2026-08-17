<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\ConsumerApplication;
use App\Models\KonsumenProgressSheetRow;
use App\Models\LeadMaster;
use Illuminate\Support\Str;

final class ConsumerIdentityBridgeAuditService
{
    public function audit(Branch $branch, LeadMaster $project): array
    {
        $legacy = $this->legacy($branch, $project);
        $local = $this->local($branch, $project);
        $candidates = $this->candidates($legacy['records'], $local['records']);

        return compact('legacy', 'local', 'candidates');
    }

    private function legacy(Branch $branch, LeadMaster $project): array
    {
        $projectKavlings = $project->kavlings()->pluck('kavling_code')->map(fn ($value) => $this->key($value))->all();
        $records = [];
        $status = [];
        $phones = [];
        $kavlings = [];
        $phoneKavlings = [];
        $fields = ['external_id', 'external_key', 'id_konsumen', 'id_customer', 'id_lead'];
        $counts = array_fill_keys($fields, 0) + ['phone' => 0, 'kavling' => 0, 'status' => 0, 'nik' => 0];

        foreach (KonsumenProgressSheetRow::where('branch_id', $branch->id)->where('sheet_name', 'data_konsumen')->get(['row_data']) as $row) {
            $data = $this->normalized($row->row_data ?? []);
            if (($data['proyek'] ?? $data['project'] ?? '') !== '' && Str::lower($data['proyek'] ?? $data['project']) !== Str::lower($project->project_name)) {
                continue;
            }
            $phone = $this->phone($this->first($data, ['no_hp', 'phone', 'nomor hp']));
            $kavling = $this->value($data, ['id_kavling', 'kavling', 'kav']);
            $sourceId = null;
            foreach ($fields as $field) {
                if (($data[$field] ?? '') !== '') {
                    $counts[$field]++;
                    $sourceId ??= $data[$field];
                }
            }
            if ($phone !== '') {
                $counts['phone']++;
                $phones[] = $phone;
            }
            if ($kavling !== '') {
                $counts['kavling']++;
                $kavlings[] = $kavling;
            }
            $statusValue = $this->first($data, ['status_konsumen', 'status']);
            if ($statusValue !== '') {
                $counts['status']++;
                $status[$statusValue] = ($status[$statusValue] ?? 0) + 1;
            }
            $nik = $this->first($data, ['nik', 'no ktp', 'no_ktp', 'ktp']);
            if ($nik !== '') {
                $counts['nik']++;
            }
            if ($phone !== '' && $kavling !== '') {
                $phoneKavlings[] = $phone.'|'.$this->key($kavling);
            }
            $records[] = ['name' => $this->first($data, ['nama_konsumen', 'nama konsumen', 'nama']), 'phone' => $phone, 'kavling' => $this->key($kavling), 'external_id' => $sourceId, 'nik_hash' => $nik === '' ? null : $this->fingerprint($nik), 'status' => $statusValue ?: null];
        }

        return ['total' => count($records), 'counts' => $counts, 'status_distribution' => $status, 'duplicates' => $this->duplicates($phones, $kavlings, $phoneKavlings), 'nik_fingerprint_available' => $counts['nik'] > 0, 'records' => $records];
    }

    private function local(Branch $branch, LeadMaster $project): array
    {
        $apps = ConsumerApplication::with(['customer', 'kavling', 'legacyIdentities'])->where('branch_id', $branch->id)->where('project_id', $project->id)->get();
        $phones = $kavlings = $phoneKavlings = $customers = [];
        $statuses = $prefixes = [];
        $records = [];
        foreach ($apps as $app) {
            $phone = $this->phone($app->customer?->phone);
            $kavling = $this->key($app->kavling?->kavling_code ?: $app->kavling?->name);
            $identity = $app->legacyIdentities->sortByDesc('updated_at')->first();
            $prefix = $identity?->external_key ? Str::before($identity->external_key, ':') : 'local/none';
            $prefixes[$prefix] = ($prefixes[$prefix] ?? 0) + 1;
            $statuses[$app->application_status] = ($statuses[$app->application_status] ?? 0) + 1;
            if ($phone !== '') {
                $phones[] = $phone;
            }
            if ($kavling !== '') {
                $kavlings[] = $kavling;
            }
            if ($phone !== '' && $kavling !== '') {
                $phoneKavlings[] = $phone.'|'.$kavling;
            }
            $customers[$app->customer_id] = ($customers[$app->customer_id] ?? 0) + 1;
            $records[] = ['id' => $app->id, 'name' => $app->customer?->name, 'phone' => $phone, 'kavling' => $kavling, 'external_id' => $identity?->external_key && Str::startsWith($identity->external_key, 'external:') ? Str::after($identity->external_key, 'external:') : null, 'nik_hash' => $app->customer?->nik_encrypted ? $this->fingerprint($app->customer->nik_encrypted) : null, 'status' => null];
        }

        return ['total' => $apps->count(), 'unique_customers' => count($customers), 'with_phone' => count($phones), 'with_kavling' => count($kavlings), 'with_nik' => $apps->filter(fn ($a) => filled($a->customer?->getRawOriginal('nik_encrypted')))->count(), 'application_status' => $statuses, 'identity_prefixes' => $prefixes, 'duplicates' => $this->duplicates($phones, $kavlings, $phoneKavlings), 'customers_with_multiple_applications' => count(array_filter($customers, fn ($n) => $n > 1)), 'records' => $records];
    }

    private function candidates(array $legacy, array $local): array
    {
        $out = ['SHARED_EXTERNAL_ID' => 0, 'UNIQUE_PHONE_KAVLING' => 0, 'NIK_FINGERPRINT_CANDIDATE' => 0, 'AMBIGUOUS' => 0, 'UNMATCHED' => 0, 'rows' => []];
        $byExternal = $this->group($local, 'external_id');
        $byPair = $this->group($local, fn ($r) => $r['phone'].'|'.$r['kavling']);
        $byNik = $this->group($local, 'nik_hash');
        foreach ($legacy as $record) {
            $category = 'UNMATCHED';
            $key = $record['external_id'] ? Str::lower($record['external_id']) : null;
            $pair = $record['phone'] && $record['kavling'] ? $record['phone'].'|'.$record['kavling'] : null;
            $nik = $record['nik_hash'];
            if ($key && count($byExternal[$key] ?? []) === 1) {
                $category = 'SHARED_EXTERNAL_ID';
            } elseif ($pair && count($byPair[$pair] ?? []) === 1) {
                $category = 'UNIQUE_PHONE_KAVLING';
            } elseif ($nik && count($byNik[$nik] ?? []) === 1) {
                $category = 'NIK_FINGERPRINT_CANDIDATE';
            } elseif (($pair && count($byPair[$pair] ?? []) > 1) || ($key && count($byExternal[$key] ?? []) > 1) || ($nik && count($byNik[$nik] ?? []) > 1)) {
                $category = 'AMBIGUOUS';
            }
            $out[$category]++;
            $out['rows'][] = ['name' => $record['name'], 'phone' => $this->maskPhone($record['phone']), 'kavling' => $record['kavling'], 'status' => $record['status'] ?: 'unknown / unavailable', 'category' => $category];
        }

        return $out;
    }

    private function normalized(array $data): array
    {
        return collect($data)->mapWithKeys(fn ($v, $k) => [Str::lower(trim((string) $k)) => is_scalar($v) ? trim((string) $v) : ''])->all();
    }

    private function first(array $data, array $keys): string
    {
        foreach ($keys as $key) {
            if (($data[Str::lower($key)] ?? '') !== '') {
                return $data[Str::lower($key)];
            }
        }

        return '';
    }

    private function value(array $data, array $keys): string
    {
        return $this->key($this->first($data, $keys));
    }

    private function key(?string $value): string
    {
        return Str::lower(trim((string) $value));
    }

    private function phone(?string $value): string
    {
        $v = preg_replace('/[^0-9+]/', '', (string) $value);
        if (Str::startsWith($v, '+62')) {
            return '0'.substr($v, 3);
        } if (Str::startsWith($v, '62')) {
            return '0'.substr($v, 2);
        }

        return $v;
    }

    private function fingerprint(string $value): string
    {
        return hash_hmac('sha256', preg_replace('/\D/', '', $value), (string) config('app.key'));
    }

    private function maskPhone(string $value): string
    {
        return $value === '' ? '—' : substr($value, 0, 4).'****'.substr($value, -4);
    }

    private function group(array $rows, string|callable $key): array
    {
        $out = [];
        foreach ($rows as $row) {
            $v = is_callable($key) ? $key($row) : $row[$key];
            if ($v !== '') {
                $out[Str::lower($v)][] = $row;
            }
        }

        return $out;
    }

    private function duplicates(array $phones, array $kavlings, array $pairs): array
    {
        return ['phone' => $this->duplicateCount($phones), 'kavling' => $this->duplicateCount($kavlings), 'phone_kavling' => $this->duplicateCount($pairs)];
    }

    private function duplicateCount(array $values): int
    {
        return count(array_filter(array_count_values($values), fn ($n) => $n > 1));
    }
}
