<?php

namespace App\Http\Middleware;

use App\Services\SchemaStatus;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Holds the owner at the console setup screen, and answers MCP/API with
 * 503, until Laravel's schema matches the migration files on disk.
 * Login, logout, registration, and /up stay reachable so the owner can
 * get in and fix it.
 */
class EnsureSchemaIsCurrent
{
    public function __construct(private SchemaStatus $schema) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs('console.setup.*') || $this->schema->isCurrent()) {
            return $next($request);
        }

        if ($this->isAgentSurface($request)) {
            return response()->json([
                'ok' => false,
                'error' => 'schema outdated',
                'message' => 'Pending database migrations. Sign in as owner and run them from the console.',
            ], 503);
        }

        return redirect()->route('console.setup.show');
    }

    private function isAgentSurface(Request $request): bool
    {
        return $request->is('mcp')
            || $request->is('api/*')
            || $request->is('plugins/*');
    }
}
