<?php

use App\Http\Controllers\Api\EnrollController;
use App\Http\Controllers\Api\FileController;
use App\Http\Controllers\Mcp\PluginRepoController;
use App\Http\Controllers\Mcp\RevokeOAuthTokenController;
use App\Http\Controllers\Mcp\ToolCallController;
use App\Http\Middleware\MergeMcpRedirectDomains;
use App\Http\Middleware\ResolveAgent;
use App\Http\Middleware\ResolveApiAgent;
use App\Mcp\Servers\MelfinaServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Http\Controllers\OAuthRegisterController;

Mcp::oauthRoutes();

// DCR is open registration — cap it so the clients table can't be spammed,
// and MergeMcpRedirectDomains unions the owner's console-managed origins
// into config('mcp.redirect_domains') before DCR validation reads it.
// Re-binding the same URI overrides the route oauthRoutes() registered.
Route::post('oauth/register', OAuthRegisterController::class)
    ->middleware(['throttle:20,1', MergeMcpRedirectDomains::class]);

Route::post('oauth/revoke', RevokeOAuthTokenController::class)->name('oauth.revoke');

Mcp::web('/mcp', MelfinaServer::class)->middleware(ResolveAgent::class);

// The MCP-first HTTP fallback: every catalogued tool is also callable as
// plain JSON for runtimes that can't install an MCP server. The request
// body is the tool's arguments object (same shape as MCP
// tools/call params.arguments); GET lists the surface. Dedicated agent
// keys, scoped API keys (melfs_…), and fleet keys all authenticate.
Route::get('api/tools', [ToolCallController::class, 'index'])
    ->middleware(['throttle:60,1', ResolveApiAgent::class])
    ->name('api.tools.index');
Route::post('api/tools/{tool}', ToolCallController::class)
    ->middleware(['throttle:60,1', ResolveApiAgent::class])
    ->where('tool', '[a-zA-Z0-9_-]+')
    ->name('api.tools.call');

// Raw file bodies don't belong in JSON tool arguments — these endpoints
// take the request body verbatim (PUT) and return it verbatim (GET), same
// key auth and scopes (files:read/files:write, shared:read/shared:write)
// as the matching tools. UTF-8 text stays text; everything else stores as
// a blob with a sniffed mime.
Route::put('api/files/{path}', [FileController::class, 'update'])
    ->middleware(['throttle:60,1', ResolveApiAgent::class])
    ->where('path', '.*')
    ->name('api.files.update');
Route::get('api/files/{path}', [FileController::class, 'show'])
    ->middleware(['throttle:60,1', ResolveApiAgent::class])
    ->where('path', '.*')
    ->name('api.files.show');
Route::put('api/shared/{space}/files/{path}', [FileController::class, 'updateShared'])
    ->middleware(['throttle:60,1', ResolveApiAgent::class])
    ->where(['space' => '[^/]+', 'path' => '.*'])
    ->name('api.shared.files.update');
Route::get('api/shared/{space}/files/{path}', [FileController::class, 'showShared'])
    ->middleware(['throttle:60,1', ResolveApiAgent::class])
    ->where(['space' => '[^/]+', 'path' => '.*'])
    ->name('api.shared.files.show');

// Self-enrollment: a runtime with no secret storage reports in with a
// named enroll key (`melfe_…`) plus its own id + claimed name. The owner
// accepts in the console; every subsequent accepted call mints a fresh
// 24h scoped key. Tightly throttled — the endpoint is unauthenticated.
Route::post('api/enroll', EnrollController::class)
    ->middleware('throttle:10,1')
    ->name('api.enroll');

// Per-agent plugin repositories, cloneable over git's dumb HTTP protocol.
// Credentials ride in HTTP Basic auth (userinfo in the clone URL — git
// converts it to an Authorization header), never in the logged path; the
// {repo} segment is decorative, except the reserved ~melfina.git which
// serves the persona-free fleet package (~ can never appear in an agent
// slug, so it can't collide).
Route::get('plugins/{repo}/{path?}', [PluginRepoController::class, 'show'])
    ->middleware('throttle:60,1')
    ->where([
        'repo' => '[^/]+\.git',
        'path' => '.*',
    ]);
