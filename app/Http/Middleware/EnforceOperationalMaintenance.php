<?php

namespace App\Http\Middleware;

use App\Services\OperationalMaintenanceService;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceOperationalMaintenance
{
    private const DEFAULT_MESSAGE = 'OASIS sedang dalam pemeliharaan.';

    public function __construct(private readonly OperationalMaintenanceService $maintenance) {}

    public function handle(Request $request, Closure $next): Response
    {
        $setting = $this->maintenance->activeSetting();

        if (! $setting || ($request->user() && $this->maintenance->canBypass($request->user()))) {
            return $next($request);
        }

        $publicData = $this->maintenance->publicData($setting);
        if (! $publicData['enabled']) {
            return $next($request);
        }

        $estimatedEndAt = $publicData['estimated_end_at'];
        $headers = [];
        $estimatedEnd = $estimatedEndAt ? CarbonImmutable::parse($estimatedEndAt) : null;

        if ($estimatedEnd) {
            $headers['Retry-After'] = (string) max(
                60,
                $estimatedEnd->getTimestamp() - now()->getTimestamp(),
            );
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => self::DEFAULT_MESSAGE,
                'maintenance' => true,
                'estimated_end_at' => $estimatedEndAt,
            ], Response::HTTP_SERVICE_UNAVAILABLE, $headers);
        }

        return response()->view('errors.operational-maintenance', [
            'title' => $publicData['title'] ?: 'Pemeliharaan OASIS',
            'message' => $publicData['message'] ?: self::DEFAULT_MESSAGE,
            'estimatedEndAt' => $estimatedEndAt,
            'estimatedEndLabel' => $estimatedEnd?->timezone(config('app.timezone'))->translatedFormat('d F Y, H.i T'),
        ], Response::HTTP_SERVICE_UNAVAILABLE, $headers);
    }
}
