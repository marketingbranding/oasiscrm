<?php

namespace App\Http\Middleware;

use App\Models\Permission;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AllPermissionsMiddleware
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        abort_if($permissions === [], 403, 'Middleware izin memerlukan setidaknya satu izin.');
        abort_if(collect($permissions)->contains(fn (string $permission) => ! Permission::isRegistered($permission)), 403, 'Middleware memuat izin yang tidak terdaftar.');
        abort_unless($request->user()?->hasAllPermissions($permissions), 403, 'Anda tidak memiliki seluruh izin yang diperlukan untuk tindakan ini.');

        return $next($request);
    }
}
