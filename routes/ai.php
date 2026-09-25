<?php

use App\Http\Controllers\Mcp\RevokeOAuthTokenController;
use App\Http\Middleware\ResolveAgent;
use App\Mcp\Servers\MelfinaServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Http\Controllers\OAuthRegisterController;

Mcp::oauthRoutes();

// DCR is open registration — cap it so the clients table can't be spammed.
// Re-binding the same URI overrides the unthrottled route oauthRoutes() registered.
Route::post('oauth/register', OAuthRegisterController::class)->middleware('throttle:20,1');

Route::post('oauth/revoke', RevokeOAuthTokenController::class)->name('oauth.revoke');

Mcp::web('/mcp', MelfinaServer::class)->middleware(ResolveAgent::class);
