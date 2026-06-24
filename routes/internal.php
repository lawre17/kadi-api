<?php

use App\Http\Controllers\Internal\AwardController;
use App\Http\Middleware\VerifyInternalSecret;
use Illuminate\Support\Facades\Route;

// Internal endpoints called by the Node game service. NOT behind Sanctum —
// guarded by the X-Internal-Secret shared-secret header instead.
Route::middleware(VerifyInternalSecret::class)
    ->prefix('internal')
    ->group(function () {
        Route::post('/award-win', [AwardController::class, 'awardWin']);
    });
