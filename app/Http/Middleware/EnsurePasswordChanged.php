<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || $user->password_changed_at) {
            return $next($request);
        }

        if ($request->routeIs('password.change', 'password.change.update', 'logout')) {
            return $next($request);
        }

        return redirect()->route('password.change');
    }
}
