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

    public function rules(): array
    {
        return collect(AppSettingCatalog::definitions())
            ->mapWithKeys(fn (array $definition, string $key): array => [str_replace('.', '\\.', $key) => [
                'sometimes',
                ...$definition['rules'],
            ]])
            ->all();
    }

    public function withValidator($validator): void
    {
        $knownKeys = array_keys(AppSettingCatalog::definitions());

        $validator->after(function ($validator) use ($knownKeys): void {
            foreach (array_keys($this->all()) as $key) {
                if (! in_array($key, $knownKeys, true) && $key !== '_token' && $key !== '_method') {
                    $validator->errors()->add($key, 'Pengaturan tidak dikenal.');
                }
            }
        });
    }
}
