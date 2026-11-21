<?php

namespace App\Http\Requests\Crm;

use App\Services\AppSettingResolver;
use App\Support\AppSettingCatalog;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAppSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperadmin() === true
            && $this->user()?->hasPermission('system.settings_manage') === true;
    }

    protected function prepareForValidation(): void
    {
        $settings = $this->input('settings', []);

        if (! is_array($settings)) {
            $settings = [];
        }

        foreach (['lead_stages_v3', 'transaction_events_v3'] as $key) {
            $modeKey = $key.'__mode';
            $branchIdsKey = $key.'__branch_ids';
            if (array_key_exists($modeKey, $settings)) {
                $settings[$key] = [
                    'mode' => $settings[$modeKey],
                    'branch_ids' => $settings[$branchIdsKey] ?? [],
                ];
                unset($settings[$modeKey], $settings[$branchIdsKey]);
            }
        }

        $settings = collect($settings)
            ->mapWithKeys(function (mixed $value, string $key): array {
                $decodedKey = $this->decodeKey($key);
                $definition = AppSettingCatalog::definitions()[$decodedKey] ?? null;

                if (($definition['type'] ?? null) === 'list' && is_string($value)) {
                    $value = array_values(array_filter(array_map('trim', preg_split('/\R/', $value) ?: [])));
                }

                return [$decodedKey => $value];
            })
            ->all();

        $this->merge(['settings' => $settings]);
    }

    private function decodeKey(string $key): string
    {
        return str_replace('__', '.', $key);
    }

    public function rules(): array
    {
        return [
            'settings' => ['array'],
            ...collect(AppSettingCatalog::definitions())
                ->mapWithKeys(fn (array $definition, string $key): array => ['settings.'.str_replace('.', '\\.', $key) => [
                    'sometimes',
                    ...$definition['rules'],
                ]])
                ->all(),
        ];
    }

    public function withValidator($validator): void
    {
        $knownKeys = array_keys(AppSettingCatalog::definitions());

        $validator->after(function ($validator) use ($knownKeys): void {
            foreach (array_keys($this->input('settings', [])) as $key) {
                $encodedKey = str_replace('.', '__', $key);
                $messages = $validator->errors()->get("settings.{$key}");

                foreach ($messages as $message) {
                    $validator->errors()->add("settings.{$encodedKey}", $message);
                }

                if (! in_array($key, $knownKeys, true)) {
                    $validator->errors()->add("settings.{$encodedKey}", 'Pengaturan tidak dikenal.');
                }
            }

            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $settings = app(AppSettingResolver::class);
            $submittedValues = $this->input('settings', []);
            $values = collect(array_keys(AppSettingCatalog::definitions()))
                ->mapWithKeys(fn (string $key): array => [$key => $submittedValues[$key] ?? $settings->value($key)])
                ->all();

            foreach (AppSettingCatalog::calendarValidationErrors($values) as $key => $messages) {
                foreach ($messages as $message) {
                    $encodedKey = str_replace('.', '__', $key);
                    $validator->errors()->add("settings.{$encodedKey}", $message);
                }
            }
        });
    }
}
