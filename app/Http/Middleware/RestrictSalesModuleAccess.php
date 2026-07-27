<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class RestrictSalesModuleAccess
{
    private const ALLOWED_ROUTES = [
        'sales-pocketbook.*',
        'sales-leads.*',
        'sales-agendas.*',
        'sales-reminders.*',
        'content-calendar.*',
        'presence.*',
        'notifications.*',
        'feedback-reports.store',
        'feedback-reports.history',
        'feedback-reports.screenshot',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isSales()) {
            return $next($request);
        }

        $routeName = $request->route()?->getName();
        abort_unless($routeName && Str::is(self::ALLOWED_ROUTES, $routeName), 403);

        return $next($request);
    }
}
