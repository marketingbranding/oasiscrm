<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureOperationalModuleEnabled
{
    private const CONFIG_KEYS = [
        'database' => 'oasis.database_operational_enabled',
        'consumer_progress' => 'oasis.consumer_progress_operational_enabled',
        'spreadsheet_sync' => 'oasis.spreadsheet_sync_enabled',
    ];

    public function handle(Request $request, Closure $next, string $module): Response
    {
        $configKey = self::CONFIG_KEYS[$module] ?? null;

        if ($configKey === null || ! (bool) config($configKey)) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Not Found'], Response::HTTP_NOT_FOUND);
            }

            abort(Response::HTTP_NOT_FOUND);
        }

        return $next($request);
    }
}
