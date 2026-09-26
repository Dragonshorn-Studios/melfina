<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredOwnerController;
use App\Http\Controllers\Console\AgentController;
use App\Http\Controllers\Console\DashboardController;
use App\Http\Controllers\Console\FileController;
use App\Http\Controllers\Console\InstructionController;
use App\Http\Controllers\Console\McpClientController;
use App\Http\Controllers\Console\McpKeyController;
use App\Http\Controllers\Console\MessageController;
use App\Http\Controllers\Console\SharedSpaceController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('guest')->group(function () {
    Route::get('register', [RegisteredOwnerController::class, 'create'])->name('register');
    Route::post('register', [RegisteredOwnerController::class, 'store']);

    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:5,1');
});

Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

Route::middleware(['auth', 'owner'])->prefix('console')->name('console.')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');

    Route::post('mcp-key', [McpKeyController::class, 'store'])->name('mcp-key.store');
    Route::delete('mcp-keys/{fleetKey}', [McpKeyController::class, 'destroy'])->name('mcp-key.destroy');
    Route::get('mcp', [McpKeyController::class, 'connect'])->name('mcp.connect');
    Route::get('mcp/plugin.zip', [McpKeyController::class, 'pluginZip'])->name('mcp.plugin.zip');
    Route::post('mcp/redirect-domains', [McpKeyController::class, 'storeRedirectDomain'])->name('mcp.redirect-domains.store');
    Route::delete('mcp/redirect-domains', [McpKeyController::class, 'destroyRedirectDomain'])->name('mcp.redirect-domains.destroy');
    Route::post('mcp/github', [McpKeyController::class, 'storeGitHub'])->name('mcp.github.store');
    Route::post('mcp/github/publish', [McpKeyController::class, 'publishGitHub'])->name('mcp.github.publish');
    Route::put('mcp/operator-persona', [McpKeyController::class, 'storeOperatorPersona'])->name('mcp.operator-persona.store');

    Route::post('agents/{agent}/rotate', [AgentController::class, 'rotate'])->name('agents.rotate');
    Route::get('agents/{agent}/persona', [AgentController::class, 'editPersona'])->name('agents.persona.edit');
    Route::put('agents/{agent}/persona', [AgentController::class, 'updatePersona'])->name('agents.persona.update');
    Route::delete('agents/{agent}/memories/{index}', [AgentController::class, 'destroyMemory'])->name('agents.memories.destroy');
    Route::get('agents/{agent}/connect', [AgentController::class, 'connect'])->name('agents.connect');
    Route::get('agents/{agent}/plugin.zip', [AgentController::class, 'pluginZip'])->name('agents.plugin');
    Route::resource('agents', AgentController::class)->except(['destroy']);

    Route::get('instructions', [InstructionController::class, 'index'])->name('instructions.index');
    Route::get('agents/{agent}/instructions', [InstructionController::class, 'edit'])->name('agents.instructions.edit');
    Route::put('agents/{agent}/instructions', [InstructionController::class, 'update'])->name('agents.instructions.update');

    Route::get('files', [FileController::class, 'index'])->name('files.index');
    Route::get('files/{agent}', [FileController::class, 'show'])->name('files.show');
    Route::get('files/{agent}/edit', [FileController::class, 'edit'])->name('files.edit');
    Route::put('files/{agent}', [FileController::class, 'update'])->name('files.update');
    Route::delete('files/{agent}', [FileController::class, 'destroy'])->name('files.destroy');

    Route::get('shared/{shared}/file', [SharedSpaceController::class, 'file'])->name('shared.file');
    Route::resource('shared', SharedSpaceController::class)->except(['destroy']);

    Route::get('messages', [MessageController::class, 'index'])->name('messages.index');
    Route::get('messages/{message}', [MessageController::class, 'show'])->name('messages.show');

    Route::get('clients', [McpClientController::class, 'index'])->name('clients.index');
    Route::delete('clients/{client}', [McpClientController::class, 'destroy'])->name('clients.destroy');
});
