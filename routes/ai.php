<?php

use App\Http\Controllers\Mcp\PluginRepoController;
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

// Per-agent plugin repositories, cloneable over git's dumb HTTP protocol.
// The credentials ride in the path: /plugins/{fleet}/{agent}.git for the
// fleet form, /plugins/{agent}.git when no fleet key is in play.
Route::get('plugins/{fleet}/{repo}/{path?}', [PluginRepoController::class, 'showFleet'])
    ->where([
        'fleet' => 'melfg_[A-Za-z0-9]+',
        'repo' => 'melf_[A-Za-z0-9]+\.git',
        'path' => '.*',
    ]);

Route::get('plugins/{repo}/{path?}', [PluginRepoController::class, 'show'])
    ->where([
        'repo' => 'melf_[A-Za-z0-9]+\.git',
        'path' => '.*',
    ]);
