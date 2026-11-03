<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\SystemAdministrationAuthorizationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SystemAdministrationMiddleware
{
    public function __construct(private readonly SystemAdministrationAuthorizationService $authorization) {}

    public function handle(Request $request, Closure $next, string $area): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $this->authorization->assertCanManage($actor, $area);

        return $next($request);
    }
}
