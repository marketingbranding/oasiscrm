<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ImpersonationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ImpersonationController extends Controller
{
    public function __construct(private readonly ImpersonationService $impersonation) {}

    public function start(Request $request, User $target): RedirectResponse
    {
        $this->impersonation->start($request, $target);

        return redirect()->route($target->landingRouteName());
    }

    public function stop(Request $request): RedirectResponse
    {
        $original = $this->impersonation->stop($request);

        return $original
            ? redirect()->route('admin-users.index')
            : redirect()->route('login');
    }
}
