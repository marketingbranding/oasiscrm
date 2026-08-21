<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\ConsumerApplication;
use App\Models\Customer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class ConsumerDatabaseWriteService
{
    public function __construct(
        private DatabaseModuleRegistry $registry,
        private OrganizationScopeService $scope,
        private ConsumerOperationalService $operational,
    ) {}

    public function update(User $actor, string $module, int $applicationId, array $payload): array
    {
        abort_unless(in_array($module, $this->registry->slugs(), true), 404);
        $application = ConsumerApplication::query()->findOrFail($applicationId);
        abort_unless($actor->hasPermission('consumer_progress.manage') || $actor->hasScopedPermission('consumer_progress', 'manage'), 403);
        abort_unless(in_array((int) $application->branch_id, $this->scope->branchIds($actor, 'consumer_progress', 'manage'), true), 403);
        abort_unless(in_array((int) $application->project_id, $this->scope->projectIds($actor, 'consumer_progress', 'manage'), true), 403);

        $column = collect($this->registry->get($module)['columns'])->firstWhere('key', $payload['column'] ?? null);
        $this->unprocessableIf(! $column || ! $column['editable'], 'column', 'Kolom tidak dapat diedit.');
        $value = $this->normalize($payload['value'] ?? null, $column['key']);
        $payload['value'] = $value;
        $validator = Validator::make($payload, ['column' => ['required', 'string'], 'expected_updated_at' => ['required', 'date'], 'value' => $column['validation']]);
        if ($validator->fails()) {
            throw new HttpResponseException(response()->json(['message' => 'Nilai tidak valid.', 'errors' => $validator->errors()], 422));
        }

        $customerId = $application->customer_id;

        return DB::transaction(function () use ($actor, $module, $applicationId, $customerId, $column, $payload, $value): array {
            $affected = collect();
            if ($column['write_strategy'] === 'customer_field') {
                $this->unprocessableIf(! $customerId, 'value', 'Data customer tidak tersedia; kolom master hanya dapat dibaca.');
                $target = Customer::query()->lockForUpdate()->find($customerId);
                $this->unprocessableIf(! $target, 'value', 'Data customer tidak tersedia; kolom master hanya dapat dibaca.');
                $affected = ConsumerApplication::query()->where('customer_id', $customerId)->orderBy('id')->lockForUpdate()->get();
                $application = $affected->firstWhere('id', $applicationId);
                $this->unprocessableIf(! $application, 'value', 'Aplikasi tidak lagi terhubung ke data customer ini.');
            } else {
                $application = ConsumerApplication::query()->lockForUpdate()->findOrFail($applicationId);
                $target = $application;
            }
            $this->authorizeManage($actor, $application);
            $this->assertFresh($target, (string) $payload['expected_updated_at'], $application, $column);
            $field = $column['write_target']['field'];
            $before = $target->{$field};
            $target->{$field} = $value;
            $target->save();
            $affectedApplicationIds = [$application->id];
            if ($column['write_strategy'] === 'customer_field') {
                $affectedApplicationIds = $affected->pluck('id')->all();
                foreach ($affected as $affectedApplication) {
                    $affectedApplication->setRelation('customer', $target);
                    $affectedApplication->source_completeness_status = $this->operational->completeness($affectedApplication)['status'];
                    $affectedApplication->save();
                }
            }
            $target->refresh();
            $application->refresh();
            ActivityLog::create([
                'causer_id' => $actor->id,
                'subject_type' => ConsumerApplication::class,
                'subject_id' => $application->id,
                'event' => 'consumer_database_cell_updated',
                'description' => 'Kolom database konsumen diperbarui.',
                'properties' => [
                    'actor_id' => $actor->id, 'application_id' => $application->id, 'customer_id' => $application->customer_id,
                    'branch_id' => $application->branch_id, 'project_id' => $application->project_id, 'module' => $module,
                    'column' => $column['key'], 'strategy' => $column['write_strategy'],
                    'affected_application_ids' => $affectedApplicationIds, 'affected_application_count' => count($affectedApplicationIds),
                    'before' => $this->auditValue($column['key'], $before), 'after' => $this->auditValue($column['key'], $value),
                ],
            ]);

            return $this->response($target, $application, $column);
        });
    }

    public function token(object $target): ?string
    {
        return $target->updated_at?->format('Y-m-d\TH:i:s.uP');
    }

    private function authorizeManage(User $actor, ConsumerApplication $application): void
    {
        abort_unless($actor->hasPermission('consumer_progress.manage') || $actor->hasScopedPermission('consumer_progress', 'manage'), 403);
        abort_unless(in_array((int) $application->branch_id, $this->scope->branchIds($actor, 'consumer_progress', 'manage'), true), 403);
        abort_unless(in_array((int) $application->project_id, $this->scope->projectIds($actor, 'consumer_progress', 'manage'), true), 403);
    }

    private function normalize(mixed $value, string $column): mixed
    {
        if ($column === 'status_cash') {
            return match ($value) {
                true, 1, '1', 'true' => true,
                false, 0, '0', 'false' => false,
                null, '' => null,
                default => $this->unprocessable('value', 'Status Cash tidak valid.'),
            };
        }
        $value = is_string($value) ? trim($value) : $value;

        return $column === 'notes' && $value === '' ? null : $value;
    }

    private function assertFresh(object $target, string $expected, ConsumerApplication $application, array $column): void
    {
        $current = $this->token($target);
        $matches = $current && CarbonImmutable::parse($expected)->equalTo(CarbonImmutable::parse($current));
        if ($matches) {
            return;
        }
        $field = $column['write_target']['field'];
        throw new HttpResponseException(response()->json([
            'code' => 'record_modified', 'message' => 'Data telah diperbarui. Muat ulang sebelum menyimpan kembali.',
            'record_type' => $column['write_strategy'] === 'customer_field' ? 'customer' : 'consumer_application',
            'record_id' => $target->getKey(), 'expected' => $expected, 'current' => $current,
            'expected_updated_at' => $expected, 'current_updated_at' => $current, 'current_updated_label' => $target->updated_at?->copy()->locale('id')->translatedFormat('d M Y, H:i'),
            'current_value' => $column['key'] === 'phone' ? null : $target->{$field},
            'current_display' => $column['key'] === 'phone' ? $this->maskPhone($target->{$field}) : $this->display($target->{$field}, $column),
            'reload_url' => route('consumer-database.module', 'data-konsumen'),
        ], 409));
    }

    private function response(object $target, ConsumerApplication $application, array $column): array
    {
        $value = $target->{$column['write_target']['field']};

        return ['value' => $value, 'display' => $this->display($value, $column), 'write_token' => $this->token($target), 'application_updated_at' => $this->token($application), 'customer_updated_at' => $application->customer ? $this->token($application->customer) : null];
    }

    private function display(mixed $value, array $column): string
    {
        if ($column['key'] === 'status_cash') {
            return $value === null ? '—' : ($value ? 'Ya' : 'Tidak');
        }

        return filled($value) ? (string) $value : '—';
    }

    private function auditValue(string $column, mixed $value): mixed
    {
        return match ($column) {
            'phone' => ['masked' => $this->maskPhone($value)],
            'notes' => ['is_null' => $value === null, 'length' => mb_strlen((string) $value)],
            default => $value,
        };
    }

    private function maskPhone(mixed $value): ?string
    {
        $value = (string) $value;

        return $value === '' ? null : str_repeat('*', max(0, mb_strlen($value) - 4)).mb_substr($value, -4);
    }

    private function unprocessableIf(bool $condition, string $field, string $message): void
    {
        if ($condition) {
            $this->unprocessable($field, $message);
        }
    }

    private function unprocessable(string $field, string $message): never
    {
        throw new HttpResponseException(response()->json(['message' => $message, 'errors' => [$field => [$message]]], 422));
    }
}
