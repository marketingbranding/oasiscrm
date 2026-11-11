<?php

namespace App\Http\Requests\Crm;

use App\Models\ContentItem;
use App\Support\SalesAgendaEvidenceRules;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSalesAgendaRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->user()?->isSales() === true) {
            return $this->user()->can('createSalesAgenda', ContentItem::class);
        }

        return $this->user()?->hasPrimaryRole('sales_coordinator') === true
            && $this->user()->hasPermission('sales_pocketbook.manage_team');
    }

    public function rules(): array
    {
        return [
            'sales_user_id' => [Rule::requiredIf(fn (): bool => $this->user()?->hasPrimaryRole('sales_coordinator') === true), 'nullable', 'integer', 'exists:users,id'],
            'project_id' => [Rule::requiredIf(fn (): bool => $this->user()?->hasPrimaryRole('sales_coordinator') === true), 'nullable', 'integer', 'exists:lead_master,id'],
            'scheduled_date' => ['required', 'date'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'sales_activity_category' => ['required', 'string', Rule::in(ContentItem::SALES_ACTIVITY_CATEGORIES)],
            'title' => ['required', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'activity_result' => ['nullable', 'string'],
            'photos' => ['nullable', 'array', 'max:'.SalesAgendaEvidenceRules::MAX_FILES],
            'photos.*' => ['required', 'file', 'max:'.intdiv(SalesAgendaEvidenceRules::MAX_BYTES, 1024)],
        ];
    }

    public function messages(): array
    {
        return [
            'scheduled_date.required' => 'Tanggal agenda wajib diisi.',
            'scheduled_date.date' => 'Tanggal agenda harus berupa tanggal yang valid.',
            'start_time.required' => 'Waktu mulai agenda wajib diisi.',
            'start_time.date_format' => 'Waktu mulai harus menggunakan format jam yang valid.',
            'end_time.required' => 'Waktu selesai agenda wajib diisi.',
            'end_time.date_format' => 'Waktu selesai harus menggunakan format jam yang valid.',
            'end_time.after' => 'Waktu selesai harus setelah waktu mulai.',
            'sales_activity_category.required' => 'Kategori aktivitas Sales wajib dipilih.',
            'sales_activity_category.in' => 'Kategori aktivitas Sales tidak dikenali.',
            'title.required' => 'Judul agenda wajib diisi.',
            'title.max' => 'Judul agenda maksimal 255 karakter.',
            'photos.max' => 'Maksimal 2 foto dapat diunggah.',
            'photos.*.file' => 'Bukti foto harus berupa file yang valid.',
            'photos.*.max' => 'Ukuran setiap bukti foto terlalu besar.',
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $start = CarbonImmutable::createFromFormat('H:i', (string) $this->input('start_time'));
                $end = CarbonImmutable::createFromFormat('H:i', (string) $this->input('end_time'));
                $duration = $start->diffInMinutes($end);

                if ($duration < 5 || $duration % 5 !== 0) {
                    $validator->errors()->add('end_time', 'Durasi agenda harus minimal 5 menit dan kelipatan 5 menit.');
                }
            },
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if (filled($this->input('activity_result')) && blank($this->file('photos'))) {
                    $validator->errors()->add('photos', SalesAgendaEvidenceRules::PHOTO_REQUIRED_MESSAGE);
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'start_time' => filled($this->input('start_time')) ? substr((string) $this->input('start_time'), 0, 5) : null,
            'end_time' => filled($this->input('end_time')) ? substr((string) $this->input('end_time'), 0, 5) : null,
        ]);
    }
}
