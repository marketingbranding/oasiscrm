<?php

namespace App\Http\Middleware;

use App\Services\ImpersonationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RejectImpersonatedRequest
{
    private const MESSAGE = 'Tindakan ini tidak tersedia selama sesi impersonasi aktif.';

    public function __construct(private readonly ImpersonationService $impersonation) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->impersonation->isActive($request)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => self::MESSAGE], Response::HTTP_FORBIDDEN);
        }

        abort(Response::HTTP_FORBIDDEN, self::MESSAGE);
    }
}
