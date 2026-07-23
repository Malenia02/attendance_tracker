<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\SystemUserController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

Route::middleware('api.auth')->group(function (): void {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    Route::get('/system-users/options', [SystemUserController::class, 'options']);
    Route::apiResource('/system-users', SystemUserController::class)
        ->parameters(['system-users' => 'systemUser'])
        ->except(['show']);
});
