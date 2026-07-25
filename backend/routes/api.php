<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\DtrController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\HolidayController;
use App\Http\Controllers\Api\PersonnelController;
use App\Http\Controllers\Api\QrAttendanceController;
use App\Http\Controllers\Api\SystemUserController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

Route::middleware(['api.auth', 'api.audit'])->group(function (): void {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    Route::get('/attendance', [AttendanceController::class, 'index']);
    Route::get('/attendance/options', [AttendanceController::class, 'options']);
    Route::post('/attendance/time-log', [AttendanceController::class, 'recordTime'])
        ->middleware('throttle:12,1');
    Route::patch('/attendance/{attendance}/verify', [AttendanceController::class, 'verify']);

    Route::middleware('role:Administrator,HR,Supervisor,Encoder')->group(function (): void {
        Route::get('/qr-attendance', [QrAttendanceController::class, 'index']);
        Route::post('/qr-attendance/scan', [QrAttendanceController::class, 'scan'])
            ->middleware('throttle:120,1');
    });

    Route::post('/qr-attendance/personnel/{personnel}/regenerate', [QrAttendanceController::class, 'regenerate'])
        ->middleware(['role:Administrator,HR', 'throttle:20,1']);

    Route::get('/dtr', [DtrController::class, 'index']);
    Route::post('/dtr/generate', [DtrController::class, 'generate'])
        ->middleware('throttle:5,1');
    Route::patch('/dtr/{personnel}/status', [DtrController::class, 'updateStatus'])
        ->middleware('throttle:20,1');

    Route::get('/holidays', [HolidayController::class, 'index']);
    Route::get('/holidays/options', [HolidayController::class, 'options']);

    Route::middleware('role:Administrator,HR')->group(function (): void {
        Route::apiResource('/departments', DepartmentController::class)
            ->except(['show']);

        Route::post('/holidays', [HolidayController::class, 'store']);
        Route::match(['put', 'patch'], '/holidays/{holiday}', [HolidayController::class, 'update']);
        Route::delete('/holidays/{holiday}', [HolidayController::class, 'destroy']);

        Route::get('/personnel/options', [PersonnelController::class, 'options']);
        Route::apiResource('/personnel', PersonnelController::class)
            ->except(['show']);
    });

    Route::middleware('role:Administrator')->group(function (): void {
        Route::get('/activity-logs', [ActivityLogController::class, 'index']);

        Route::get('/system-users/options', [SystemUserController::class, 'options']);
        Route::apiResource('/system-users', SystemUserController::class)
            ->parameters(['system-users' => 'systemUser'])
            ->except(['show']);
    });
});
