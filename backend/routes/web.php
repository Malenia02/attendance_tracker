<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\SpaController;
use Illuminate\Support\Facades\Route;

Route::post('/api/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:login');

Route::get('/health/ready', HealthController::class)
    ->middleware('throttle:30,1');

Route::get('/{path?}', SpaController::class)
    ->where('path', '^(?!api(?:/|$)|sanctum(?:/|$)|health(?:/|$)|up$|app(?:/|$)).*');
