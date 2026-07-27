<?php

namespace App\Http\Requests\Crm;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class RescheduleSalesAgendaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'scheduled_date' => ['required', 'date'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'expected_updated_at' => ['required', 'string', 'max:40'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->hasAny(['start_time', 'end_time'])) {
                return;
            }
            if (Carbon::createFromFormat('H:i', $this->input('end_time'))->lte(Carbon::createFromFormat('H:i', $this->input('start_time')))) {
                $validator->errors()->add('end_time', 'Jam selesai harus setelah jam mulai.');
            }
        }];
    }
}
