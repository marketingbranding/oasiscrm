<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureWorkspaceV2Enabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('app.workspace_v2_enabled'), Response::HTTP_NOT_FOUND);

        return $next($request);
    }
}
