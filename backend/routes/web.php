<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\SpaController;
use Illuminate\Support\Facades\Route;

// Authentication entry point for the React SPA. This route lives in web.php so
// Laravel can issue the encrypted session cookie and enforce login throttling.
Route::post('/api/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:login');

// Lightweight readiness endpoint used by Render and manual deployment checks.
Route::get('/health/ready', HealthController::class)
    ->middleware('throttle:30,1');

// React SPA fallback. API, Sanctum, health, and framework routes are excluded
// so browser refreshes load the frontend without swallowing backend endpoints.
Route::get('/{path?}', SpaController::class)
    ->where('path', '^(?!api(?:/|$)|sanctum(?:/|$)|health(?:/|$)|up$|app(?:/|$)).*');
