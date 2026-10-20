<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceCrmReadOnly
{
    private const MESSAGE = 'OASIS CRM telah dibekukan. Data lama masih dapat dilihat, tetapi penambahan dan perubahan data sudah ditutup. Gunakan OASIS Next untuk aktivitas operasional baru.';

    /** @var list<string> */
    private const ALLOWED_INTERNAL_WRITES = [
        'notifications.read',
        'notifications.read-all',
        'presence.heartbeat',
        'presence.destroy',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('app.read_only') || $this->isAllowedRequest($request)) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->isJson()) {
            return response()->json([
                'message' => self::MESSAGE,
                'read_only' => true,
            ], Response::HTTP_LOCKED);
        }

        return redirect()->back()->with('warning', self::MESSAGE);
    }

    private function isAllowedRequest(Request $request): bool
    {
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return true;
        }

        return in_array((string) $request->route()?->getName(), self::ALLOWED_INTERNAL_WRITES, true);
    }
}
