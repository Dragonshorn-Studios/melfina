<?php

use App\Http\Controllers\Mcp\PluginRepoController;
use App\Http\Controllers\Mcp\RevokeOAuthTokenController;
use App\Http\Middleware\MergeMcpRedirectDomains;
use App\Http\Middleware\ResolveAgent;
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
