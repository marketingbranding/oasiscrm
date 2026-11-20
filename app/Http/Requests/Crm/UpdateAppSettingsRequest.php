<?php

namespace App\Http\Requests\Crm;

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

        $this->merge([
            'settings' => collect($settings)
                ->mapWithKeys(fn (mixed $value, string $key): array => [$this->decodeKey($key) => $value])
                ->all(),
        ]);
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
        });
    }
}
