<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredOwnerController;
use App\Http\Controllers\Console\AgentController;
use App\Http\Controllers\Console\DashboardController;
use App\Http\Controllers\Console\EnrollKeyController;
use App\Http\Controllers\Console\EnrollmentController;
use App\Http\Controllers\Console\FileController;
use App\Http\Controllers\Console\InstructionController;
use App\Http\Controllers\Console\KnowledgeController;
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
    Route::post('enroll-keys', [EnrollKeyController::class, 'store'])->name('enroll-keys.store');
    Route::delete('enroll-keys/{enrollKey}', [EnrollKeyController::class, 'destroy'])->name('enroll-keys.destroy');
    Route::post('enrollments/{enrollment}/accept', [EnrollmentController::class, 'accept'])->name('enrollments.accept');
    Route::post('enrollments/{enrollment}/revoke', [EnrollmentController::class, 'revoke'])->name('enrollments.revoke');
    Route::delete('mcp-keys/{fleetKey}', [McpKeyController::class, 'destroy'])->name('mcp-key.destroy');
    Route::get('mcp', [McpKeyController::class, 'connect'])->name('mcp.connect');
    Route::get('mcp/plugin.zip', [McpKeyController::class, 'pluginZip'])->name('mcp.plugin.zip');
    Route::post('mcp/redirect-domains', [McpKeyController::class, 'storeRedirectDomain'])->name('mcp.redirect-domains.store');
    Route::delete('mcp/redirect-domains', [McpKeyController::class, 'destroyRedirectDomain'])->name('mcp.redirect-domains.destroy');
    Route::post('mcp/github', [McpKeyController::class, 'storeGitHub'])->name('mcp.github.store');
    Route::post('mcp/github/publish', [McpKeyController::class, 'publishGitHub'])->name('mcp.github.publish');
    Route::put('mcp/operator-persona', [McpKeyController::class, 'storeOperatorPersona'])->name('mcp.operator-persona.store');

    Route::post('agents/{agent}/rotate', [AgentController::class, 'rotate'])->name('agents.rotate');
    Route::post('agents/{agent}/api-keys', [AgentController::class, 'storeApiKey'])->name('agents.api-keys.store');
    Route::delete('agents/{agent}/api-keys/{agentApiKey}', [AgentController::class, 'destroyApiKey'])
        ->name('agents.api-keys.destroy');
    Route::get('agents/{agent}/persona', [AgentController::class, 'editPersona'])->name('agents.persona.edit');
    Route::put('agents/{agent}/persona', [AgentController::class, 'updatePersona'])->name('agents.persona.update');
    Route::delete('agents/{agent}/memories/{index}', [AgentController::class, 'destroyMemory'])
        ->whereNumber('index')
        ->name('agents.memories.destroy');
    Route::get('agents/{agent}/preview', [AgentController::class, 'preview'])->name('agents.preview');
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
    Route::delete('shared/{shared}/file', [SharedSpaceController::class, 'destroyFile'])->name('shared.file.destroy');
    Route::resource('shared', SharedSpaceController::class)->except(['destroy']);

    Route::get('knowledge', [KnowledgeController::class, 'index'])->name('knowledge.index');
    Route::get('knowledge/create', [KnowledgeController::class, 'create'])->name('knowledge.create');
    Route::post('knowledge', [KnowledgeController::class, 'store'])->name('knowledge.store');
    Route::get('knowledge/{knowledge}/edit', [KnowledgeController::class, 'edit'])->name('knowledge.edit');
    Route::put('knowledge/{knowledge}', [KnowledgeController::class, 'update'])->name('knowledge.update');
    Route::delete('knowledge/{knowledge}', [KnowledgeController::class, 'destroy'])->name('knowledge.destroy');

    Route::get('messages', [MessageController::class, 'index'])->name('messages.index');
    Route::put('messages/webhook', [MessageController::class, 'updateWebhook'])->name('messages.webhook.update');
    Route::post('messages/webhook/rotate', [MessageController::class, 'rotateWebhookSecret'])->name('messages.webhook.rotate');
    Route::get('messages/{message}', [MessageController::class, 'show'])->name('messages.show');

    Route::get('clients', [McpClientController::class, 'index'])->name('clients.index');
    Route::delete('clients/{client}', [McpClientController::class, 'destroy'])->name('clients.destroy');
});
