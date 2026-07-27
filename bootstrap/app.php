<?php

use App\Http\Middleware\CheckBranch;
use App\Http\Middleware\CheckRole;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\RestrictSalesModuleAccess;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectUsersTo(fn (Request $request) => route($request->user()?->landingRouteName() ?? 'dashboard'));
        $middleware->alias([
            'role' => CheckRole::class,
            'branch' => CheckBranch::class,
            'password.changed' => EnsurePasswordChanged::class,
            'active' => EnsureUserIsActive::class,
            'sales.access' => RestrictSalesModuleAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
