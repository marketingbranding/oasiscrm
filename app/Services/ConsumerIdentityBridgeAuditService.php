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
        $diagnostics = $this->diagnostics($legacy['records'], $local['records']);

        return compact('legacy', 'local', 'candidates', 'diagnostics');
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
            $records[] = ['name' => $this->first($data, ['nama_konsumen', 'nama konsumen', 'nama']), 'phone' => $phone, 'kavling' => $this->key($kavling), 'external_id' => $sourceId, 'nik_hash' => $nik === '' ? null : $this->fingerprint($nik), 'consumer_status' => $this->first($data, ['status_konsumen']) ?: null];
        }

        return ['total' => count($records), 'counts' => $counts, 'status_distribution' => $status, 'duplicates' => $this->duplicates($phones, $kavlings, $phoneKavlings), 'nik_fingerprint_available' => $counts['nik'] > 0, 'records' => $records];
    }

    private function local(Branch $branch, LeadMaster $project): array
    {
        $apps = ConsumerApplication::with(['customer', 'kavling', 'legacyIdentities'])->where('branch_id', $branch->id)->where('project_id', $project->id)->get();
        $phones = $kavlings = $phoneKavlings = $customers = [];
        $statuses = $prefixes = $consumerStatuses = $lastProcesses = $completenessStatuses = [];
        $semanticCoverage = ['at_least_one' => 0, 'all_three' => 0, 'none' => 0];
        $records = [];
        foreach ($apps as $app) {
            $phone = $this->phone($app->customer?->phone);
            $kavling = $this->key($app->kavling?->kavling_code ?: $app->kavling?->name);
            $identity = $app->legacyIdentities->sortByDesc('updated_at')->first();
            $prefix = $identity?->external_key ? Str::before($identity->external_key, ':') : 'local/none';
            $prefixes[$prefix] = ($prefixes[$prefix] ?? 0) + 1;
            $statuses[$app->application_status] = ($statuses[$app->application_status] ?? 0) + 1;
            $consumerStatus = trim((string) $app->consumer_status);
            $lastProcess = trim((string) $app->source_last_process);
            $completenessStatus = trim((string) $app->source_completeness_status);
            $consumerStatusKey = in_array($consumerStatus, ['Lanjut', 'Mundur', 'Pindah Kavling', 'Reject'], true) ? $consumerStatus : 'null/unknown';
            $completenessStatusKey = in_array($completenessStatus, ['Data Lengkap', 'Data Belum Lengkap'], true) ? $completenessStatus : ($completenessStatus === '' ? 'null' : 'other');
            $consumerStatuses[$consumerStatusKey] = ($consumerStatuses[$consumerStatusKey] ?? 0) + 1;
            $lastProcesses[$lastProcess !== '' ? $lastProcess : 'null'] = ($lastProcesses[$lastProcess !== '' ? $lastProcess : 'null'] ?? 0) + 1;
            $completenessStatuses[$completenessStatusKey] = ($completenessStatuses[$completenessStatusKey] ?? 0) + 1;
            $semanticCount = collect([$consumerStatus, $lastProcess, $completenessStatus])->filter()->count();
            if ($semanticCount === 0) {
                $semanticCoverage['none']++;
            } else {
                $semanticCoverage['at_least_one']++;
                if ($semanticCount === 3) {
                    $semanticCoverage['all_three']++;
                }
            }
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
            $records[] = ['id' => $app->id, 'customer_id' => $app->customer_id, 'name' => $app->customer?->name, 'phone' => $phone, 'kavling' => $kavling, 'external_id' => $identity?->external_key && Str::startsWith($identity->external_key, 'external:') ? Str::after($identity->external_key, 'external:') : null, 'nik_hash' => $app->customer?->nik_encrypted ? $this->fingerprint($app->customer->nik_encrypted) : null, 'identity_count' => $app->legacyIdentities->count(), 'identity_rows' => $app->legacyIdentities->map(fn ($identity) => ['application_id' => $identity->consumer_application_id, 'legacy_source' => $identity->legacy_source, 'external_key' => $identity->external_key])->values()->all(), 'status' => null];
        }

        return ['total' => $apps->count(), 'unique_customers' => count($customers), 'with_phone' => count($phones), 'with_kavling' => count($kavlings), 'with_nik' => $apps->filter(fn ($a) => filled($a->customer?->getRawOriginal('nik_encrypted')))->count(), 'application_status' => $statuses, 'identity_prefixes' => $prefixes, 'consumer_status' => $consumerStatuses, 'source_last_process' => $lastProcesses, 'source_completeness_status' => $completenessStatuses, 'semantic_coverage' => $semanticCoverage, 'duplicates' => $this->duplicates($phones, $kavlings, $phoneKavlings), 'customers_with_multiple_applications' => count(array_filter($customers, fn ($n) => $n > 1)), 'records' => $records];
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
            $pairCount = $pair ? count($byPair[$pair] ?? []) : 0;
            $nikCount = $nik ? count($byNik[$nik] ?? []) : 0;
            $out['rows'][] = ['name' => $record['name'], 'phone' => $this->maskPhone($record['phone']), 'kavling' => $record['kavling'], 'consumer_status' => $record['consumer_status'] ?: 'unknown / unavailable', 'category' => $category, 'reason' => $category === 'AMBIGUOUS' ? ($nikCount > 1 ? 'duplicate NIK fingerprint' : ($pairCount > 1 ? 'duplicate phone+kavling' : 'conflicting candidate')) : null, 'legacy_candidate_count' => 1, 'local_candidate_count' => max($pairCount, $nikCount, $key ? count($byExternal[$key] ?? []) : 0), 'duplicate_nik' => $nikCount > 1, 'duplicate_phone_kavling' => $pairCount > 1, 'reused_kavling' => $record['kavling'] !== '' && count(array_filter($local, fn ($r) => $r['kavling'] === $record['kavling'])) > 1];
        }

        return $out;
    }

    private function diagnostics(array $legacy, array $local): array
    {
        $legacyPairs = $this->group($legacy, fn ($r) => $r['phone'] && $r['kavling'] ? $r['phone'].'|'.$r['kavling'] : '');
        $localPairs = $this->group($local, fn ($r) => $r['phone'] && $r['kavling'] ? $r['phone'].'|'.$r['kavling'] : '');
        $pairKeys = array_unique(array_merge(array_keys($legacyPairs), array_keys($localPairs)));
        $raw = $unique = $ambiguous = $unmatchedLegacy = $unmatchedLocal = 0;
        foreach ($pairKeys as $key) {
            $left = count($legacyPairs[$key] ?? []);
            $right = count($localPairs[$key] ?? []);
            if ($left && $right) {
                $raw++;
                if ($left === 1 && $right === 1) {
                    $unique++;
                } else {
                    $ambiguous++;
                }
            } elseif ($left) {
                $unmatchedLegacy += $left;
            } elseif ($right) {
                $unmatchedLocal += $right;
            }
        }
        $legacyNik = $this->group($legacy, 'nik_hash');
        $localNik = $this->group($local, 'nik_hash');
        $exact = $safe = $safeLinkable = $oneMany = $manyOne = $unmatchedLegacyNik = $unmatchedLocalNik = $conflicts = 0;
        $classifications = ['SAME_APPLICATION_EXPECTED' => 0, 'TRUE_CONFLICT' => 0, 'MULTIPLE_IDENTITY_ROWS' => 0, 'NO_CONFLICT' => 0];
        $duplicateLocalGroups = $unmatchedLegacyDetails = $unmatchedLocalDetails = [];
        foreach (array_unique(array_merge(array_keys($legacyNik), array_keys($localNik))) as $key) {
            $left = count($legacyNik[$key] ?? []);
            $right = count($localNik[$key] ?? []);
            if (! $left || ! $right) {
                if ($left) {
                    $unmatchedLegacyNik += $left;
                    foreach ($legacyNik[$key] as $row) {
                        $unmatchedLegacyDetails[] = ['phone' => $this->maskPhone($row['phone']), 'kavling' => $row['kavling'], 'reason' => 'no local NIK match'];
                    }
                } else {
                    $unmatchedLocalNik += $right;
                    foreach ($localNik[$key] as $row) {
                        $unmatchedLocalDetails[] = ['application_id' => $row['id'], 'phone' => $this->maskPhone($row['phone']), 'kavling' => $row['kavling'], 'reason' => 'no legacy NIK match'];
                    }
                }

                continue;
            }
            $exact++;
            if ($right > 1) {
                $oneMany++;
            }
            if ($left > 1) {
                $manyOne++;
            }
            if ($left === 1 && $right === 1) {
                $identityRows = $localNik[$key][0]['identity_rows'] ?? [];
                $classification = count($identityRows) > 1 ? 'MULTIPLE_IDENTITY_ROWS' : (count($identityRows) === 0 ? 'NO_CONFLICT' : (($identityRows[0]['application_id'] ?? null) === $localNik[$key][0]['id'] && ($identityRows[0]['legacy_source'] ?? null) === 'manual_spreadsheet_paste' ? 'SAME_APPLICATION_EXPECTED' : 'TRUE_CONFLICT'));
                $classifications[$classification]++;
                if ($classification === 'TRUE_CONFLICT' || $classification === 'MULTIPLE_IDENTITY_ROWS') {
                    $conflicts++;
                }
                if ($classification === 'NO_CONFLICT' || $classification === 'SAME_APPLICATION_EXPECTED') {
                    $safeLinkable++;
                }
                if ($classification === 'NO_CONFLICT') {
                    $safe++;
                }
            } elseif ($left > 1 || $right > 1) {
                $classifications['TRUE_CONFLICT']++;
            }
        }

        foreach ($localNik as $fingerprint => $rows) {
            if (count($rows) > 1) {
                $duplicateLocalGroups[] = ['applications' => count($rows), 'customers' => count(array_unique(array_map(fn ($r) => $r['customer_id'] ?? null, $rows))), 'phones' => count(array_unique(array_filter(array_map(fn ($r) => $r['phone'], $rows)))), 'kavlings' => count(array_unique(array_filter(array_map(fn ($r) => $r['kavling'], $rows)))), 'names' => count(array_unique(array_filter(array_map(fn ($r) => $r['name'], $rows)))), 'manual_external_keys' => collect($rows)->flatMap(fn ($r) => collect($r['identity_rows'] ?? [])->where('legacy_source', 'manual_spreadsheet_paste')->pluck('external_key'))->filter()->unique()->count(), 'reason' => 'duplicate local NIK fingerprint'];
            }
        }

        return ['phone_kavling' => ['legacy_rows' => array_sum(array_map('count', $legacyPairs)), 'local_applications' => array_sum(array_map('count', $localPairs)), 'raw_exact_matches' => $raw, 'raw_unique_one_to_one' => $unique, 'duplicate_legacy_keys' => count(array_filter($legacyPairs, fn ($v) => count($v) > 1)), 'duplicate_local_keys' => count(array_filter($localPairs, fn ($v) => count($v) > 1)), 'ambiguous_matches' => $ambiguous, 'unmatched_legacy' => $unmatchedLegacy, 'unmatched_local' => $unmatchedLocal], 'nik' => ['legacy_valid' => array_sum(array_map('count', $legacyNik)), 'local_valid' => array_sum(array_map('count', $localNik)), 'unique_legacy_fingerprints' => count($legacyNik), 'unique_local_fingerprints' => count($localNik), 'exact_matches' => $exact, 'safe_one_to_one' => $safe, 'duplicate_legacy_groups' => count(array_filter($legacyNik, fn ($v) => count($v) > 1)), 'duplicate_local_groups' => count(array_filter($localNik, fn ($v) => count($v) > 1)), 'one_legacy_multiple_local' => $oneMany, 'multiple_legacy_one_local' => $manyOne, 'unmatched_legacy' => $unmatchedLegacyNik, 'unmatched_local' => $unmatchedLocalNik, 'identity_conflicts' => $conflicts, 'safe_linkable' => $safeLinkable, 'classifications' => $classifications, 'duplicate_local_details' => $duplicateLocalGroups, 'unmatched_legacy_details' => $unmatchedLegacyDetails, 'unmatched_local_details' => $unmatchedLocalDetails]];
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
