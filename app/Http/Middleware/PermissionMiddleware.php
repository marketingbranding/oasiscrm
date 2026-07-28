<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PermissionMiddleware
{
    public function handle(Request $request, Closure $next, string $permission, string ...$extraPermissions): Response
    {
        abort_if($extraPermissions !== [], 403, 'Middleware izin hanya menerima satu izin.');
        abort_unless($request->user()?->hasPermission($permission), 403, 'Anda tidak memiliki izin untuk tindakan ini.');

        return $next($request);
    }
}
