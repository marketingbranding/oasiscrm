<?php

namespace App\Http\Middleware;

use App\Services\ModuleMaintenanceService;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

class EnforceModuleMaintenance
{
    private const DEFAULT_MESSAGE = 'Modul ini sedang dalam pemeliharaan. Silakan coba kembali nanti.';

    public function __construct(private readonly ModuleMaintenanceService $maintenance) {}

    public function handle(Request $request, Closure $next, string $key): Response
    {
        try {
            $status = $this->maintenance->status($key);
        } catch (InvalidArgumentException) {
            abort(404);
        }

        if (! $status['is_enabled']) {
            return $next($request);
        }

        if ($this->maintenance->canBypass()) {
            $request->attributes->set('module_maintenance_context', $status);

            return $next($request);
        }

        $estimatedEnd = $status['estimated_end_at'] ? CarbonImmutable::parse($status['estimated_end_at']) : null;
        $headers = $estimatedEnd && $estimatedEnd->isFuture()
            ? ['Retry-After' => (string) max(60, $estimatedEnd->getTimestamp() - now()->getTimestamp())]
            : [];
        $message = $status['message'] ?: self::DEFAULT_MESSAGE;

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'maintenance' => true,
                'module' => $key,
                'module_label' => $status['module_label'],
                'estimated_end_at' => $status['estimated_end_at'],
            ], Response::HTTP_SERVICE_UNAVAILABLE, $headers);
        }

        return response()->view('errors.module-maintenance', [
            'moduleLabel' => $status['module_label'],
            'message' => $message,
            'estimatedEndAt' => $status['estimated_end_at'],
            'estimatedEndLabel' => $estimatedEnd?->timezone(config('app.timezone'))->translatedFormat('d F Y, H.i T'),
        ], Response::HTTP_SERVICE_UNAVAILABLE, $headers);
    }
}
