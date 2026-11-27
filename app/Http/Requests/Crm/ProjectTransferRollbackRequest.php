<?php

namespace App\Http\Requests\Crm;

use Illuminate\Foundation\Http\FormRequest;

class ProjectTransferRollbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperadmin() === true && ! (bool) session('impersonating_user_id');
    }

    public function rules(): array
    {
        return ['confirmation' => ['required', 'string', 'max:255']];
    }
}
