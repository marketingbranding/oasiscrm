<?php

namespace App\Http\Middleware;

use App\Enums\AccountStatus;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && $user->is_active !== $user->isAccountActive()) {
            DB::table('users')->where('id', $user->id)->update(['is_active' => $user->isAccountActive()]);
            $user->setAttribute('is_active', $user->isAccountActive());
            $user->syncOriginalAttribute('is_active');
        }

        if ($user && $user->account_status !== AccountStatus::Active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            $message = $user->account_status === AccountStatus::Suspended
                ? 'Akun Anda sedang ditangguhkan.'
                : 'Akun Anda sudah dinonaktifkan.';

            if ($request->expectsJson()) {
                return response()->json([
                    'ok' => false,
                    'code' => 'account_inactive',
                    'message' => $message,
                ], 403);
            }

            return redirect()->route('login')->withErrors(['email' => $message]);
        }

        return $next($request);
    }
}
