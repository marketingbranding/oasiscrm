<?php

namespace App\Http\Requests\Crm;

use App\Models\Promo;
use App\Services\PromoAccessService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UpdatePromoRequest extends FormRequest
{
    public function authorize(): bool
    {
        $promo = $this->route('promo');

        $branchId = (int) $this->input('branch_id');

        return $promo instanceof Promo
            && $this->user()->can('update', $promo)
            && ($this->user()->isSuperadmin() || $branchId === (int) $promo->branch_id)
            && (! $this->user()->hasPrimaryRole('admin') || $branchId === (int) $this->user()->branch_id)
            && app(PromoAccessService::class)->canManageBranch($this->user(), $branchId);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => Str::of((string) $this->input('code'))->trim()->upper()->replaceMatches('/\s+/', '-')->toString(),
            'name' => Str::squish((string) $this->input('name')),
            'description' => filled($this->input('description')) ? trim((string) $this->input('description')) : null,
            'is_active' => $this->exists('is_active') ? $this->boolean('is_active') : (bool) $this->route('promo')->is_active,
        ]);
    }

    public function rules(): array
    {
        $promo = $this->route('promo');

        return [
            'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')->where('is_active', true)],
            'code' => ['required', 'string', 'max:100', 'regex:/^[A-Z0-9][A-Z0-9._-]*$/', Rule::unique('promos', 'code')->where('branch_id', $this->integer('branch_id'))->ignore($promo)],
            'name' => ['required', 'string', 'max:255'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
