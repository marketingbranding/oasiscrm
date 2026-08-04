<?php

namespace App\Http\Requests\Crm;

use App\Enums\SalesLeadStatus;
use App\Exceptions\SalesLeadSpreadsheetContractException;
use App\Models\Branch;
use App\Models\LeadMaster;
use App\Models\SalesLead;
use App\Models\User;
use App\Services\SalesLeadSheetOptionService;
use App\Services\SalesSheetIdentityService;
use App\Services\WorkspaceAccessService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
            'phone' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'id_promo' => ['nullable', 'string', 'max:255'],
            'source' => ['required', 'string', 'max:255'],
            'platform' => ['required', 'string', 'max:255'],
            'campaign_id' => ['nullable', 'string', 'max:255'],
            'campaign_name' => ['required', 'string', 'max:255'],
            'current_status' => ['nullable', Rule::in(array_map(fn (SalesLeadStatus $status) => $status->value, SalesLeadStatus::MANUAL))],
            'linked_consumer_reference' => ['nullable', 'string', 'max:255'],
            'expected_updated_at' => ['sometimes', 'required', 'string', 'max:40'],
            'operation_uuid' => [$this->lead() ? 'sometimes' : 'required', 'uuid'],
        ];
    }

    public function messages(): array
    {
        return [
            'source.required' => 'Sumber lead spreadsheet wajib dipilih.',
            'platform.required' => 'Kanal masuk spreadsheet wajib dipilih.',
            'campaign_name.required' => 'Aktivitas lead spreadsheet wajib dipilih.',
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

            if (! $access->canViewBranch($user, $branchId)) {
                $validator->errors()->add('branch_id', 'Cabang tidak dapat diakses.');
            }
            if (! $project || ! $project->is_active || (int) $project->branch_id !== $branchId || ! $access->canAccessProject($user, $project)) {
                $validator->errors()->add('project_id', 'Proyek aktif harus berada di cabang yang dipilih dan dapat diakses.');
            }
            if (! $owner || ! $owner->is_active || ! $owner->isSales() || ! $access->canViewBranch($owner, $branchId)) {
                $validator->errors()->add('sales_user_id', 'Sales aktif harus berada di cabang yang dipilih.');
            } elseif (! $owner->assignedProjects()->whereKey($this->integer('project_id'))->exists()) {
                $validator->errors()->add('sales_user_id', 'Sales belum ditugaskan ke proyek yang dipilih.');
            }

            if ($user->isSales() && (int) $owner?->id !== (int) $user->id) {
                $validator->errors()->add('sales_user_id', 'Sales hanya dapat memilih dirinya sendiri.');
            }

            if ($this->lead()?->external_sync_id && (int) $this->lead()->branch_id !== $branchId) {
                $validator->errors()->add('branch_id', 'Lead yang sudah tersinkron tidak dapat dipindahkan ke spreadsheet cabang lain.');
            }

            if ($this->lead() && $this->filled('current_status')) {
                $current = $this->lead()->current_status;
                $target = SalesLeadStatus::fromInput($this->string('current_status')->toString());
                if (! $current->isManual()) {
                    $validator->errors()->add('current_status', 'Status sistem atau status yang lebih lanjut tidak dapat diubah melalui formulir lead.');
                }
            }

            if ($validator->errors()->hasAny(['branch_id', 'project_id', 'sales_user_id'])) {
                return;
            }

            $branch = Branch::find($branchId);
            if (! $branch || blank($branch->sheet_id)) {
                return;
            }
            try {
                $options = app(SalesLeadSheetOptionService::class)->forBranch($branch);
                $fields = [
                    'id_promo' => ['promo', 'ID Promo tidak tersedia pada pilihan spreadsheet cabang.'],
                    'source' => ['source', 'Sumber lead tidak tersedia pada pilihan spreadsheet cabang.'],
                    'platform' => ['channel', 'Kanal masuk tidak tersedia pada pilihan spreadsheet cabang.'],
                    'campaign_name' => ['activity', 'Aktivitas lead tidak tersedia pada pilihan spreadsheet cabang.'],
                ];
                foreach ($fields as $field => [$optionKey, $message]) {
                    $exact = $this->filled($field)
                        ? app(SalesLeadSheetOptionService::class)->exactOption($options[$optionKey], $this->string($field)->toString())
                        : null;
                    if ($this->filled($field) && $exact === null) {
                        $validator->errors()->add($field, $message);
                    } elseif ($exact !== null) {
                        // Persist the workbook's exact spelling/casing, not the browser-submitted variant.
                        $this->merge([$field => $exact]);
                        $validator->setData(array_replace($validator->getData(), [$field => $exact]));
                    }
                }
                app(SalesSheetIdentityService::class)->projectValue($project, $options);
                app(SalesSheetIdentityService::class)->salesValue($branch, $owner, $options);
            } catch (ValidationException $exception) {
                foreach ($exception->errors() as $field => $messages) {
                    foreach ($messages as $message) {
                        $validator->errors()->add($field, $message);
                    }
                }
            } catch (SalesLeadSpreadsheetContractException $exception) {
                $validator->errors()->add('spreadsheet', $exception->getMessage());
            }
        }];
    }

    protected function lead(): ?SalesLead
    {
        $lead = $this->route('sales_lead');

        return $lead instanceof SalesLead ? $lead : null;
    }
}
