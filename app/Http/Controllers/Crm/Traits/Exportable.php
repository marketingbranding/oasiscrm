<?php

namespace App\Http\Controllers\Crm\Traits;

trait Exportable
{
    public function exportTemplate()
    {
        $class = $this->exportClass;
        return $class::generateTemplate();
    }
}
