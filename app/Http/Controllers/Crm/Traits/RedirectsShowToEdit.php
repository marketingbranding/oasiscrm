<?php

namespace App\Http\Controllers\Crm\Traits;

trait RedirectsShowToEdit
{
    public function show($model)
    {
        return redirect()->route($this->showEditRoute, [$this->showEditParam => $model->id]);
    }
}
