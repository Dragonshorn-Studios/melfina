<?php

use App\Http\Controllers\Api\KnowledgeApiController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->prefix('api/knowledge')->group(function () {
    // Graph operations
    Route::get('/graph', [KnowledgeApiController::class, 'graph'])->name('api.knowledge.graph');

    // Entry operations
    Route::get('/entries', [KnowledgeApiController::class, 'search'])->name('api.knowledge.entries.search');
    Route::get('/entries/{entry}', [KnowledgeApiController::class, 'entry'])->name('api.knowledge.entries.show');
    Route::post('/entries', [KnowledgeApiController::class, 'storeEntry'])->name('api.knowledge.entries.store');
    Route::put('/entries/{entry}', [KnowledgeApiController::class, 'updateEntry'])->name('api.knowledge.entries.update');
    Route::delete('/entries/{entry}', [KnowledgeApiController::class, 'deleteEntry'])->name('api.knowledge.entries.destroy');

    // Link operations
    Route::get('/links/{link}', [KnowledgeApiController::class, 'link'])->name('api.knowledge.links.show');
    Route::post('/links', [KnowledgeApiController::class, 'storeLink'])->name('api.knowledge.links.store');
    Route::put('/links/{link}', [KnowledgeApiController::class, 'updateLink'])->name('api.knowledge.links.update');
    Route::post('/links/{link}/confirm', [KnowledgeApiController::class, 'confirmLink'])->name('api.knowledge.links.confirm');
    Route::post('/links/{link}/permanent', [KnowledgeApiController::class, 'permanentLink'])->name('api.knowledge.links.permanent');
    Route::post('/links/{link}/retract', [KnowledgeApiController::class, 'retractLink'])->name('api.knowledge.links.retract');
    Route::delete('/links/{link}', [KnowledgeApiController::class, 'deleteLink'])->name('api.knowledge.links.destroy');

    // Metadata
    Route::get('/predicates', [KnowledgeApiController::class, 'predicates'])->name('api.knowledge.predicates');
    Route::get('/types', [KnowledgeApiController::class, 'types'])->name('api.knowledge.types');
});
