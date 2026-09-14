<?php

namespace App\Http\Requests\Crm;

use App\Models\Branch;
use App\Models\LeadMaster;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && $this->user()->isSuperadmin();
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['project_name' => Str::squish($this->string('project_name')->toString())]);
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer'],
            'project_name' => ['required', 'string', 'max:255'],
            'sheet_project_name' => ['nullable', 'string', 'max:255'],
            'expected_updated_at' => ['required', 'string'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['branch_id', 'project_name'])) {
                return;
            }

            $branch = Branch::query()->where('is_active', true)->find($this->integer('branch_id'));
            if (! $branch) {
                $validator->errors()->add('branch_id', 'Cabang tujuan harus aktif.');

                return;
            }

            $project = $this->route('project');
            $projectId = $project instanceof LeadMaster ? $project->id : (int) $project;
            $name = mb_strtolower($this->string('project_name')->toString());
            $alias = blank($this->input('sheet_project_name'))
                ? null
                : mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $this->input('sheet_project_name'))) ?? '');

            if ($alias !== null && $alias !== '' && $alias === $name) {
                $validator->errors()->add('sheet_project_name', 'Identitas proyek spreadsheet tidak boleh sama dengan nama proyek.');

                return;
            }

            $candidates = LeadMaster::query()
                ->where('branch_id', $branch->id)
                ->where('is_active', true)
                ->whereKeyNot($projectId)
                ->get(['project_name', 'sheet_project_name']);

            foreach ($candidates as $other) {
                $otherName = mb_strtolower(Str::squish((string) $other->project_name));
                $otherAlias = blank($other->sheet_project_name)
                    ? null
                    : mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $other->sheet_project_name)) ?? '');

                if ($name !== '' && ($name === $otherName || $name === $otherAlias)) {
                    $validator->errors()->add('project_name', 'Nama proyek aktif sudah digunakan pada cabang terpilih.');

                    return;
                }
                if ($alias !== null && $alias !== '' && ($alias === $otherName || $alias === $otherAlias)) {
                    $validator->errors()->add('sheet_project_name', 'Identitas proyek aktif sudah digunakan pada cabang terpilih.');

                    return;
                }
            }
        }];
    }
}
