<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Services\SystemHealthService;

class SystemHealthController extends Controller
{
    public function __invoke(SystemHealthService $health)
    {
        return view('crm.system-health.index', ['sections' => $health->report()]);
    }
}
