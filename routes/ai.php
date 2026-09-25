<?php

use App\Http\Controllers\Mcp\RevokeOAuthTokenController;
use App\Http\Middleware\ResolveAgent;
use App\Mcp\Servers\MelfinaServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

Mcp::oauthRoutes();

Route::post('oauth/revoke', RevokeOAuthTokenController::class)->name('oauth.revoke');

Mcp::web('/mcp', MelfinaServer::class)->middleware(ResolveAgent::class);
