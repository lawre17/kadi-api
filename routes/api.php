<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\MatchPlayController;
use Illuminate\Support\Facades\Route;

// Public auth endpoints.
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Sanctum-protected (personal access token / Bearer).
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // Multiplayer match orchestration. Authoritative logic lives in the Node
    // engine; these endpoints relay moves and broadcast the results.
    Route::post('/matches', [MatchPlayController::class, 'store']);
    Route::post('/matches/{code}/join', [MatchPlayController::class, 'join']);
    Route::post('/matches/{matchId}/start', [MatchPlayController::class, 'start']);
    Route::post('/matches/{matchId}/move', [MatchPlayController::class, 'move']);
    // Push-to-talk voice clip: stored on the public disk + broadcast to the room.
    Route::post('/matches/{matchId}/voice', [MatchPlayController::class, 'voice']);
    // Fetch the current authoritative state — used when a client opens the game
    // (so it doesn't depend on catching the one-time initial broadcast).
    Route::get('/matches/{matchId}/state', [MatchPlayController::class, 'state']);
});
