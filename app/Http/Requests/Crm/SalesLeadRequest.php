<?php

namespace App\Http\Requests\Crm;

use App\Enums\SalesLeadStatus;
use App\Models\LeadMaster;
use App\Models\SalesLead;
use App\Models\User;
use App\Policies\SalesLeadPolicy;
use App\Services\CoordinatorLeadTeamService;
use App\Services\PromoOptionService;
use App\Services\SalesAgendaProjectResolver;
use App\Services\WorkspaceAccessService;
use App\Support\SalesLeadMasterData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

abstract class SalesLeadRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'project_id' => ['required', 'integer', 'exists:lead_master,id'],
            'sales_user_id' => ['required', 'integer', 'exists:users,id'],
            'lead_date' => ['required', 'date'],
            'customer_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'promo_name' => ['nullable', 'string', 'max:255', function (string $attribute, mixed $value, \Closure $fail): void {
                $project = LeadMaster::find($this->integer('project_id'));
                if (! $project || ! $this->date('lead_date') || ! app(PromoOptionService::class)->accepts(
                    (int) $project->branch_id,
                    $this->date('lead_date'),
                    $value,
                    $this->lead()?->id_promo,
                )) {
                    $fail('Promo tidak tersedia untuk proyek dan tanggal lead yang dipilih.');
                }
            }],
            'source' => [Rule::requiredIf(fn (): bool => ! $this->isQuickSalesCreate()), 'nullable', 'string', 'max:255', $this->masterRule(SalesLeadMasterData::SOURCES, 'source')],
            'platform' => [Rule::requiredIf(fn (): bool => ! $this->isQuickSalesCreate()), 'nullable', 'string', 'max:255', $this->masterRule(SalesLeadMasterData::CHANNELS, 'platform')],
            'campaign_id' => ['nullable', 'string', 'max:255'],
            'campaign_name' => [Rule::requiredIf(fn (): bool => ! $this->isQuickSalesCreate()), 'nullable', 'string', 'max:255', $this->masterRule(SalesLeadMasterData::ACTIVITIES, 'campaign_name')],
            'current_status' => ['nullable', Rule::in(array_map(fn (SalesLeadStatus $status) => $status->value, SalesLeadStatus::MANUAL))],
            'linked_consumer_reference' => ['nullable', 'string', 'max:255'],
            'expected_updated_at' => ['sometimes', 'required', 'string', 'max:40'],
            'operation_uuid' => [$this->lead() ? 'sometimes' : 'required', 'uuid'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $data = [];
        if (! $this->exists('promo_name') && $this->exists('id_promo')) {
            $data['promo_name'] = $this->input('id_promo');
        }
        if ($this->user()?->isSales()) {
            $data['sales_user_id'] = $this->user()->id;
            $data['branch_id'] = $this->user()->branch_id;
            $data['lead_date'] = today()->toDateString();
            $project = app(SalesAgendaProjectResolver::class)->resolve($this->user(), $data['lead_date']);
            $data['project_id'] = $project?->id;
        }
        if (! $this->lead() && ! $this->exists('current_status')) {
            $data['current_status'] = SalesLeadStatus::NoResponse->value;
        }
        if ($data !== []) {
            $this->merge($data);
        }
    }

    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        if (! $this->expectsJson()) {
            parent::failedValidation($validator);
        }

        throw new HttpResponseException(response()->json([
            'message' => $validator->errors()->first(),
            'errors' => $validator->errors(),
        ], 422));
    }

    public function messages(): array
    {
        return [
            'branch_id.required' => 'Cabang wajib dipilih.',
            'project_id.required' => 'Proyek wajib dipilih.',
            'sales_user_id.required' => 'Sales wajib dipilih.',
            'lead_date.required' => 'Tanggal lead wajib diisi.',
            'customer_name.required' => 'Nama calon konsumen wajib diisi.',
            'phone.required' => 'No. WhatsApp / Telepon wajib diisi untuk lead baru atau perubahan melalui formulir.',
            'current_status.required' => 'Status lead wajib dipilih.',
            'current_status.in' => 'Status sistem (UTJ, Cek SLIK, dan seterusnya) tidak dapat diisi dari formulir lead. Gunakan aksi siklus lead yang sesuai.',
            'source.required' => 'Sumber lead wajib dipilih.',
            'platform.required' => 'Kanal masuk wajib dipilih.',
            'campaign_name.required' => 'Aktivitas lead wajib dipilih.',
            'operation_uuid.required' => 'Identitas operasi lead tidak tersedia. Muat ulang formulir lalu coba lagi.',
            'operation_uuid.uuid' => 'Identitas operasi lead tidak valid. Muat ulang formulir lalu coba lagi.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->hasAny(['branch_id', 'project_id', 'sales_user_id'])) {
                return;
            }

            $user = $this->user();
            $access = app(WorkspaceAccessService::class);
            $branchId = (int) $this->input('branch_id');
            $project = LeadMaster::find($this->integer('project_id'));
            $owner = User::with('role')->find($this->integer('sales_user_id'));
            $historicalOwnerEdit = $this->lead() !== null
                && app(SalesLeadPolicy::class)->canEditHistoricalOwnedLead($user, $this->lead());

            if ($historicalOwnerEdit) {
                $this->validateHistoricalImmutableFields($validator, $this->lead());

                return;
            }

            if (! $access->canViewBranch($user, $branchId)) {
                $validator->errors()->add('branch_id', 'Cabang tidak dapat diakses.');
            }
            if (! $project || ! $project->is_active || (int) $project->branch_id !== $branchId || ! $access->canAccessProject($user, $project)) {
                $validator->errors()->add('project_id', 'Proyek aktif harus berada di cabang yang dipilih dan dapat diakses.');
            }
            $ownerCanViewBranch = $owner && ($access->canViewBranch($owner, $branchId)
                || ($historicalOwnerEdit && $access->canViewHistoricalBranch($owner, $branchId)));
            if (! $owner || ! $owner->is_active || ! $owner->isSales() || ! $ownerCanViewBranch) {
                $validator->errors()->add('sales_user_id', 'Sales aktif harus berada di cabang yang dipilih.');
            } elseif (! $owner->assignedProjects()
                ->whereKey($this->integer('project_id'))
                ->wherePivot('is_active', true)
                ->where(fn ($query) => $query->whereNull('project_user.assignment_start_date')->orWhereDate('project_user.assignment_start_date', '<=', today()))
                ->where(fn ($query) => $query->whereNull('project_user.assignment_end_date')->orWhereDate('project_user.assignment_end_date', '>=', today()))
                ->exists()) {
                $validator->errors()->add('sales_user_id', 'Sales belum memiliki penugasan aktif pada proyek yang dipilih.');
            }

            if ($user->hasPrimaryRole('sales_coordinator') && ! app(CoordinatorLeadTeamService::class)->contains($user, (int) $owner?->id)) {
                $validator->errors()->add('sales_user_id', 'Sales harus anggota aktif tim koordinator.');
            }

            if ($this->lead()?->last_synced_at && (int) $this->lead()->branch_id !== $branchId) {
                $validator->errors()->add('branch_id', 'Lead yang sudah tersinkron tidak dapat dipindahkan ke spreadsheet cabang lain.');
            }

        }];
    }

    private function validateHistoricalImmutableFields(Validator $validator, SalesLead $lead): void
    {
        $immutableFields = [
            'branch_id' => [(int) $lead->branch_id, 'Lead historis hanya dapat diedit pada cabang asal.'],
            'project_id' => [(int) $lead->project_id, 'Lead historis hanya dapat diedit pada proyek asal.'],
            'sales_user_id' => [(int) $lead->sales_user_id, 'Lead historis hanya dapat diedit tanpa mengganti Sales pemilik.'],
        ];

        foreach ($immutableFields as $field => [$originalValue, $message]) {
            if ($this->exists($field) && (int) $this->input($field) !== $originalValue) {
                $validator->errors()->add($field, $message);
            }
        }

        if (! $this->exists('current_status') || blank($this->input('current_status')) || $validator->errors()->has('current_status')) {
            return;
        }

        try {
            $submittedStatus = SalesLeadStatus::fromInput((string) $this->input('current_status'));
        } catch (\ValueError) {
            return;
        }

        if ($submittedStatus !== $lead->current_status) {
            $validator->errors()->add('current_status', 'Lead historis tidak dapat mengubah status melalui formulir edit.');
        }
    }

    private function masterRule(array $values, string $field): mixed
    {
        $current = $this->lead()?->{$field};

        return $this->lead() && $this->input($field) === $current ? Rule::in([$current]) : Rule::in($values);
    }

    private function isQuickSalesCreate(): bool
    {
        return $this->lead() === null && $this->user()?->isSales() === true;
    }

    protected function lead(): ?SalesLead
    {
        $lead = $this->route('sales_lead');

        return $lead instanceof SalesLead ? $lead : null;
    }
}
