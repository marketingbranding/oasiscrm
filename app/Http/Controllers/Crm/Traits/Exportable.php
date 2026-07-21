<?php

namespace App\Http\Controllers\Crm\Traits;

use App\Services\WorkspaceAccessService;
use Illuminate\Support\Facades\Auth;

trait Exportable
{
    public function exportTemplate()
    {
        $class = $this->exportClass;

        return $class::generateTemplate(app(WorkspaceAccessService::class)->accessibleBranchIds(Auth::user()));
    }
}
