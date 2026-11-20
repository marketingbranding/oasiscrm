<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\AppSetting;
use App\Models\User;
use App\Support\AppSettingCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class AppSettingService
{
    public function __construct(private readonly AppSettingResolver $resolver) {}

    /**
     * @return array<string, array{group:string,type:string,default:mixed,label:string,description:string,rules:array<int, mixed>,feature_flag?:bool,value:mixed}>
     */
    public function definitions(): array
    {
        return collect(AppSettingCatalog::definitions())
            ->map(fn (array $definition, string $key): array => [...$definition, 'key' => $key, 'value' => $this->resolver->value($key)])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function update(User $actor, array $values): void
    {
        $this->assertCanManage($actor);
        $normalized = $this->normalizeValues($values);

        DB::transaction(function () use ($actor, $normalized): void {
            $changedKeys = [];

            foreach ($normalized as $key => $value) {
                $definition = AppSettingCatalog::definition($key);
                $setting = AppSetting::query()->firstOrNew(['key' => $key]);
                $oldValue = $setting->exists ? $setting->value : $definition['default'];

                if ($oldValue === $value) {
                    continue;
                }

                $setting->fill([
                    'value' => $value,
                    'group' => $definition['group'],
                    'updated_by' => $actor->id,
                ])->save();

                ActivityLog::query()->create([
                    'causer_id' => $actor->id,
                    'subject_type' => AppSetting::class,
                    'subject_id' => $setting->id,
                    'event' => 'app_setting_updated',
                    'description' => "Pengaturan {$key} diperbarui.",
                    'properties' => [
                        'key' => $key,
                        'group' => $definition['group'],
                        'old_value' => $oldValue,
                        'new_value' => $value,
                        'actor_id' => $actor->id,
                    ],
                ]);

                $changedKeys[] = $key;
            }

            DB::afterCommit(function () use ($changedKeys): void {
                foreach ($changedKeys as $key) {
                    $this->resolver->forget($key);
                }
            });
        });
    }

    public function assertCanManage(User $actor): void
    {
        abort_unless(
            $actor->isAccountActive() && $actor->is_active && $actor->isSuperadmin() && $actor->hasPermission('system.settings_manage'),
            403,
            'Akses pengaturan aturan tidak diizinkan.',
        );
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, int|bool|string|array<mixed>>
     */
    private function normalizeValues(array $values): array
    {
        $unknown = array_diff(array_keys($values), array_keys(AppSettingCatalog::definitions()));
        if ($unknown !== []) {
            throw ValidationException::withMessages(
                collect($unknown)->mapWithKeys(fn (string $key): array => [$key => 'Pengaturan tidak dikenal.'])->all(),
            );
        }

        $normalized = [];
        foreach ($values as $key => $value) {
            $definition = AppSettingCatalog::definition($key);
            $normalizedValue = match ($definition['type']) {
                'integer' => is_int($value) || (is_string($value) && filter_var($value, FILTER_VALIDATE_INT) !== false)
                    ? (int) $value
                    : throw ValidationException::withMessages([$key => 'Nilai harus berupa bilangan bulat.']),
                'boolean' => match (true) {
                    is_bool($value) => $value,
                    $value === '0' || $value === 0 => false,
                    $value === '1' || $value === 1 => true,
                    default => throw ValidationException::withMessages([$key => 'Nilai harus berupa ya atau tidak.']),
                },
                'string' => is_string($value) ? $value : throw ValidationException::withMessages([$key => 'Nilai harus berupa teks.']),
                'list' => is_array($value) && array_is_list($value) ? $value : throw ValidationException::withMessages([$key => 'Nilai harus berupa daftar.']),
                default => throw new InvalidArgumentException("Unsupported application setting type [{$definition['type']}]."),
            };

            Validator::make(['value' => $normalizedValue], ['value' => $definition['rules']])->validate();
            $normalized[$key] = $normalizedValue;
        }

        return $normalized;
    }
}
