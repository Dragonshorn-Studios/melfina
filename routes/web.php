<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredOwnerController;
use App\Http\Controllers\Console\AgentController;
use App\Http\Controllers\Console\DashboardController;
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

    Route::post('agents/{agent}/rotate', [AgentController::class, 'rotate'])->name('agents.rotate');
    Route::resource('agents', AgentController::class)->except(['destroy']);
});
