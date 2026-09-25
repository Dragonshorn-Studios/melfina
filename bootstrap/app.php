<?php

use App\Http\Middleware\EnsureUserIsOwner;
use App\Http\Middleware\PersistMcpConsent;
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
        $middleware->trustProxies(at: env('TRUSTED_PROXIES', '*'));
        $middleware->validateCsrfTokens(except: [
            'mcp',
            'oauth/register',
            'oauth/token',
            'oauth/token/refresh',
            'oauth/revoke',
        ]);
        $middleware->appendToGroup('web', [
            PersistMcpConsent::class,
        ]);
        $middleware->alias([
            'owner' => EnsureUserIsOwner::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->is('mcp') || $request->expectsJson(),
        );
    })->create();
