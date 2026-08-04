<?php

namespace App\Http\Requests\Crm;

class UpdateProjectRequest extends StoreProjectRequest
{
    public function authorize(): bool
    {
        return $this->user() && $this->user()->isSuperadmin();
    }
}
