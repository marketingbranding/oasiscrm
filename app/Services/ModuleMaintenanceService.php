<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\ModuleMaintenance;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

class ModuleMaintenanceService
{
    public function availableModules(): array
    {
        return config('oasis_modules', []);
    }

    public function status(string $key): array
    {
        $this->module($key);

        return $this->statuses()[$key];
    }

    public function statuses(): array
    {
        $modules = $this->availableModules();

        try {
            return Cache::rememberForever($this->cacheKey('all'), function () use ($modules) {
                $maintenances = ModuleMaintenance::query()
                    ->with('updatedBy:id,name')
                    ->whereIn('module_key', array_keys($modules))
                    ->get()
                    ->keyBy('module_key');

                return collect($modules)->mapWithKeys(function (array $module, string $key) use ($maintenances) {
                    $maintenance = $maintenances->get($key);

                    return [$key => [
                        'module_key' => $key,
                        'module_label' => $module['label'],
                        'module_description' => $module['description'] ?? null,
                        'is_enabled' => (bool) $maintenance?->is_enabled,
                        'message' => $maintenance?->message,
                        'estimated_end_at' => $maintenance?->estimated_end_at?->toIso8601String(),
                        'started_at' => $maintenance?->started_at?->toIso8601String(),
                        'ended_at' => $maintenance?->ended_at?->toIso8601String(),
                        'updated_at' => $maintenance?->updated_at?->toIso8601String(),
                        'updated_by' => $maintenance?->updatedBy?->name,
                    ]];
                })->all();
            });
        } catch (Throwable $exception) {
            Log::error('Module maintenance states could not be read; access remains open.', [
                'exception' => $exception::class,
            ]);

            return collect($modules)->mapWithKeys(fn (array $module, string $key) => [
                $key => $this->openSnapshot($key, $module['label']),
            ])->all();
        }
    }

    public function enabledMap(): array
    {
        return collect($this->statuses())->map(fn (array $status) => $status['is_enabled'])->all();
    }

    public function current(string $key): array
    {
        return $this->status($key);
    }

    public function isUnderMaintenance(string $key): bool
    {
        return $this->status($key)['is_enabled'];
    }

    public function canBypass(): bool
    {
        return Auth::user()?->isSuperadmin() === true;
    }

    public function enable(string $key, User $actor, array $values): ModuleMaintenance
    {
        return $this->mutate($key, $actor, $values, true, 'module_maintenance_enabled');
    }

    public function update(string $key, User $actor, array $values): ModuleMaintenance
    {
        return $this->mutate($key, $actor, $values, null, 'module_maintenance_updated');
    }

    public function disable(string $key, User $actor): ModuleMaintenance
    {
        $this->module($key);
        $this->assertPrimarySuperadmin($actor);

        $maintenance = DB::transaction(function () use ($key, $actor) {
            $maintenance = ModuleMaintenance::query()->where('module_key', $key)->lockForUpdate()->firstOrNew(['module_key' => $key]);
            if (! $maintenance->is_enabled) {
                throw ValidationException::withMessages([
                    'module' => 'Pemeliharaan modul sudah tidak aktif.',
                ]);
            }

            $maintenance->fill([
                'is_enabled' => false,
                'estimated_end_at' => null,
                'ended_at' => now(),
                'updated_by' => $actor->id,
            ])->save();
            $this->recordActivity('module_maintenance_disabled', $maintenance, $actor);

            return $maintenance;
        }, 3);

        $this->forget($key);

        return $maintenance;
    }

    private function mutate(string $key, User $actor, array $values, ?bool $enable, string $event): ModuleMaintenance
    {
        $this->module($key);
        $this->assertPrimarySuperadmin($actor);
        $configuration = $this->configuration($values, $enable === true);

        $maintenance = DB::transaction(function () use ($key, $actor, $configuration, $enable, $event) {
            $maintenance = ModuleMaintenance::query()->where('module_key', $key)->lockForUpdate()->firstOrNew(['module_key' => $key]);
            $wasEnabled = (bool) $maintenance->is_enabled;
            if ($enable === true && $wasEnabled) {
                throw ValidationException::withMessages([
                    'module' => 'Pemeliharaan modul sudah aktif.',
                ]);
            }
            if ($enable === null && ! $wasEnabled) {
                throw ValidationException::withMessages([
                    'module' => 'Pemeliharaan modul tidak aktif dan tidak dapat diperbarui.',
                ]);
            }

            $maintenance->fill([
                ...$configuration,
                'is_enabled' => $enable ?? $wasEnabled,
                'updated_by' => $actor->id,
            ]);

            if ($enable === true && ! $wasEnabled) {
                $maintenance->started_at = now();
                $maintenance->started_by = $actor->id;
                $maintenance->ended_at = null;
            }

            $maintenance->save();
            $this->recordActivity($event, $maintenance, $actor);

            return $maintenance;
        }, 3);

        $this->forget($key);

        return $maintenance;
    }

    private function configuration(array $values, bool $futureEstimateRequired): array
    {
        $message = trim((string) ($values['message'] ?? ''));
        if (mb_strlen($message) > 1000) {
            throw new InvalidArgumentException('Module maintenance message may not exceed 1000 characters.');
        }

        $estimatedEndAt = filled($values['estimated_end_at'] ?? null)
            ? CarbonImmutable::parse($values['estimated_end_at'])
            : null;
        if ($futureEstimateRequired && $estimatedEndAt?->isPast()) {
            throw new InvalidArgumentException('Perkiraan selesai harus berada di masa mendatang.');
        }

        return [
            'message' => $message === '' ? null : $message,
            'estimated_end_at' => $estimatedEndAt,
        ];
    }

    private function assertPrimarySuperadmin(User $actor): void
    {
        $actor->loadMissing('role');
        if (! $actor->isSuperadmin()) {
            throw new AuthorizationException('Hanya Super Admin utama yang dapat mengelola pemeliharaan modul.');
        }
    }

    private function module(string $key): array
    {
        $module = $this->availableModules()[$key] ?? null;
        if (! is_array($module)) {
            throw new InvalidArgumentException("Unknown OASIS module [{$key}].");
        }

        return $module;
    }

    private function cacheKey(string $key): string
    {
        return "oasis.module_maintenance.{$key}";
    }

    private function forget(string $key): void
    {
        Cache::forget($this->cacheKey($key));
        Cache::forget($this->cacheKey('all'));
    }

    private function openSnapshot(string $key, string $label): array
    {
        return ['module_key' => $key, 'module_label' => $label, 'is_enabled' => false, 'message' => null, 'estimated_end_at' => null, 'started_at' => null, 'ended_at' => null];
    }

    private function recordActivity(string $event, ModuleMaintenance $maintenance, User $actor): void
    {
        ActivityLog::create([
            'causer_id' => $actor->id,
            'subject_type' => ModuleMaintenance::class,
            'subject_id' => $maintenance->id,
            'event' => $event,
            'description' => "Pemeliharaan modul {$maintenance->module_key} diperbarui",
            'properties' => [
                'module_key' => $maintenance->module_key,
                'module_label' => $this->module($maintenance->module_key)['label'],
                'is_enabled' => $maintenance->is_enabled,
                'message' => $maintenance->message,
                'estimated_end_at' => $maintenance->estimated_end_at?->toIso8601String(),
                'actor_id' => $actor->id,
            ],
        ]);
    }
}
