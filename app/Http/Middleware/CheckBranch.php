<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckBranch
{
    public function handle(Request $request, Closure $next, string ...$branches): Response
    {
        $user = $request->user();

        if (!$user) {
            return redirect()->route('login');
        }

        foreach ($branches as $branch) {
            if ($user->hasBranch($branch)) {
                return $next($request);
            }
        }

        abort(403, 'Unauthorized branch access.');
    }
}
